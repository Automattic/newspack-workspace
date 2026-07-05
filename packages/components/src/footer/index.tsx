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

/**
 * URLs localized by newspack-plugin for the wizard footer. All keys are
 * optional: the payload varies with site configuration (and is absent
 * entirely outside Newspack admin pages).
 */
type NewspackUrls = {
	site?: string;
	components_demo?: string;
	support?: string;
	setup_wizard?: string;
	reset_url?: string;
	plugin_version?: { label: string };
	remove_starter_content?: string;
	support_email?: string;
};

declare global {
	interface Window {
		newspack_urls?: NewspackUrls;
	}
}

type FooterElement = {
	label: string;
	url: string | false;
	external?: boolean;
};

const Footer = ( { simple = undefined }: { simple?: boolean } ) => {
	const urls: NewspackUrls = window.newspack_urls || {};
	const {
		components_demo: componentsDemo = false,
		support = false,
		setup_wizard: setupWizard = false,
		reset_url: resetUrl = false,
		plugin_version: pluginVersion = { label: 'Newspack' },
		remove_starter_content: removeStarterContent = false,
		support_email: supportEmail,
	} = urls;

	const footerElements: FooterElement[] = [
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
						<li key={ index }>
							{ /* A false url (e.g. missing support URL) renders the label without an href, as before. */ }
							{ external ? <ExternalLink href={ url as string }>{ label }</ExternalLink> : <a href={ url || undefined }>{ label }</a> }
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
};

export default Footer;
