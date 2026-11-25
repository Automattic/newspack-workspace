import { useSyncExternalStore } from 'react';
import { MOBILE_MENU_QUERY } from '@/lib/site';

export type Theme = 'light' | 'dark';

/**
 * The one record of the theme is the `dark` class on `html`. A stored choice lives under this
 * key only while it differs from the system setting. The head script (`THEME_SCRIPT`) reads the
 * same key before paint and follows system and cross-tab changes. Below the mobile menu
 * breakpoint it ignores the key and follows the system setting.
 */
export const THEME_KEY = 'theme';

export function currentTheme(): Theme {
	return document.documentElement.classList.contains( 'dark' ) ? 'dark' : 'light';
}

export function systemTheme(): Theme {
	return window.matchMedia( '(prefers-color-scheme: dark)' ).matches ? 'dark' : 'light';
}

export function storedTheme(): Theme | null {
	try {
		const stored = localStorage.getItem( THEME_KEY );
		return stored === 'dark' || stored === 'light' ? stored : null;
	} catch {
		return null;
	}
}

export function applyTheme( next: Theme, { persist = true }: { persist?: boolean } = {} ) {
	const root = document.documentElement;
	root.classList.toggle( 'dark', next === 'dark' );
	root.style.colorScheme = next;
	if ( persist ) {
		try {
			if ( next === systemTheme() ) {
				localStorage.removeItem( THEME_KEY );
			} else {
				localStorage.setItem( THEME_KEY, next );
			}
		} catch {}
	}
}

function subscribe( listener: () => void ) {
	const observer = new MutationObserver( listener );
	observer.observe( document.documentElement, { attributes: true, attributeFilter: [ 'class' ] } );
	return () => observer.disconnect();
}

export function useTheme(): Theme {
	return useSyncExternalStore( subscribe, currentTheme, () => 'light' );
}

function subscribeMobileMenu( listener: () => void ) {
	const query = window.matchMedia( MOBILE_MENU_QUERY );
	query.addEventListener( 'change', listener );
	return () => query.removeEventListener( 'change', listener );
}

export function useMobileMenu(): boolean {
	return useSyncExternalStore( subscribeMobileMenu, () => window.matchMedia( MOBILE_MENU_QUERY ).matches, () => false );
}

export function prefersReducedMotion() {
	return window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
}
