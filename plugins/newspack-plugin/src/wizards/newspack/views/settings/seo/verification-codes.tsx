/**
 * Components for managing SEO verification codes.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { ExternalLink } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { TextControl } from '../../../../../../packages/components/src';

function VerificationCodes( { data, setData }: { data: SeoData[ 'verification' ]; setData: ( v: SeoData[ 'verification' ] ) => void } ) {
	return (
		<>
			<TextControl
				label="Google"
				onChange={ ( google: string ) => setData( { ...data, google } ) }
				value={ data.google }
				withMargin={ false }
				help={
					<Fragment>
						{ __( 'Get your verification code in', 'newspack-plugin' ) + ' ' }
						<ExternalLink
							href={ `https://search.google.com/search-console/ownership?resource_id=${ encodeURIComponent(
								window.location.origin
							) }` }
						>
							{ __( 'Google Search Console', 'newspack-plugin' ) }
						</ExternalLink>
					</Fragment>
				}
			/>
			<TextControl
				label="Bing"
				onChange={ ( bing: string ) => setData( { ...data, bing } ) }
				value={ data.bing }
				withMargin={ false }
				help={
					<Fragment>
						{ `${ __( 'Get your verification code in', 'newspack-plugin' ) } ` }
						<ExternalLink href="https://www.bing.com/toolbox/webmaster/#/Dashboard/">
							{ __( 'Bing Webmaster Tools', 'newspack-plugin' ) }
						</ExternalLink>
					</Fragment>
				}
			/>
		</>
	);
}

export default VerificationCodes;
