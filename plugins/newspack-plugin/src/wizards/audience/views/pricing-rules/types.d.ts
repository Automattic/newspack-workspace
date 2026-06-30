/**
 * Types for the Pricing Rules DataViews page.
 * Ambient (no imports/exports) — globally available, matching the audience views convention.
 */

interface PricingRuleSimpleParams {
	calc_type: string;
	value: number;
	cycles_limit: number;
	label: string;
}

interface PricingRuleStep {
	at: number;
	calc_type: string;
	value: number;
	label: string;
}

interface PricingRuleRow {
	id: number;
	deal_key: string;
	title: string;
	status: string;
	status_label: string;
	strategy_id: string;
	strategy_label: string;
	scope_type: string;
	scope_label: string;
	scope_ids: number[];
	priority: number;
	compose_mode: 'min' | 'priority_exclusive' | string;
	application: 'locked' | 'current' | string;
	publicize: boolean;
	active_from: number | null;
	active_until: number | null;
	active_state: 'active' | 'scheduled' | 'ended';
	published_at: number | null;
	intent: string;
	intent_note: string;
	cycle_anchor: 'subscription_start' | 'rule_application' | string;
	is_stepped: boolean;
	has_conditions: boolean;
	conditions: { [ id: string ]: boolean | number | number[] | null };
	simple: PricingRuleSimpleParams | null;
	steps: PricingRuleStep[] | null;
	edit_link: string;
}

interface PricingRulesCurrency {
	code: string;
	symbol: string;
	decimals: number;
}

interface PricingRulesVocabItem {
	id: string;
	label: string;
	requires_value?: boolean;
}

interface PricingRuleConditionVocab {
	id: string;
	field_type: 'boolean' | 'datetime' | 'select' | string;
	label: string;
	help: string;
	multiple?: boolean;
	options?: { value: number; label: string }[];
}

interface PricingRulesResponse {
	rules: PricingRuleRow[];
	currency: PricingRulesCurrency;
	strategies: PricingRulesVocabItem[];
	scopes: PricingRulesVocabItem[];
	calc_types: { value: string; label: string }[];
	conditions: PricingRuleConditionVocab[];
}

interface Window {
	newspackAudiencePricingRules?: {
		rules_rest_path: string;
		engine_active: boolean;
	};
}

interface ImpactSegment {
	from_cycle: number;
	amount: number;
	rule_id: string;
	rule_title: string;
	rule_edit_link: string;
	changed: boolean;
}

interface CatalogImpactRow {
	product_id: number;
	name: string;
	edit_link: string;
	regular: number;
	adjusted: number;
	is_subscription: boolean;
	changed: boolean;
	segments: ImpactSegment[];
}

interface SegmentImpactGroup {
	segment_id: number;
	segment_label: string;
	sample: CatalogImpactRow[];
}

interface CatalogImpactResponse {
	supported: boolean;
	total_matching: number;
	count_limited: boolean;
	preview_limited: boolean;
	sample_count: number;
	currency: PricingRulesCurrency;
	sample: CatalogImpactRow[];
	segment_groups?: SegmentImpactGroup[];
}

interface RuleAudienceData {
	supported: boolean;
	total: number;
	caught: number;
	protected: number;
	count_limited: boolean;
	application: 'current' | 'locked' | string;
}

interface RuleAudienceResponse {
	audience?: RuleAudienceData;
}

interface RulePreviewResponse extends CatalogImpactResponse {
	audience?: RuleAudienceData;
}
