/**
 * StatCard
 */

/**
 * Internal dependencies.
 */
import Body from './body';
import Delta from './delta';
import Footer from './footer';
import Label from './label';
import Root from './root';
import Secondary from './secondary';
import Value from './value';

export { STAT_CARD_NULL_GLYPH } from './constants';
export type {
	StatCardBodyProps,
	StatCardDeltaDirection,
	StatCardDeltaProps,
	StatCardDeltaTone,
	StatCardFooterProps,
	StatCardHeadingLevel,
	StatCardLabelProps,
	StatCardLabels,
	StatCardRootProps,
	StatCardSecondaryProps,
	StatCardValue,
	StatCardValueProps,
	StatCardValueVariant,
} from './types';

// Compound components here export one namespace object, as Drawer does.
export const StatCard = {
	Root,
	Label,
	Body,
	Value,
	Delta,
	Secondary,
	Footer,
};

Object.entries( StatCard ).forEach( ( [ name, part ] ) => {
	( part as { displayName?: string } ).displayName = `StatCard.${ name }`;
} );

export default StatCard;
