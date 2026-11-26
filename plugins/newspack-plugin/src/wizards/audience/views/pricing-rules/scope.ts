const SCOPE_TARGETING = { product_ids: 'products', category: 'category', all_products: 'all' } as const;

export type ScopeTargeting = ( typeof SCOPE_TARGETING )[ keyof typeof SCOPE_TARGETING ];

/**
 * How the Audience lists present a Pricing Rules scope type, or null for a type
 * they have no names to list for (such as all subscriptions), which the list
 * labels with the engine's own `scope_label` instead.
 *
 * @param scopeType The rule's `scope_type`.
 */
export function scopeTargeting( scopeType: string ): ScopeTargeting | null {
	return SCOPE_TARGETING[ scopeType as keyof typeof SCOPE_TARGETING ] ?? null;
}
