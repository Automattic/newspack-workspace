/**
 * WordPress dependencies
 */
import type { Badge } from '@wordpress/ui';

/**
 * Heading level for a component that renders a heading whose position in the
 * document outline is the caller's to decide. Matches Core's `headingLevel` on
 * `Page.Header`, `ToolsPanel` and `ColorPalette`. Level 1 is excluded: a card
 * or panel is never a page's only heading.
 */
export type HeadingLevel = 2 | 3 | 4 | 5 | 6;

// @wordpress/ui does not export BadgeProps, so the union is derived from the component.
export type BadgeIntent = NonNullable< React.ComponentProps< typeof Badge >[ 'intent' ] >;

/**
 * A badge rendered beside a card title. The label is optional because cards drop
 * a badge they cannot label rather than painting an empty pill, so a caller may
 * build one straight from data that can be absent.
 */
export type CardBadge = {
	label?: string | null;
	intent?: BadgeIntent;
};
