/**
 * WordPress dependencies
 */
import type { Badge } from '@wordpress/ui';

// @wordpress/ui does not export BadgeProps, so the union is derived from the component.
export type BadgeIntent = NonNullable< React.ComponentProps< typeof Badge >[ 'intent' ] >;
