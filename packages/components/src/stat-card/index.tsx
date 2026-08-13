/**
 * StatCard
 */

/**
 * Internal dependencies.
 */
import Body from './body';
import Footer from './footer';
import Label from './label';
import Root from './root';
import Secondary from './secondary';
import Value from './value';

export { STAT_CARD_NULL_GLYPH } from './constants';
export type {
	StatCardBodyProps,
	StatCardFooterProps,
	StatCardHeadingLevel,
	StatCardLabelProps,
	StatCardRootProps,
	StatCardSecondaryProps,
	StatCardValue,
	StatCardValueProps,
	StatCardValueVariant,
} from './types';

// A namespace object, not this package's usual flat exports. See the README.
export const StatCard = {
	Root,
	Label,
	Body,
	Value,
	Secondary,
	Footer,
};

Object.entries( StatCard ).forEach( ( [ name, part ] ) => {
	( part as { displayName?: string } ).displayName = `StatCard.${ name }`;
} );

export default StatCard;
