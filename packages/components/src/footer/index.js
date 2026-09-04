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

	const resetDialog = useConfirmDialog( {
		title: __( 'Reset Newspack?', 'newspack-plugin' ),
		message: __(
			'This deletes every Newspack setting on this site and returns you to the setup wizard. Your posts, pages and users are not affected. This cannot be undone.',
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
	const renderLink = ( { url, label, external, confirm } ) => {
		if ( external ) {
			return <ExternalLink href={ url }>{ label }</ExternalLink>;
		}
		if ( ! confirm ) {
			return <a href={ url }>{ label }</a>;
		}
		return (
			<a
				href={ url }
				onClick={ event => {
					event.preventDefault();
					confirm( () => {
						window.location.href = url;
					} );
				} }
			>
				{ label }
			</a>
		);
	};

	return (
		<div className="newspack-footer">
			{ ! simple && (
				<ul>
					{ footerElements.map( ( element, index ) => (
						<li key={ index }>{ renderLink( element ) }</li>
					) ) }
				</ul>
			) }
			{ resetDialog.confirmDialog }
			{ starterContentDialog.confirmDialog }
		</div>
	);
};

export default Footer;
