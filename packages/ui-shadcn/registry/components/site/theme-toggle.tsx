"use client"

import type { MouseEvent } from 'react';
import { Icon, styles } from '@wordpress/icons';
import { Button } from '@/components/ui/button';
import { DARK_MODE } from '@/lib/site';
import { applyTheme, useTheme, type Theme } from '@/lib/theme';
import { cn } from '@/lib/utils';

export type ThemeToggleHandler = ( next: Theme, event: MouseEvent< HTMLButtonElement > ) => void;

/**
 * The light and dark switch, from the mobile menu breakpoint up. Renders nothing when
 * `DARK_MODE` is off.
 *
 * - `onToggle`: runs in place of `applyTheme( next )`, so the site can apply the theme its own
 *   way, such as inside a view transition. `event.detail` is 0 when the press came from the
 *   keyboard.
 */
export function ThemeToggle( { className, onToggle }: { className?: string; onToggle?: ThemeToggleHandler } ) {
	const dark = useTheme() === 'dark';
	if ( ! DARK_MODE ) {
		return null;
	}
	return (
		<Button
			type="button"
			variant="ghost"
			size="icon-sm"
			className={ cn( 'max-md:hidden', className ) }
			onClick={ ( event ) => {
				const next = dark ? 'light' : 'dark';
				if ( onToggle ) {
					onToggle( next, event );
				} else {
					applyTheme( next );
				}
			} }
		>
			<Icon icon={ styles } />
			<span className="sr-only">{ dark ? 'Switch to light mode' : 'Switch to dark mode' }</span>
		</Button>
	);
}
