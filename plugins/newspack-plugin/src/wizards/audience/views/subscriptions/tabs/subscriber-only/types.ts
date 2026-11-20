/**
 * Types for the Subscriber-only products tab.
 */

/**
 * Internal dependencies.
 */
import type { BaseRule } from '../../types';

/**
 * What a restriction blocks for readers outside its audience: the purchase
 * only, or the purchase and the product itself ("Purchase and visibility").
 */
export type RestrictionType = 'purchase' | 'view';

/** A subscriber-only product restriction. */
export interface Restriction extends BaseRule {
	restriction_type: RestrictionType;
}

/** What the page of a hidden product does for a reader who can't see it. */
export type ProductPageMode = 'hide_content' | 'hide' | 'redirect';

/** The tab's settings. */
export interface RestrictionSettings {
	/** Keep "Purchase and visibility" products out of product lists for readers who can't see them. */
	hide_from_product_lists: boolean;
	product_page: ProductPageMode;
	/** The page `redirect` sends readers to. */
	redirect_page_id: number;
}

export const DEFAULT_SETTINGS: RestrictionSettings = {
	hide_from_product_lists: false,
	product_page: 'hide_content',
	redirect_page_id: 0,
};
