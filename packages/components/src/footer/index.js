/**
 * Footer
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import './style.scss';

const Footer = ( { simple = undefined } ) => {
	const {
		components_demo: componentsDemo = false,
		support = false,
		setup_wizard: setupWizard = false,
		reset_url: resetUrl = false,
		plugin_version: pluginVersion = { label: 'Newspack' },
		remove_starter_content: removeStarterContent = false,
		support_email: supportEmail,
	} = window.newspack_urls || {};

	const footerElements = [
		{
			label: pluginVersion.label,
			url: 'https://newspack.com/category/release-notes/',
			external: true,
		},
		{
			label: __( 'About', 'newspack-plugin' ),
			url: 'https://newspack.com/',
			external: true,
		},
		{
			label: __( 'Documentation', 'newspack-plugin' ),
			url: support,
			external: true,
		},
	];
	if ( componentsDemo ) {
		footerElements.push( {
			label: __( 'Components Demo', 'newspack-plugin' ),
			url: componentsDemo,
		} );
	}
	if ( setupWizard ) {
		footerElements.push( {
			label: __( 'Setup Wizard', 'newspack-plugin' ),
			url: setupWizard,
		} );
	}
	if ( resetUrl ) {
		footerElements.push( {
			label: __( 'Reset Newspack', 'newspack-plugin' ),
			url: resetUrl,
		} );
	}
	if ( removeStarterContent ) {
		footerElements.push( {
			label: __( 'Remove Starter Content', 'newspack-plugin' ),
			url: removeStarterContent,
		} );
	}
	if ( supportEmail ) {
		footerElements.push( {
			label: __( 'Contact Support', 'newspack-plugin' ),
			url: `mailto:${ supportEmail }`,
		} );
	}
	return (
		<div className="newspack-footer">
			{ ! simple && (
				<ul>
					{ footerElements.map( ( { url, label, external }, index ) => (
						<li key={ index }>{ external ? <ExternalLink href={ url }>{ label }</ExternalLink> : <a href={ url }>{ label }</a> }</li>
					) ) }
				</ul>
			) }
		</div>
	);
};

export default Footer;
