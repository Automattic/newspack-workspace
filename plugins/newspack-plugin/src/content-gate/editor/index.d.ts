declare module '@wordpress/block-editor';

/**
 * Types.
 */
type BlockSettings = {
	attributes: Record< string, unknown >;
	name: string;
};
type DynamicOptionItem = {
	id: string | number;
	title: {
		raw: string;
	};
};
type AccessRuleOption = {
	value: string | number;
	label: string;
};
type AccessRuleConfig = {
	name: string;
	description: string;
	default: string | Array< string | number >;
	is_boolean?: boolean;
	placeholder?: string;
	options?: AccessRuleOption[];
};
type ActiveRule = {
	slug: string;
	value: string | Array< string | number > | null;
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

/**
 * A single content rule on a gate, as evaluated by gateMatchesPost() (mirrors
 * the PHP Content_Restriction_Control rule shape). `value` holds term/post IDs
 * or post-type slugs; `exclusion` marks a carve-out rule.
 */
type GateContentRule = {
	slug: string;
	value: Array< string | number >;
	exclusion?: boolean;
};

/**
 * An active gate localized as `newspackContentGates.gates` by
 * Content_Gate::enqueue_block_editor_assets(), used for reactive gate matching
 * in the post editor.
 */
type ContentGateData = {
	id: number;
	title: string;
	edit_url: string | null;
	content_rules: GateContentRule[];
	content_rules_match: string;
};

/**
 * A WooCommerce Membership plan localized as `newspack_memberships_gate.plans`
 * by Memberships::enqueue_block_editor_assets(). `gate_id`/`gate_status` are
 * `false` when the plan has no dedicated gate.
 */
type MembershipPlan = {
	id: number;
	name: string;
	gate_id: number | false;
	gate_status: string | false;
	plan_url: string | null;
};

interface Window {
	newspackBlockVisibility: {
		target_blocks: string[];
		available_access_rules: Record< string, AccessRuleConfig >;
		available_gates: GateOption[];
	};
	/** Localized on the post-settings script by Content_Gate::enqueue_block_editor_assets(). */
	newspackContentGates?: {
		gates: ContentGateData[];
		taxonomyMap: Record< string, string >;
		canEditGates: boolean;
	};
	/** Localized on the gate editor script by Memberships::enqueue_block_editor_assets(). */
	newspack_memberships_gate?: MembershipsGateConfig;
}

/**
 * Memberships gate config localized as `newspack_memberships_gate`. `gate_plans`
 * maps plan IDs to their names for the plans this gate applies to.
 */
type MembershipsGateConfig = {
	edit_gate_url: string;
	plans: MembershipPlan[];
	gate_plans: Record< string, string >;
	edit_plan_gate_url: string;
};
