declare module '@wordpress/block-editor';

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

interface Window {
	newspackBlockVisibility: {
		target_blocks: string[];
		available_access_rules: Record< string, AccessRuleConfig >;
		available_gates: GateOption[];
	};
}
