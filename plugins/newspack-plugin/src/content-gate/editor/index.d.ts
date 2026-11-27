declare module '@wordpress/block-editor';

// @wordpress/edit-post ships no types of its own; everything imported from it is `any`.
declare module '@wordpress/edit-post';

/**
 * Types.
 */
type BlockSettings = {
	attributes: Record< string, unknown >;
	name: string;
};
// Alias the module's own type rather than restating it, so the two cannot drift.
type AccessRuleOption = import( '../access-rule-options' ).AccessRuleOption;
// Single source of truth for each composite value shape: one-time purchase lives
// with its control, range bounds with the verdicts that read them.
type EditorOneTimePurchaseRuleValue = import( '../components/one-time-purchase-rule-control' ).OneTimePurchaseValue;
type EditorRangeRuleValue = import( '../utils/access-rule-value' ).RangeValue;
type AccessRuleConfig = {
	name: string;
	description: string;
	default: string | Array< string | number > | EditorOneTimePurchaseRuleValue | EditorRangeRuleValue;
	is_boolean?: boolean;
	// Whether the value is `{ min, max }` bounds on a number, rendered as a min/max control.
	is_range?: boolean;
	placeholder?: string;
	options?: AccessRuleOption[];
	has_options: boolean;
	empty_grants_access?: boolean;
	requires_value?: boolean;
};
type ActiveRule = {
	slug: string;
	value: string | Array< string | number > | EditorOneTimePurchaseRuleValue | EditorRangeRuleValue | null;
};
type RegistrationRule = {
	active: boolean;
	require_verification?: boolean;
};
type CustomAccessRule = {
	active: boolean;
	access_rules: ActiveRule[][];
};
type BlockVisibilityRules = {
	registration?: RegistrationRule;
	custom_access?: CustomAccessRule;
};
type GateOption = {
	id: number;
	title: string;
};
type BlockVisibilityAttributes = {
	newspackAccessControlRules: BlockVisibilityRules;
	newspackAccessControlVisibility: string;
	newspackAccessControlMode: string;
	newspackAccessControlGateIds: number[];
	[ key: string ]: unknown;
};
type BlockEditProps = {
	name: string;
	attributes: BlockVisibilityAttributes;
	setAttributes: ( attrs: Partial< BlockVisibilityAttributes > ) => void;
	[ key: string ]: unknown;
};

// A gate as localized to the post editor's Access Control panel (post-settings).
type PostEditorContentGate = {
	id: number;
	title: string;
	edit_url?: string;
	content_rules: import( './gate-matching' ).ContentRule[];
	content_rules_match: 'all' | 'any';
};

// A membership plan as localized to the Memberships gate editor panel.
type MembershipPlan = {
	id: number;
	name: string;
	gate_id: number | false;
	gate_status: string | false;
	plan_url?: string | null;
};

// Localized by the Memberships gate editor as `newspack_memberships_gate`.
type MembershipsGateConfig = {
	edit_gate_url: string;
	plans: MembershipPlan[];
	gate_plans: Record< string, string >;
	edit_plan_gate_url: string;
};

// Localized by the gate-layout editor bundle (editor.tsx and preview.tsx).
declare const newspack_content_gate: {
	has_campaigns: boolean;
	preview?: {
		preview_post: string;
		query_param: string;
		preview_query_keys: Record< string, string >;
	} | null;
};

interface Window {
	newspackBlockVisibility: {
		target_blocks: string[];
		available_access_rules: Record< string, AccessRuleConfig >;
		available_gates: GateOption[];
	};
	// Localized by the gate editor for the per-post Access Control Settings panel.
	newspackContentGates?: {
		gates: PostEditorContentGate[];
		taxonomyMap: Record< string, string >;
		canEditGates: boolean;
	};
	// Localized by the Memberships gate editor panel.
	newspack_memberships_gate?: MembershipsGateConfig;
}
