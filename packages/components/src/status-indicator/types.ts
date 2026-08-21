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
type StatusIcon = ComponentProps< typeof Icon >[ 'icon' ];

export interface StatusIndicatorProps extends Omit< ComponentProps< 'div' >, 'children' > {
	/** The status glyph, from `@wordpress/icons`. */
	icon: StatusIcon;
	/** The status label. */
	children?: ReactNode;
}
