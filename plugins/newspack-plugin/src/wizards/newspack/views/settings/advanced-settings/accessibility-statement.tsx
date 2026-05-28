/**
 * Newspack > Settings > Advanced Settings > Accessibility Statement
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { ExternalLink } from '@wordpress/components';
import { Button, Card, Notice, SectionHeader } from '../../../../../../packages/components/src';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';

interface AccessibilityStatementProps {
	isFetching: boolean;
}

type PageData = {
	editUrl: string;
	status: string;
	pageUrl: string;
};

export default function AccessibilityStatement( { isFetching }: AccessibilityStatementProps ) {
	const { wizardApiFetch } = useWizardApiFetch( 'newspack-settings/advanced-settings/accessibility-statement' );
	const [ localIsFetching, setLocalIsFetching ] = useState( false );
	const [ localPageData, setLocalPageData ] = useState< PageData | null >( null );

	// Function to fetch fresh data
	const fetchFreshData = () => {
		setLocalIsFetching( true );
		wizardApiFetch(
			{
				path: `/newspack/v1/wizard/newspack-settings/accessibility-statement`,
				method: 'GET',
			},
			{
				onSuccess: response => {
					if ( response && response.editUrl && response.status && response.status !== 'trash' ) {
						setLocalPageData( response );
					} else {
						setLocalPageData( null );
					}
					setLocalIsFetching( false );
				},
				onError: () => {
					setLocalPageData( null );
					setLocalIsFetching( false );
				},
			}
		);
	};

	// Only fetch on mount
	useEffect( () => {
		if ( ! localPageData ) {
			fetchFreshData();
		}
	}, [] );

	const createPage = () => {
		setLocalIsFetching( true );
		wizardApiFetch(
			{
				path: '/newspack/v1/wizard/newspack-settings/accessibility-statement',
				method: 'POST',
				data: {
					force_create: 'true', // Force creation of a new page
				},
			},
			{
				onSuccess: response => {
					if ( response && response.editUrl && response.status ) {
						setLocalPageData( response );
					} else {
						setLocalPageData( null );
					}
					fetchFreshData();
				},
				onError: () => {
					setLocalPageData( null );
					setLocalIsFetching( false );
				},
			}
		);
	};

	const getStatusMessage = () => {
		if ( ! localPageData ) {
			return {
				type: 'warning',
				message: __(
					'Your accessibility statement page has been moved to trash or deleted. Click "Create Page" to create a new one.',
					'newspack-plugin'
				),
			};
		}

		switch ( localPageData.status ) {
			case 'publish':
				return {
					type: 'success',
					message: __( 'Your accessibility statement page is published.', 'newspack-plugin' ),
				};
			case 'draft':
			case 'pending':
				return {
					type: 'warning',
					message: __(
						'Your accessibility statement page is not yet published. Please review and make edits before publishing.',
						'newspack-plugin'
					),
				};
			case 'trash':
			default:
				return {
					type: 'warning',
					message: __(
						'Your accessibility statement page has been moved to trash. Click "Create Page" to create a new one.',
						'newspack-plugin'
					),
				};
		}
	};

	const getButtonText = () => {
		if ( ! localPageData ) {
			return __( 'Create Page', 'newspack-plugin' );
		}

		switch ( localPageData.status ) {
			case 'publish':
				return __( 'Edit Page', 'newspack-plugin' );
			case 'draft':
			case 'pending':
				return __( 'Edit and Publish Page', 'newspack-plugin' );
			case 'trash':
			default:
				return __( 'Create Page', 'newspack-plugin' );
		}
	};

	const statusInfo = getStatusMessage();

	return (
		<>
			<Card noBorder headerActions>
				<SectionHeader
					title={ __( 'Accessibility Statement Page', 'newspack-plugin' ) }
					noMargin
					description={ __(
						'Edit and publish an accessibility statement page. Once published, a link to this page will display in the footer of your site.',
						'newspack-plugin'
					) }
				/>
				{ localPageData && localPageData.status !== 'trash' ? (
					<Button variant="secondary" isSmall href={ localPageData.editUrl }>
						{ getButtonText() }
					</Button>
				) : (
					<Button variant="secondary" isSmall onClick={ createPage } disabled={ isFetching || localIsFetching }>
						{ getButtonText() }
					</Button>
				) }
			</Card>

			<Notice isSuccess={ statusInfo.type === 'success' } isWarning={ statusInfo.type === 'warning' } noticeText={ statusInfo.message } />

			<p>
				{ __(
					'An accessibility statement helps your readers understand how your site supports accessibility standards and what to do if they encounter accessibility issues. ',
					'newspack-plugin'
				) }
				<ExternalLink href="https://www.w3.org/WAI/planning/statements/">
					{ __( 'What makes a good accessibility statement.', 'newspack-plugin' ) }{ ' ' }
				</ExternalLink>
			</p>

			<p>
				{ __( 'The page you create here will include a boilerplate accessibility statement. ', 'newspack-plugin' ) }
				<strong>{ __( 'Please review and make edits to ensure it meets the requirements before publishing. ', 'newspack-plugin' ) }</strong>
				{ __( 'You can also use the W3C Accessibility Statement Generator to create a custom statement. ', 'newspack-plugin' ) }
				<ExternalLink href="https://www.w3.org/WAI/planning/statements/generator/#create">
					{ __( 'Try out the Accessibility Statement Generator.', 'newspack-plugin' ) }{ ' ' }
				</ExternalLink>
			</p>

			<p>
				<ExternalLink href="https://help.newspack.com/revenue/reader-revenue/how-to-add-an-accessibility-statement/">
					{ __( 'Learn more about this feature in our documentation.', 'newspack-plugin' ) }{ ' ' }
				</ExternalLink>
			</p>
		</>
	);
}
