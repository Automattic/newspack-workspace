/**
 * WordPress dependencies.
 */
import { Icon } from '@wordpress/components';

/**
 * External dependencies.
 */
import type { ComponentProps, ReactNode } from 'react';

/**
 * Internal dependencies.
 */
import type { StatusName } from './statuses';

// @wordpress/components does not export the Icon prop type, so the union is
// derived from the component to track the library instead of a copy kept here.
// `icon` is optional there, and indexing an optional property widens the union
// with `undefined`, so NonNullable is what keeps the glyph required here.
export type StatusIcon = NonNullable< ComponentProps< typeof Icon >[ 'icon' ] >;

interface StatusIndicatorBaseProps extends Omit< ComponentProps< 'div' >, 'children' > {
	/** The status label. @wordpress/primitives forces `aria-hidden` on the glyph, so this is the whole accessible name. */
	children: ReactNode;
}

/**
 * Name a status and the component draws it, or pass a glyph for the states the
 * vocabulary does not cover. Availability and Visibility are classifications
 * rather than lifecycle states, so they take `icon`.
 */
export type StatusIndicatorProps = StatusIndicatorBaseProps & ( { status: StatusName; icon?: never } | { status?: never; icon: StatusIcon } );
