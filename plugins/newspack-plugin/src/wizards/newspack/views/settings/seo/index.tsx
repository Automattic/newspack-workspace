/**
 * Newspack > Settings > Emails
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { Notice } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import Accounts from './accounts';
import { ACCOUNTS } from './constants';
import WizardsTab from '../../../../wizards-tab';
import VerificationCodes from './verification-codes';
import WizardSection from '../../../../wizards-section';
import { Button } from '../../../../../../packages/components/src';
import WizardsActionCard from '../../../../wizards-action-card';
import useFieldsValidation from '../../../../hooks/use-fields-validation';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';

const PATH = '/newspack/v1/wizard/newspack-settings/seo';

function Seo() {
	const { wizardApiFetch, isFetching } = useWizardApiFetch( 'newspack-settings/seo' );

	const [ data, setData ] = useState< SeoData >( {
		under_construction: false,
		urls: {
			facebook: '',
			twitter: '',
			instagram: '',
			youtube: '',
			linkedin: '',
			pinterest: '',
		},
		verification: {
			bing: '',
			google: '',
		},
	} );

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
				onSuccess: res => setData( res ),
			}
		);
	}

	function post() {
		const isVerificationCodesValid = codesValidation.isInputsValid();
		const isAccountsValid = accountsValidation.isInputsValid();
		if ( ! isVerificationCodesValid || ! isAccountsValid ) {
			return;
		}
		wizardApiFetch(
			{
				path: PATH,
				method: 'POST',
				updateCacheMethods: [ 'GET' ],
				data,
			},
			{
				onSuccess: res => setData( res ),
			}
		);
	}
	return (
		<WizardsTab title={ __( 'SEO', 'newspack-plugin' ) } className={ isFetching ? 'is-fetching' : '' }>
			<WizardSection
				title={ __( 'Webmaster Tools', 'newspack-plugin' ) }
				description={ __( 'Add verification meta tags to your site', 'newspack-plugin' ) }
			>
				{ codesValidation.errorMessage && (
					<Notice status="error" isDismissible={ false }>
						{ codesValidation.errorMessage }
					</Notice>
				) }
				<VerificationCodes setData={ verification => setData( { ...data, verification } ) } data={ data.verification } />
			</WizardSection>
			<WizardSection
				title={ __( 'Social Accounts', 'newspack-plugin' ) }
				description={ __( 'Let search engines know which social profiles are associated to your site', 'newspack-plugin' ) }
			>
				{ accountsValidation.errorMessage && (
					<Notice status="error" isDismissible={ false }>
						{ accountsValidation.errorMessage }
					</Notice>
				) }
				<Accounts setData={ urls => setData( { ...data, urls } ) } data={ data.urls } />
			</WizardSection>
			<WizardSection>
				<WizardsActionCard
					isMedium
					disabled={ isFetching }
					toggleChecked={ data.under_construction }
					title={ __( 'Under construction', 'newspack' ) }
					toggleOnChange={ ( under_construction: boolean ) => setData( { ...data, under_construction } ) }
					description={ __( 'Discourage search engines from indexing this site.', 'newspack' ) }
				/>
			</WizardSection>
			<div className="newspack-buttons-card">
				<Button isPrimary onClick={ post } disabled={ isFetching }>
					{ isFetching ? __( 'Loading…', 'newspack-plugin' ) : __( 'Save Settings', 'newspack-plugin' ) }
				</Button>
			</div>
		</WizardsTab>
	);
}

export default Seo;
