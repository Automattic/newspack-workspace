const { createElement, createPortal, Fragment, Suspense, useLayoutEffect, useState } = window.wp.element;

const settingsTabs = () => Object.entries( window.newspackSettings || {} ).filter( ( [ , tab ] ) => tab && tab.label );

const tabPath = ( slug, tab ) => tab?.path ?? `/${ slug }`;

function currentSettingsSection() {
	const p = new URLSearchParams( window.location.search ).get( 'p' ) || '';
	const match = p.match( /^\/settings\/([^/]+)/ );
	return match ? match[ 1 ] : null;
}

// Settings still routes its sections through its own hash, while boot routes
// them as `?p=/settings/<slug>`. Rewrite the hash in place before Settings'
// router reads it. Assigning location.hash would fire popstate, making boot
// navigate a second time and cut its view transition short; doing it in the
// route's beforeLoad would also run on boot's hover preloads.
function syncSettingsHash( section ) {
	const path = tabPath( section, window.newspackSettings?.[ section ] );
	// Boot drops the hash when it navigates, so an empty hash is never "on" a
	// section: Settings' own router may still be showing the previous one.
	const hash = window.location.hash.slice( 1 );
	const onSection = '/' === path ? '/' === hash : hash === path || hash.startsWith( `${ path }/` );
	if ( ! onSection ) {
		const url = new URL( window.location.href );
		url.hash = path;
		window.history.replaceState( window.history.state, '', url );
	}
}

// A bare `/settings` link, e.g. an old `page=newspack-settings#/seo` redirect:
// move it onto the matching section route. A hash that names no section is
// left to the Settings screen, which forwards retired ones such as `#/emails`.
function redirectBareSettings() {
	const hash = `/${ window.location.hash.replace( /^#\/?/, '' ) }`;
	const tabs = settingsTabs();
	const found =
		'/' === hash
			? tabs[ 0 ]
			: tabs.find( ( [ slug, tab ] ) => {
					const path = tabPath( slug, tab );
					return '/' !== path && ( hash === path || hash.startsWith( `${ path }/` ) );
			  } );
	if ( ! found ) {
		return;
	}
	const url = new URL( window.location.href );
	url.searchParams.set( 'p', `/settings/${ found[ 0 ] }` );
	window.history.replaceState( window.history.state, '', url );
	window.dispatchEvent( new PopStateEvent( 'popstate', { state: window.history.state } ) );
}

// Boot's own back link sits outside the sliding sidebar panels, so it can't
// slide with them, and a drill-down panel's back arrow and title are separate
// elements. Render one back button (arrow and title) into every panel's title
// row instead: the root panel's goes to the WordPress dashboard, a drill-down
// panel's presses boot's own (hidden) back button so boot still navigates.
const OWN = 'newspack-admin-app__header';

function SidebarHeader() {
	const [ rows, setRows ] = useState( [] );
	useLayoutEffect( () => {
		const content = document.querySelector( '.boot-sidebar__content' );
		if ( ! content ) {
			return;
		}
		const find = () => {
			const found = [ ...content.querySelectorAll( '.boot-navigation-screen__title-icon' ) ];
			setRows( current => ( current.length === found.length && current.every( ( row, i ) => row === found[ i ] ) ? current : found ) );
		};
		find();
		const observer = new window.MutationObserver( find );
		observer.observe( content, { childList: true, subtree: true } );
		return () => observer.disconnect();
	}, [] );

	const backIcons = window.newspackAdminApp?.backIcons;
	if ( ! backIcons ) {
		return null;
	}
	const { Button } = window.wp.components;
	const icon = window.wp.i18n.isRTL() ? backIcons.chevronRight : backIcons.chevronLeft;

	return createElement(
		Fragment,
		null,
		rows.map( ( row, index ) => {
			const bootBack = row.querySelector( `.components-button:not(.${ OWN })` );
			const title = row.querySelector( '.boot-navigation-screen__title' )?.textContent;
			const props = bootBack
				? {
						onClick: () => bootBack.click(),
						children: title || bootBack.getAttribute( 'aria-label' ),
						// Keeps the visible name, and says the button goes back a level.
						...( title && {
							'aria-label': window.wp.i18n.sprintf(
								/* translators: %s: Name of the current sidebar section. */
								window.wp.i18n.__( '%s: go back', 'newspack-plugin' ),
								title
							),
						} ),
				  }
				: {
						href: window.wp.data.select( 'wordpress/boot' ).getDashboardLink(),
						children: 'Newspack',
						// Keeps the visible name, and says the link leaves Newspack.
						'aria-label': window.wp.i18n.sprintf(
							/* translators: %s: Newspack */
							window.wp.i18n.__( '%s: go to the WordPress Dashboard', 'newspack-plugin' ),
							'Newspack'
						),
				  };
			return createPortal(
				createElement( 'h1', { className: `${ OWN }-title` }, createElement( Button, { className: OWN, icon, ...props } ) ),
				row,
				`header-${ index }`
			);
		} )
	);
}

// The Newspack footer links live at the bottom of boot's sidebar instead of
// under each screen.
function SidebarFooter() {
	const [ target, setTarget ] = useState( null );
	useLayoutEffect( () => setTarget( document.querySelector( '.boot-sidebar__footer' ) ), [] );
	const Footer = window.newspackAdminApp?.Footer;
	return target && Footer ? createPortal( createElement( Footer, { hideAbout: true } ), target ) : null;
}

export function wizardStage( slug ) {
	return function Stage() {
		const entry = window.newspackAdminApp?.components?.[ slug ];
		const isSettings = 'newspack-settings' === slug;
		const section = isSettings ? currentSettingsSection() : null;
		if ( section ) {
			syncSettingsHash( section );
		}
		useLayoutEffect( () => {
			if ( section ) {
				// Tells an already-mounted Settings router about the rewritten hash;
				// it ignores the event when its location is unchanged.
				window.dispatchEvent( new window.HashChangeEvent( 'hashchange' ) );
			}
			if ( isSettings && ! section ) {
				redirectBareSettings();
			}
		} );
		const sidebar = createElement( Fragment, null, createElement( SidebarHeader ), createElement( SidebarFooter ) );
		if ( ! entry ) {
			return sidebar;
		}
		// Boot themes its surfaces from the WP admin colour scheme; reseed the
		// screen with the Newspack primary so accents match our other screens.
		const screen = createElement(
			window.wp.theme.ThemeProvider,
			{ color: { primary: window.newspackAdminApp.primaryColor } },
			createElement(
				'div',
				{ className: `newspack-admin-app__stage newspack-wizard ${ slug }`, id: slug },
				createElement( Suspense, { fallback: null }, createElement( entry.component ) )
			)
		);
		return createElement( Fragment, null, screen, sidebar );
	};
}
