/**
 * Newspack > Settings > SEO
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { Notice } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies.
 */
import Accounts from './accounts';
import { ACCOUNTS } from './constants';
import WizardsTab from '../../../../wizards-tab';
import VerificationCodes from './verification-codes';
import { Divider, Grid, Handoff, SectionHeader, useUnsavedChangesDialog } from '../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import useFieldsValidation from '../../../../hooks/use-fields-validation';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';

const PATH = '/newspack/v1/wizard/newspack-settings/seo';

const EMPTY_DATA: SeoData = {
	search_engines_discouraged: false,
	urls: {
		bluesky: '',
		facebook: '',
		instagram: '',
		linkedin: '',
		pinterest: '',
		threads: '',
		tiktok: '',
		twitter: '',
		youtube: '',
	},
	verification: {
		bing: '',
		google: '',
	},
};

function Seo() {
	const { wizardApiFetch, isFetching, errorMessage, resetError } = useWizardApiFetch( 'newspack-settings/seo' );
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	const [ data, setData ] = useState< SeoData >( EMPTY_DATA );
	const [ savedData, setSavedData ] = useState< SeoData >( EMPTY_DATA );

	const codesValidation = useFieldsValidation< SeoData[ 'verification' ] >(
		[
			[
				'google',
				'isId',
				{
					message: __(
						'Google verification codes use only letters, numbers, hyphens, and underscores. Copy just the code from Search Console, not the full meta tag.',
						'newspack-plugin'
					),
				},
			],
			[
				'bing',
				/** JS version of [WPSEO PHP regex](https://github.com/Yoast/wordpress-seo/blob/trunk/inc/options/class-wpseo-option.php#L313) */
				v =>
					/^[A-Fa-f0-9_-]*$/.test( v )
						? ''
						: __(
								'Bing verification codes use only the letters A-F, numbers, hyphens, and underscores. Copy just the code from Bing Webmaster Tools, not the full meta tag.',
								'newspack-plugin'
						  ),
			],
		],
		data.verification
	);

	const accountsValidation = useFieldsValidation< SeoData[ 'urls' ] >(
		ACCOUNTS.map(
			( [ key, label, placeholder, validation ] ) => [
				key,
				validation ?? 'isUrl',
				validation
					? {}
					: {
							message: sprintf(
								/* translators: %1$s: label, %2$s: placeholder */
								__( 'Invalid URL for "%1$s", correct format is "%2$s"', 'newspack-plugin' ),
								label,
								placeholder
							),
					  },
			],
			[]
		),
		data.urls
	);

	useEffect( get, [] );

	function get() {
		wizardApiFetch(
			{
				path: PATH,
			},
			{
				onSuccess: res => {
					setData( res );
					setSavedData( res );
				},
			}
		);
	}

	function post() {
		// `speak` empties the live region before each write, so two notices mounting in one
		// commit would report only the last. Announcing here also repeats an unchanged message.
		const validationErrors = [ codesValidation.validateInputs(), accountsValidation.validateInputs() ].filter( Boolean );
		if ( validationErrors.length ) {
			speak( validationErrors.join( ' ' ), 'assertive' );
			return;
		}
		resetError();
		wizardApiFetch(
			{
				path: PATH,
				method: 'POST',
				updateCacheMethods: [ 'GET' ],
				data,
			},
			{
				onSuccess: res => {
					setData( res );
					setSavedData( res );
				},
			}
		);
	}

	// The header keeps whichever callback it was handed, so publishing `post` directly
	// would pin the state of the render that published it.
	const submit = useRef( post );
	submit.current = post;

	const isDirty = JSON.stringify( data ) !== JSON.stringify( savedData );

	useEffect( () => {
		setHeaderData( {
			actions: [
				{
					type: 'primary',
					label: __( 'Save', 'newspack-plugin' ),
					action: () => submit.current(),
					disabled: isFetching || ! isDirty,
				},
			],
		} );
	}, [ isFetching, isDirty, setHeaderData ] );

	const { confirmDialog: navBlockDialog } = useUnsavedChangesDialog( {
		when: isDirty && ! isFetching,
	} );

	return (
		<WizardsTab className={ isFetching ? 'is-fetching' : '' }>
			{ navBlockDialog }
			{ errorMessage && (
				<Notice status="error" isDismissible={ false } politeness="polite">
					{ errorMessage }
				</Notice>
			) }
			{ data.search_engines_discouraged && (
				<Notice status="warning" isDismissible={ false } spokenMessage="">
					<Stack direction="row" align="center" justify="space-between" gap="lg" wrap="wrap">
						<span>
							{ __(
								'This site is set to discourage search engines from indexing it. Until that is turned off, these settings have no effect.',
								'newspack-plugin'
							) }
						</span>
						<Handoff
							url="options-reading.php"
							isLink
							bannerText={ __(
								'Clear "Discourage search engines from indexing this site" under Search engine visibility.',
								'newspack-plugin'
							) }
						>
							{ __( 'Reading Settings', 'newspack-plugin' ) }
						</Handoff>
					</Stack>
				</Notice>
			) }
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					noMargin
					heading={ 2 }
					title={ __( 'Webmaster Tools', 'newspack-plugin' ) }
					description={ __( 'Add verification meta tags to your site', 'newspack-plugin' ) }
				/>
				<Stack direction="column" gap="xl">
					{ codesValidation.errorMessage && (
						<Notice status="error" isDismissible={ false } spokenMessage="">
							{ codesValidation.errorMessage }
						</Notice>
					) }
					<VerificationCodes setData={ verification => setData( { ...data, verification } ) } data={ data.verification } />
				</Stack>
			</Grid>
			<Divider alignment="full-width" variant="tertiary" />
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					noMargin
					heading={ 2 }
					title={ __( 'Social Accounts', 'newspack-plugin' ) }
					description={ __( 'Let search engines know which social profiles are associated to your site', 'newspack-plugin' ) }
				/>
				<Stack direction="column" gap="xl">
					{ accountsValidation.errorMessage && (
						<Notice status="error" isDismissible={ false } spokenMessage="">
							{ accountsValidation.errorMessage }
						</Notice>
					) }
					<Accounts setData={ urls => setData( { ...data, urls } ) } data={ data.urls } />
				</Stack>
			</Grid>
		</WizardsTab>
	);
}

export default Seo;
