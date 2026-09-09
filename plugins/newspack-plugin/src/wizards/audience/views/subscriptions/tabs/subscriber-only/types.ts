/**
 * Types for the Subscriber-only products tab.
 */

/**
 * Internal dependencies.
 */
import type { BaseRule } from '../../types';

/** A subscriber-only product restriction. */
export type Restriction = BaseRule;

/** The tab's settings. */
export interface RestrictionSettings {
	/** Keep restricted products out of product lists for readers who can't buy them. */
	hide_from_product_lists: boolean;
}
