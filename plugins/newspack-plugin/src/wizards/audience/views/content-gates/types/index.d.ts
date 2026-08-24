declare module '@wordpress/block-editor';

// No top-level imports here: they would turn this declaration file into a
// module and strip every type below of its global scope. Use inline
// import() types instead.
type HeaderAction = {
	type: 'primary' | 'secondary' | 'more';
	label: string;
	icon?: import('@wordpress/icons').Icon | string;
	disabled?: boolean;
	destructive?: boolean;
	action?: () => void;
	href?: string;
	separator?: boolean;
};

// An entry in a section's kebab menu, or the header's secondary action. A
// HeaderAction without the store-assigned `type`: either an `action` callback
// or an `href` carries the behaviour.
type SectionMenuItem = Omit< HeaderAction, 'type' >;

// Single source of truth for the composite value shape lives with the control.
type OneTimePurchaseRuleValue = import( '../../../../../content-gate/components/one-time-purchase-rule-control' ).OneTimePurchaseValue;
type GateAccessRuleValue = string | string[] | boolean | OneTimePurchaseRuleValue;
type AccessRule = {
	name: string;
	default: GateAccessRuleValue;
	description?: string;
	id?: string;
	is_boolean?: boolean;
	options?: { value: string; label: string }[];
	placeholder?: string;
	value: GateAccessRuleValue;
};

type GateContentRuleValue = string[];
type ContentRule = {
	name: string;
	default: GateContentRuleValue;
	description?: string;
	endpoint?: string;
	include_only?: boolean;
	options?: { value: string; label: string }[];
	value: GateContentRuleValue;
};

type Metering = {
	enabled: boolean;
	count: number;
	period: 'week' | 'month';
};

type GateAccessRuleProps = {
	config: AccessRule;
	rule?: GateAccessRule;
	enabled?: boolean;
	onToggle?: (slug: string) => void;
	slug: string;
	exclusion?: boolean;
	onChange: (value: GateRuleValue) => void;
};

type GateContentRuleProps = {
	config: ContentRule;
	rule?: GateContentRule;
	enabled?: boolean;
	onToggle?: (slug: string) => void;
	slug: string;
	onChange: (value: GateContentRuleValue) => void;
	onChangeExclusion?: (value: boolean) => void;
	isNewsletter?: boolean;
};

type GateRuleControlProps = {
	slug: string;
	value: GateRuleValue;
	exclusion?: boolean;
	onChange: (value: GateRuleValue) => void;
	onChangeExclusion?: (value: boolean) => void;
	isStatic?: boolean;
};

type AccessRules = {
	[key: string]: AccessRule;
};

type ContentRules = {
	[key: string]: ContentRule;
};

type GateAccessRule = {
	slug: string;
	value: GateAccessRuleValue;
};

type GateContentRule = {
	slug: string;
	value: GateContentRuleValue;
	exclusion?: boolean;
};

type GateStatus = 'publish' | 'draft' | 'pending' | 'future' | 'private' | 'trash';

type Gate = {
	id: number;
	title: string;
	priority: number;
	status: GateStatus;
	isExpanded?: boolean;
	collapse?: boolean;
	content_rules: GateContentRule[];
	content_rules_match: 'all' | 'any';
	registration: Registration;
	custom_access: CustomAccess;
};

type Registration = {
	active: boolean;
	metering: Metering;
	require_verification: boolean;
	// Optional: the edit UI rebuilds this object without `gate_layout_id`
	// (see edit/registration.tsx), and the server falls back to the gate ID.
	gate_layout_id?: number;
};

type GateAccessRuleGroup = GateAccessRule[];

type CustomAccess = {
	active: boolean;
	metering: Metering;
	// Optional: the edit UI rebuilds this object without `gate_layout_id`
	// (see edit/custom-access.tsx), and the server falls back to the gate ID.
	gate_layout_id?: number;
	access_rules: GateAccessRuleGroup[];
	// Optional: gates saved before the setting existed lack the key; reads treat absence as ON.
	payment_recovery_grace?: boolean;
};

// All fields are optional: the settings screens build these objects
// incrementally by spreading a possibly-empty stored config, and every read
// falls back to a default (e.g. `config?.content_gifting?.limit || 10`).
type ContentGiftingConfig = {
	enabled?: boolean;
	limit?: number;
	interval?: string;
	expiration_time?: number;
	expiration_time_unit?: string;
	style?: string;
	cta_label?: string;
	button_label?: string;
	cta_type?: string;
	cta_product_id?: number;
	cta_url?: string;
};

type MeteringCountdownConfig = {
	enabled?: boolean;
	style?: string;
	cta_label?: string;
	button_label?: string;
	cta_url?: string;
	cta_type?: string;
	cta_product_id?: number;
};

type FeedRestrictionMode = 'truncate' | 'exclude';

type AdvancedSettingsConfig = {
	restrict_feeds: boolean;
	feed_restriction_mode: FeedRestrictionMode;
	newsletter_link_bypass_enabled: boolean;
};

type GateSettings = {
	content_gifting?: ContentGiftingConfig;
	countdown_banner?: MeteringCountdownConfig;
	advanced_settings?: AdvancedSettingsConfig;
	has_institutions?: boolean;
	// Capability flags the gates endpoint returns alongside the stored settings.
	has_newsletters?: boolean;
};

type GateConfig = {
	gates: Gate[];
	config: GateSettings;
};

/**
 * Shape of the content-gates wizard store data (`useWizardData`): `gates` and
 * `config` are written under those keys via `updateWizardSettings`, alongside
 * the per-path request cache the store also maintains.
 */
type ContentGatesWizardData = {
	gates?: Gate[];
	config?: GateSettings;
};

// A product the publisher can attach to a gate CTA. Localized by the Audience
// wizard on `window.newspackAudience` (see wizards/types/window.d.ts).
type PurchasableProductOption = {
	label: string;
	value: number;
};

type Institution = {
	id: number;
	title: { raw: string; rendered: string };
	excerpt: { raw: string; rendered: string };
	featured_media: number;
	slug: string;
	status: string;
	meta: {
		np_institution_email_domain: string;
		np_institution_ip_range: string;
		np_institution_reader_data: string;
	};
	_embedded?: {
		'wp:featuredmedia'?: Array<{ source_url: string }>;
	};
};
