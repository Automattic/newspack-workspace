/**
 * REST paths on the standalone dynamic-pricing plugin's API, shared across the
 * Pricing Rules views. The engine owns these routes — an engine route change is
 * a one-line edit here.
 */

export const RULES_API_PATH = '/wc-dynamic-pricing/v1/rules';
export const RULE_PREVIEW_API_PATH = '/wc-dynamic-pricing/v1/rules/preview';
export const IMPACT_PREVIEW_API_PATH = '/wc-dynamic-pricing/v1/impact-preview';

/**
 * The engine's route maximum, which the catalog read asks for in full.
 */
export const IMPACT_SAMPLE_LIMIT = 50;
