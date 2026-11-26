import { createElement, forwardRef } from '@wordpress/element';

export const Link = forwardRef( ( { to, ...props }, ref ) => createElement( 'a', { href: to, ref, ...props } ) );
