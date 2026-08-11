/**
 * REST paths on the standalone dynamic-pricing plugin's API, shared across the
 * Pricing Rules views. The engine owns these routes — an engine route change is
 * a one-line edit here.
 */

export const RULES_API_PATH = '/wc-dynamic-pricing/v1/rules';
export const RULE_PREVIEW_API_PATH = '/wc-dynamic-pricing/v1/rules/preview';
export const IMPACT_PREVIEW_API_PATH = '/wc-dynamic-pricing/v1/impact-preview';

/**
 * The engine's route maximum. The catalogue view requests it; both views compare
 * it against sample_count to decide whether a result is a sample, because the
 * engine flags a preview limited even when it merely skipped a product it could
 * not price.
 */
export const IMPACT_SAMPLE_LIMIT = 50;
