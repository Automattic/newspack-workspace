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
import type { StatusName, StatusTone } from './statuses';

// @wordpress/components does not export the Icon prop type, so the union is
// derived from the component to track the library instead of a copy kept here.
// `icon` is optional there, and indexing an optional property widens the union
// with `undefined`, so NonNullable is what keeps the glyph required here.
type StatusIcon = NonNullable< ComponentProps< typeof Icon >[ 'icon' ] >;

interface StatusIndicatorBaseProps extends Omit< ComponentProps< 'div' >, 'children' > {
	/** The status label. @wordpress/primitives forces `aria-hidden` on the glyph, so this is the whole accessible name. */
	children: ReactNode;
}

interface NamedStatusProps {
	status: StatusName;
	/** Overrides the status's own tone, for a screen where the state means less than its glyph suggests. */
	tone?: StatusTone;
	icon?: never;
}

/** `icon` is the escape hatch for fields that classify rather than track a lifecycle; a status drawn this way is never tinted. */
interface GlyphProps {
	status?: never;
	tone?: never;
	icon: StatusIcon;
}

export type StatusIndicatorProps = StatusIndicatorBaseProps & ( NamedStatusProps | GlyphProps );
