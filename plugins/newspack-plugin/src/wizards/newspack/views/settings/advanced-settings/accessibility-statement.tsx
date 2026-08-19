/**
 * Newspack > Settings > Advanced Settings > Accessibility Statement
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { ExternalLink } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { Button, Card, Notice, SectionHeader } from '../../../../../../packages/components/src';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';

interface AccessibilityStatementProps {
	isFetching: boolean;
}

type PageData = {
	editUrl: string | null;
	status: string;
	pageUrl: string | false;
	title: string;
};

/**
 * Where no page exists, the server says whether the site has never created one
 * ( 'none' ) or created one that has since gone ( 'missing' ).
 */
type PageResponse = PageData | { status: 'none' | 'missing' };

const isPage = ( response: PageResponse ): response is PageData =>
	Boolean( response?.status ) && response.status !== 'none' && response.status !== 'missing';

export default function AccessibilityStatement( { isFetching }: AccessibilityStatementProps ) {
	const { wizardApiFetch, errorMessage, resetError } = useWizardApiFetch( 'newspack-settings/advanced-settings/accessibility-statement' );
	const [ localIsFetching, setLocalIsFetching ] = useState( false );
	const [ localPageData, setLocalPageData ] = useState< PageData | null >( null );
	const [ isPageMissing, setIsPageMissing ] = useState( false );

	const applyResponse = ( response: PageResponse, announce = false ) => {
		// A retry that succeeds must clear the notice the failure left behind.
		resetError();
		if ( isPage( response ) ) {
			setLocalPageData( response );
			setIsPageMissing( false );
		} else {
			setLocalPageData( null );
			setIsPageMissing( response?.status === 'missing' );
		}
		setLocalIsFetching( false );
		if ( announce ) {
			speak( __( 'Accessibility statement page created.', 'newspack-plugin' ) );
		}
	};

	const handleError = ( error: { message?: string } ) => {
		setLocalPageData( null );
		setIsPageMissing( false );
		setLocalIsFetching( false );
		// The notice is a plain div, so nothing else announces the failure.
		speak( error?.message ?? __( 'Something went wrong.', 'newspack-plugin' ), 'assertive' );
	};

	useEffect( () => {
		setLocalIsFetching( true );
		wizardApiFetch< PageResponse >(
			{
				path: '/newspack/v1/wizard/newspack-settings/accessibility-statement',
				method: 'GET',
			},
			{ onSuccess: applyResponse, onError: handleError }
		).catch( () => {} );
	}, [] );

	const createPage = () => {
		setLocalIsFetching( true );
		wizardApiFetch< PageResponse >(
			{
				path: '/newspack/v1/wizard/newspack-settings/accessibility-statement',
				method: 'POST',
				// The POST answers with the page, so it can refresh the cached
				// GET rather than leaving a stale "no page" behind it.
				updateCacheMethods: [ 'GET' ],
			},
			{
				onSuccess: response => applyResponse( response, true ),
				onError: handleError,
			}
		).catch( () => {} );
	};

	const getStatusMessage = () => {
		if ( errorMessage ) {
			return { type: 'error', message: errorMessage };
		}

		if ( ! localPageData ) {
			return {
				type: 'warning',
				message: isPageMissing
					? __(
							'Your accessibility statement page has been moved to trash or deleted. Click "Create Page" to create a new one.',
							'newspack-plugin'
					  )
					: __( 'You do not have an accessibility statement page yet. Click "Create Page" to add one.', 'newspack-plugin' ),
			};
		}

		switch ( localPageData.status ) {
			case 'publish':
				return {
					type: 'success',
					message: __( 'Your accessibility statement page is published.', 'newspack-plugin' ),
				};
			default:
				return {
					type: 'warning',
					message: __(
						'Your accessibility statement page is not yet published. Please review and make edits before publishing.',
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
			default:
				return __( 'Edit and Publish Page', 'newspack-plugin' );
		}
	};

	// A page the user cannot edit has no edit URL, and Create would only hand
	// back the page they already cannot open.
	const renderAction = () => {
		if ( localPageData ) {
			return localPageData.editUrl ? (
				<Button variant="secondary" isSmall href={ localPageData.editUrl }>
					{ getButtonText() }
				</Button>
			) : null;
		}

		return (
			<Button variant="secondary" isSmall onClick={ createPage } disabled={ isFetching || localIsFetching }>
				{ getButtonText() }
			</Button>
		);
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
				{ renderAction() }
			</Card>

			<Notice
				isError={ statusInfo.type === 'error' }
				isSuccess={ statusInfo.type === 'success' }
				isWarning={ statusInfo.type === 'warning' }
				noticeText={ statusInfo.message }
			/>

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
