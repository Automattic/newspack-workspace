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
import useConfirmDialog from '../hooks/use-confirm-dialog';
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
	confirm?: ( callback: () => void ) => void;
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

	const resetDialog = useConfirmDialog( {
		title: __( 'Reset Newspack?', 'newspack-plugin' ),
		message: __(
			'This deletes the Newspack settings on this site and returns you to the setup wizard. Your posts, pages and users are not affected. This cannot be undone.',
			'newspack-plugin'
		),
		confirmButtonText: __( 'Reset Newspack', 'newspack-plugin' ),
		isDestructive: true,
	} );

	const starterContentDialog = useConfirmDialog( {
		title: __( 'Remove Starter Content?', 'newspack-plugin' ),
		message: __( 'This deletes the posts, pages and categories created as starter content. This cannot be undone.', 'newspack-plugin' ),
		confirmButtonText: __( 'Remove Starter Content', 'newspack-plugin' ),
		isDestructive: true,
	} );

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
			confirm: resetDialog.requestConfirm,
		} );
	}
	if ( removeStarterContent ) {
		footerElements.push( {
			label: __( 'Remove Starter Content', 'newspack-plugin' ),
			url: removeStarterContent,
			confirm: starterContentDialog.requestConfirm,
		} );
	}
	if ( supportEmail ) {
		footerElements.push( {
			label: __( 'Contact Support', 'newspack-plugin' ),
			url: `mailto:${ supportEmail }`,
		} );
	}
	const renderItem = ( { url, label, external, confirm }: FooterElement ) => {
		if ( external ) {
			return <ExternalLink href={ url as string }>{ label }</ExternalLink>;
		}
		if ( ! confirm ) {
			// A false url (e.g. missing support URL) renders the label without an href, as before.
			return <a href={ url || undefined }>{ label }</a>;
		}
		// onClick only sees primary clicks, so an href here would let a middle-click
		// or "Open link in new tab" reach the URL unguarded.
		return (
			<button
				type="button"
				onClick={ () =>
					confirm( () => {
						window.location.href = url as string;
					} )
				}
			>
				{ label }
			</button>
		);
	};

	return (
		<div className="newspack-footer">
			{ ! simple && (
				<ul>
					{ footerElements.map( ( element, index ) => (
						<li key={ index }>{ renderItem( element ) }</li>
					) ) }
				</ul>
			) }
			{ resetDialog.confirmDialog }
			{ starterContentDialog.confirmDialog }
		</div>
	);
};

export default Footer;
