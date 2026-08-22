/**
 * WordPress dependencies.
 */
import { Icon } from '@wordpress/components';

/**
 * External dependencies.
 */
import type { ComponentProps, ReactNode } from 'react';

// @wordpress/components does not export the Icon prop type, so the union is
// derived from the component to track the library instead of a copy kept here.
// `icon` is optional there, and indexing an optional property widens the union
// with `undefined`, so NonNullable is what keeps the glyph required here.
type StatusIcon = NonNullable< ComponentProps< typeof Icon >[ 'icon' ] >;

export interface StatusIndicatorProps extends Omit< ComponentProps< 'div' >, 'children' > {
	/** The status glyph, from `@wordpress/icons`. */
	icon: StatusIcon;
	/** The status label. @wordpress/primitives forces `aria-hidden` on the glyph, so this is the whole accessible name. */
	children: ReactNode;
}
