/* global newspack_plugin_info, jQuery */

/**
 * Internal dependencies.
 */
import './plugins-screen.scss';

const getCreateButton =
	targetEl =>
	( text, hrefOrCallback, isPrimary = false ) => {
		const buttonEl = document.createElement( 'a' );
		if ( typeof hrefOrCallback === 'string' ) {
			buttonEl.setAttribute( 'href', hrefOrCallback );
		} else if ( typeof hrefOrCallback === 'function' ) {
			buttonEl.onclick = hrefOrCallback;
		} else {
			return;
		}
		buttonEl.setAttribute( 'target', '_blank' );
		buttonEl.classList.add( `button-${ isPrimary ? 'primary' : 'secondary' }` );
		buttonEl.innerText = text;
		targetEl.appendChild( buttonEl );
		return buttonEl;
	};

/**
 * Extra zazz for the WP Admin Plugins page.
 *
 * @see Admin_Plugins_Screen::enqueue_scripts_and_styles().
 */
( function ( $ ) {
	// Display a modal when adding a new plugin.
	if ( newspack_plugin_info.screen === 'plugin-install.php' && newspack_plugin_info.plugin_review_link ) {
		const modalEl = document.createElement( 'div' );
		const modalContentEl = document.createElement( 'div' );
		const modalHeadingEl = document.createElement( 'h1' );
		const modalPEl = document.createElement( 'p' );
		const modalButtonsWrapperEl = document.createElement( 'div' );
		const modalCloseEl = document.createElement( 'button' );
		const createButton = getCreateButton( modalButtonsWrapperEl );

		const closeModal = () => {
			modalEl.classList.add( 'newspack-plugin-info-modal--hidden' );
		};

		modalEl.classList.add( 'newspack-plugin-info-modal' );
		modalHeadingEl.innerText = wp.i18n.__( 'Plugin review required', 'newspack-plugin' );
		modalPEl.innerText = wp.i18n.__(
			'Please submit a plugin for review by the Newspack Team before installing it on your website. If you plan to install an approved plugin, feel free to close this message.',
			'newspack-plugin'
		);
		modalCloseEl.innerHTML =
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false"><path d="m13.06 12 6.47-6.47-1.06-1.06L12 10.94 5.53 4.47 4.47 5.53 10.94 12l-6.47 6.47 1.06 1.06L12 13.06l6.47 6.47 1.06-1.06L13.06 12Z"></path></svg>';
		modalCloseEl.onclick = closeModal;

		createButton( wp.i18n.__( 'Plugin Review Form', 'newspack-plugin' ), newspack_plugin_info.plugin_review_link, true );
		createButton( wp.i18n.__( 'Approved Plugins List', 'newspack-plugin' ), newspack_plugin_info.approved_plugins_list_link );
		createButton( wp.i18n.__( 'Close this message', 'newspack-plugin' ), closeModal );

		modalEl.appendChild( modalContentEl );
		modalContentEl.appendChild( modalHeadingEl );
		modalContentEl.appendChild( modalPEl );
		modalContentEl.appendChild( modalCloseEl );
		modalContentEl.appendChild( modalButtonsWrapperEl );
		document.body.appendChild( modalEl );
	}

	// Add a 'newspack_plugin' class to managed plugins.
	if ( newspack_plugin_info.screen === 'plugins.php' ) {
		newspack_plugin_info.plugins.forEach( function ( plugin_slug ) {
			const $row = $( 'tr[data-slug="' + plugin_slug + '"]' );
			if ( $row.length ) {
				$row.addClass( 'newspack-plugin' );
			}

			if ( ! newspack_plugin_info.installed_plugins.includes( plugin_slug ) ) {
				$row.addClass( 'uninstalled' );
			}
		} );
	}
} )( jQuery );
