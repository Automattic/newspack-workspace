/**
 * Newspack > Settings > Emails > Emails section
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useState, Fragment } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { Notice, utils } from '../../../../../../packages/components/src';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import WizardsActionCard from '../../../../wizards-action-card';
import WizardsPluginCard from '../../../../wizards-plugin-card';

const Emails = () => {
	const emailSections = window.newspackSettings.emails.sections;
	const postType = emailSections.emails.postType;

	const [ pluginsReady, setPluginsReady ] = useState( emailSections.emails.dependencies.newspackNewsletters );

	const { wizardApiFetch, isFetching, errorMessage, resetError } = useWizardApiFetch( 'newspack-settings/emails' );

	const [ emails, setEmails ] = useState( Object.values( emailSections.emails.all ) );

	const updateStatus = ( postId: number, status: string ) => {
		wizardApiFetch(
			{
				path: `/wp/v2/${ postType }/${ postId }`,
				method: 'POST',
				data: { status },
			},
			{
				onStart() {
					resetError();
				},
				onSuccess() {
					setEmails(
						emails.map( email => {
							if ( email.post_id === postId ) {
								return { ...email, status };
							}
							return email;
						} )
					);
				},
			}
		);
	};

	const resetEmail = ( postId: number ) => {
		wizardApiFetch(
			{
				path: `/newspack/v1/wizard/newspack-audience-donations/emails/${ postId }`,
				method: 'DELETE',
			},
			{
				onSuccess( result ) {
					window.newspackSettings.emails.sections.emails.all = result;
					setEmails( Object.values( result ) );
				},
			}
		);
	};

	if ( false === pluginsReady ) {
		return (
			<Fragment>
				<Notice isError>
					{ __(
						'Newspack uses Newspack Newsletters to handle editing email-type content. Please activate this plugin to proceed.',
						'newspack-plugin'
					) }
					<br />
					{ __( 'Until this feature is configured, default receipts will be used.', 'newspack-plugin' ) }
				</Notice>
				<WizardsPluginCard
					slug="newspack-newsletters"
					title={ __( 'Newspack Newsletters', 'newspack-plugin' ) }
					description={ __( 'Newspack Newsletters is the plugin that powers Newspack email receipts.', 'newspack-plugin' ) }
					onStatusChange={ ( statuses: Record< string, boolean > ) => {
						if ( ! statuses.isLoading ) {
							setPluginsReady( statuses.isSetup );
						}
					} }
				/>
			</Fragment>
		);
	}

	return (
		<Fragment>
			{ emails.map( email => {
				const isActive = email.status === 'publish';
				const isAudience = email.category === 'reader-activation';
				let notification = __( 'This email is not active.', 'newspack-plugin' );
				if ( email.type === 'receipt' ) {
					notification = __( 'This email is not active. The default receipt will be used.', 'newspack-plugin' );
				}
				if ( email.type === 'welcome' ) {
					notification = __( 'This email is not active. The receipt template will be used if active.', 'newspack-plugin' );
				}
				return (
					<WizardsActionCard
						isSmall
						key={ email.post_id }
						disabled={ isFetching }
						title={ email.label }
						titleLink={ email.edit_link }
						href={ email.edit_link }
						description={ email.description }
						actionText={ __( 'Edit', 'newspack-plugin' ) }
						secondaryActionText={ __( 'Reset', 'newspack-plugin' ) }
						onSecondaryActionClick={ () => {
							if ( utils.confirmAction( __( 'Are you sure you want to reset the contents of this email?', 'newspack-plugin' ) ) ) {
								resetEmail( email.post_id );
							}
						} }
						secondaryDestructive={ true }
						{ ...( isAudience
							? {}
							: {
									toggleChecked: isActive,
									toggleOnChange: value => updateStatus( email.post_id, value ? 'publish' : 'draft' ),
							  } ) }
						{ ...( isActive
							? {}
							: {
									notification,
									notificationLevel: 'info',
							  } ) }
					>
						{ errorMessage && <Notice noticeText={ errorMessage || __( 'Something went wrong.', 'newspack-plugin' ) } isError /> }
					</WizardsActionCard>
				);
			} ) }
		</Fragment>
	);
};

export default Emails;
