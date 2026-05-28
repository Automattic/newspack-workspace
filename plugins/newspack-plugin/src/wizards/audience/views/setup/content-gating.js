/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import CountdownBanner from './countdown-banner';
import ContentGifting from './content-gifting';
import { ActionCard, Notice, withWizardScreen } from '../../../../../packages/components/src';
import WizardsTab from '../../../wizards-tab';

export default withWizardScreen( ( { wizardApiFetch } ) => {
	const [ error, setError ] = useState( false );
	const [ config, setConfig ] = useState( {} );

	useEffect( () => {
		fetchConfig();
	}, [] );

	const fetchConfig = () => {
		setError( false );
		wizardApiFetch( {
			path: '/newspack/v1/wizard/newspack-audience/content-gating',
		} )
			.then( data => {
				setConfig( data );
			} )
			.catch( setError );
	};

	const updateConfig = newConfig => {
		setError( false );
		wizardApiFetch( {
			path: '/newspack/v1/wizard/newspack-audience/content-gating',
			method: 'POST',
			quiet: true,
			data: newConfig,
		} )
			.then( data => {
				setConfig( data );
			} )
			.catch( setError );
	};

	const getContentGateDescription = () => {
		let message = __( 'Configure the gate rendered on content with restricted access.', 'newspack-plugin' );
		if ( 'publish' === config?.gate_status ) {
			message += ' ' + __( 'The gate is currently published.', 'newspack-plugin' );
		} else if ( 'draft' === config?.gate_status || 'trash' === config?.gate_status ) {
			message += ' ' + __( 'The gate is currently a draft.', 'newspack-plugin' );
		}
		return message;
	};

	return (
		<WizardsTab
			title={ __( 'Content Gating', 'newspack-plugin' ) }
			description={
				<>
					{ __( 'WooCommerce Memberships integration to improve the reader experience with content gating. ', 'newspack-plugin' ) }
					<ExternalLink href={ 'https://help.newspack.com/engagement/audience-management-system/content-gating/' }>
						{ __( 'Learn more', 'newspack-plugin' ) }
					</ExternalLink>
				</>
			}
		>
			{ error && <Notice noticeText={ error?.message || __( 'Something went wrong.', 'newspack-plugin' ) } isError /> }
			<ActionCard
				title={ __( 'Content Gate', 'newspack-plugin' ) }
				titleLink={ config.edit_gate_url }
				href={ config.edit_gate_url }
				description={ getContentGateDescription() }
				actionText={ __( 'Configure', 'newspack-plugin' ) }
			/>
			<ContentGifting config={ config } setConfig={ setConfig } updateConfig={ updateConfig } />
			<CountdownBanner config={ config } setConfig={ setConfig } updateConfig={ updateConfig } />
			{ config?.plans && 1 < config.plans.length && (
				<ActionCard
					title={ __( 'Require membership in all plans', 'newspack-plugin' ) }
					description={ __(
						'When enabled, readers must belong to all membership plans that apply to a restricted content item before they are granted access. Otherwise, they will be able to unlock access to that item with membership in any single plan that applies to it.',
						'newspack-plugin'
					) }
					toggleOnChange={ value => updateConfig( { require_all_plans: value } ) }
					toggleChecked={ config.require_all_plans }
					togglePosition="trailing"
				/>
			) }
			{ config.has_memberships && (
				<ActionCard
					title={ __( 'Display memberships on the subscriptions tab', 'newspack-plugin' ) }
					description={ __(
						"Display memberships that don't have active subscriptions on the My Account Subscriptions tab, so readers can see information like expiration dates.",
						'newspack-plugin'
					) }
					toggleOnChange={ value => updateConfig( { show_on_subscription_tab: value } ) }
					toggleChecked={ config.show_on_subscription_tab }
					togglePosition="trailing"
				/>
			) }
		</WizardsTab>
	);
} );
