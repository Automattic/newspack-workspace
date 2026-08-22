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
type StatusIcon = NonNullable< ComponentProps< typeof Icon >[ 'icon' ] >;

interface StatusIndicatorBaseProps extends Omit< ComponentProps< 'div' >, 'children' > {
	/** The status label. @wordpress/primitives forces `aria-hidden` on the glyph, so this is the whole accessible name. */
	children: ReactNode;
}

/** `icon` is the escape hatch for fields that classify rather than track a lifecycle. */
export type StatusIndicatorProps = StatusIndicatorBaseProps & ( { status: StatusName; icon?: never } | { status?: never; icon: StatusIcon } );
