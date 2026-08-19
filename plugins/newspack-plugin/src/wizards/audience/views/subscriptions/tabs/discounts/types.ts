/**
 * Types for the Subscriber discounts tab.
 */

/**
 * Internal dependencies.
 */
import type { BaseRule } from '../../types';

/** A subscriber discount rule. */
export interface DiscountRule extends BaseRule {
	discount_type: 'fixed' | 'percent';
	amount: number;
}

/** Global settings governing how discounts combine. */
export interface DiscountSettings {
	apply_on_sale: boolean;
	apply_at_checkout: boolean;
}

/** The store's currency format. */
export interface DiscountCurrency {
	code: string;
	symbol: string;
	decimals: number;
	decimal_separator: string;
	thousand_separator: string;
	position: string;
}

/** Everything the tab renders from; every write returns this shape. */
export interface DiscountsPayload {
	rules: DiscountRule[];
	settings: DiscountSettings;
	currency: DiscountCurrency;
}
