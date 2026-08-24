/**
 * Pure helpers behind the Subscriber discounts tab: currency formatting, the
 * discount arithmetic the price preview shows, and the summary labels the list
 * renders.
 *
 * The arithmetic mirrors `Newspack\Subscriber_Discounts::discounted_price()`.
 * The preview is what a publisher tunes a fixed amount against, so the two must
 * agree. They can differ by one minor unit at an exact half-cent boundary, where
 * PHP's `round()` pre-rounds to 15 significant digits and this does not.
 */

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies.
 */
import type { DiscountCurrency, DiscountRule } from './types';

export const DEFAULT_CURRENCY: DiscountCurrency = {
	code: 'USD',
	symbol: '$',
	decimals: 2,
	decimal_separator: '.',
	thousand_separator: ',',
	position: 'left',
};

/**
 * Format an amount the way the storefront renders prices.
 *
 * @param amount   Amount to format.
 * @param currency Store currency.
 */
export function formatCurrency( amount: number, currency: DiscountCurrency = DEFAULT_CURRENCY ): string {
	const { decimals, decimal_separator: decimalSeparator, thousand_separator: thousandSeparator, symbol, position } = currency;

	const fixed = Math.abs( amount ).toFixed( decimals );
	const [ whole, fraction ] = fixed.split( '.' );
	// Replacement via a callback: a separator containing `$` would otherwise be
	// interpreted as a capture-group reference.
	const grouped = whole.replace( /\B(?=(\d{3})+(?!\d))/g, () => thousandSeparator );
	const number = ( amount < 0 ? '-' : '' ) + grouped + ( fraction ? decimalSeparator + fraction : '' );

	switch ( position ) {
		case 'right':
			return `${ number }${ symbol }`;
		case 'left_space':
			return `${ symbol } ${ number }`;
		case 'right_space':
			return `${ number } ${ symbol }`;
		default:
			return `${ symbol }${ number }`;
	}
}

/**
 * The price a subscriber pays under a single rule, or null when the rule cannot
 * lower it.
 *
 * @param basePrice Price before the discount.
 * @param rule      Rule providing the type and amount.
 * @param decimals  Currency decimals.
 */
export function subscriberPrice(
	basePrice: number,
	rule: Pick< DiscountRule, 'discount_type' | 'amount' >,
	decimals = DEFAULT_CURRENCY.decimals
): number | null {
	if ( ! ( basePrice > 0 ) || ! ( rule.amount > 0 ) ) {
		return null;
	}
	const raw = 'percent' === rule.discount_type ? basePrice * ( 1 - Math.min( rule.amount, 100 ) / 100 ) : basePrice - rule.amount;
	const factor = Math.pow( 10, decimals );
	// Round half down, matching how the server rounds a subscriber price.
	const rounded = Math.max( 0, -Math.round( -raw * factor ) / factor );
	return rounded < basePrice ? rounded : null;
}

/**
 * The discount as shown in the list's Discount column.
 *
 * @param rule     Rule.
 * @param currency Store currency.
 */
export function discountLabel( rule: Pick< DiscountRule, 'discount_type' | 'amount' >, currency: DiscountCurrency = DEFAULT_CURRENCY ): string {
	return 'percent' === rule.discount_type
		? sprintf(
				/* translators: %s: a percentage, e.g. "15". */
				__( '%s%%', 'newspack-plugin' ),
				String( rule.amount )
		  )
		: formatCurrency( rule.amount, currency );
}

/**
 * The rule's audience, as shown in the list's Subscription column.
 *
 * Names the subscriptions where the options list can resolve them; ids it
 * cannot resolve (a deleted product, or a site with more subscriptions than
 * one options page) fall back to a count so the cell never goes blank.
 *
 * @param ids     The rule's subscription product ids.
 * @param options Known subscription products.
 */
export function subscriptionsLabel( ids: number[], options: { id: number; name: string }[] ): string {
	const names = ids.map( id => options.find( option => option.id === id )?.name ).filter( ( name ): name is string => !! name );
	if ( ! names.length ) {
		return sprintf(
			/* translators: %d: number of subscriptions whose subscribers get the discount. */
			_n( '%d subscription', '%d subscriptions', ids.length, 'newspack-plugin' ),
			ids.length
		);
	}
	const listed = names.map( decodeEntities ).join( ', ' );
	const unresolved = ids.length - names.length;
	if ( unresolved > 0 ) {
		return sprintf(
			/* translators: %1$s: subscription names, %2$d: number of further subscriptions the rule also covers. */
			_n( '%1$s + %2$d more', '%1$s + %2$d more', unresolved, 'newspack-plugin' ),
			listed,
			unresolved
		);
	}
	return listed;
}

type TargetingFieldsOnly = Pick< DiscountRule, 'targeting' | 'product_ids' | 'category_ids' | 'excluded_product_ids' >;

/**
 * What a rule covers, before any exclusions.
 *
 * @param rule Rule.
 */
export function targetingBaseLabel( rule: TargetingFieldsOnly ): string {
	if ( 'all' === rule.targeting ) {
		return __( 'All products', 'newspack-plugin' );
	}
	if ( 'category' === rule.targeting ) {
		const count = rule.category_ids.length;
		return sprintf(
			/* translators: %d: number of product categories. */
			_n( '%d category', '%d categories', count, 'newspack-plugin' ),
			count
		);
	}
	const count = rule.product_ids.length;
	return sprintf(
		/* translators: %d: number of products. */
		_n( '%d product', '%d products', count, 'newspack-plugin' ),
		count
	);
}

/**
 * The rule's exclusions, or an empty string when there are none.
 *
 * Exclusions only ever apply to category and all-products rules; a hand-picked
 * list is its own exclusion.
 *
 * @param rule Rule.
 */
export function excludedLabel( rule: TargetingFieldsOnly ): string {
	const excluded = 'products' === rule.targeting ? 0 : rule.excluded_product_ids.length;
	if ( ! excluded ) {
		return '';
	}
	return sprintf(
		/* translators: %d: number of excluded products. */
		_n( '%d excluded', '%d excluded', excluded, 'newspack-plugin' ),
		excluded
	);
}

/**
 * What a rule covers, as shown in the list's "Applies to" column.
 *
 * @param rule Rule.
 */
export function targetingLabel( rule: TargetingFieldsOnly ): string {
	const base = targetingBaseLabel( rule );
	const excluded = excludedLabel( rule );
	if ( ! excluded ) {
		return base;
	}
	return sprintf(
		/* translators: %1$s: what the rule covers, %2$s: the exclusions, e.g. "2 excluded". */
		__( '%1$s · %2$s', 'newspack-plugin' ),
		base,
		excluded
	);
}

/**
 * Whether a rule can be saved.
 *
 * Mirrors the server's validation so the editor can disable Save rather than
 * round-trip to a 400.
 *
 * @param rule Draft rule.
 */
export function isValidRule( rule: Partial< DiscountRule > ): boolean {
	if ( ! rule.subscription_product_ids?.length ) {
		return false;
	}
	const amount = Number( rule.amount );
	if ( ! ( amount > 0 ) || ( 'percent' === rule.discount_type && amount > 100 ) ) {
		return false;
	}
	if ( 'products' === rule.targeting && ! rule.product_ids?.length ) {
		return false;
	}
	if ( 'category' === rule.targeting && ! rule.category_ids?.length ) {
		return false;
	}
	return true;
}
