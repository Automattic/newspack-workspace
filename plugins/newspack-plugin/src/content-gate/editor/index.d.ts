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

interface Window {
	newspackBlockVisibility: {
		target_blocks: string[];
		available_access_rules: Record< string, AccessRuleConfig >;
		available_gates: GateOption[];
	};
}
