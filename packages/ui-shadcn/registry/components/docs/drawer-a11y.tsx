"use client"

import { useEffect } from 'react';
import { useSidebar } from 'fumadocs-ui/layouts/notebook/slots/sidebar';

export function DrawerA11y() {
	const { open, setOpen, mode } = useSidebar();
	const active = open && mode === 'drawer';

	useEffect( () => {
		if ( open && mode === 'full' ) {
			setOpen( false );
		}
	}, [ open, mode, setOpen ] );

	useEffect( () => {
		if ( ! active ) {
			return;
		}
		const drawer = document.getElementById( 'nd-sidebar-mobile' );
		if ( ! drawer ) {
			return;
		}
		const overlay = drawer.previousElementSibling?.hasAttribute( 'data-state' )
			? drawer.previousElementSibling
			: null;
		const behind: Element[] = [];
		for ( let node: Element | null = drawer; node && node !== document.body; node = node.parentElement ) {
			for ( const sibling of Array.from( node.parentElement?.children ?? [] ) ) {
				if ( sibling !== node && sibling !== overlay && ! sibling.hasAttribute( 'inert' ) && ! [ 'SCRIPT', 'STYLE', 'TEMPLATE' ].includes( sibling.tagName ) ) {
					behind.push( sibling );
				}
			}
		}
		const trigger = document.querySelector< HTMLElement >(
			'[aria-controls="nd-sidebar-mobile"]'
		);
		behind.forEach( ( el ) => el.setAttribute( 'inert', '' ) );
		drawer
			.querySelectorAll( 'a[data-active="true"]' )
			.forEach( ( el ) => el.setAttribute( 'aria-current', 'page' ) );

		let frame = 0;
		let tries = 0;
		const focusClose = () => {
			drawer.querySelector< HTMLElement >( 'button' )?.focus();
			if ( ! drawer.contains( document.activeElement ) && tries++ < 10 ) {
				frame = requestAnimationFrame( focusClose );
			}
		};
		focusClose();

		const onKeyDown = ( event: KeyboardEvent ) => {
			if (
				event.key === 'Escape' &&
				! event.defaultPrevented &&
				! (
					event.target instanceof Element &&
					event.target.closest( '[role="dialog"]:not(#nd-sidebar-mobile)' )
				)
			) {
				setOpen( false );
			}
		};
		document.addEventListener( 'keydown', onKeyDown );

		return () => {
			cancelAnimationFrame( frame );
			document.removeEventListener( 'keydown', onKeyDown );
			behind.forEach( ( el ) => el.removeAttribute( 'inert' ) );
			if ( trigger?.isConnected ) {
				trigger.focus();
			}
		};
	}, [ active, setOpen ] );

	return null;
}
