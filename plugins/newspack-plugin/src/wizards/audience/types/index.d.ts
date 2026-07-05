/**
 * Types for the Prequisite component.
 */

type PromptOptionsBase = {
	background_color: string;
	display_title: boolean;
	hide_border: boolean;
	large_border: boolean;
	frequency: string;
	frequency_max: number;
	frequency_start: number;
	frequency_between: number;
	frequency_reset: string;
	overlay_color: string;
	overlay_opacity: number;
	overlay_size: string;
	no_overlay_background: boolean;
	placement: string;
	trigger_type: string;
	trigger_delay: number;
	trigger_scroll_progress: number;
	trigger_blocks_count: number;
	archive_insertion_posts_count: number;
	archive_insertion_is_repeating: false;
	utm_suppression: string;
};

type PromptOptionsBaseKey = keyof PromptOptionsBase;

// Available transactional email slugs.
type EmailSlugs =
	| 'reader-activation-verification'
	| 'reader-activation-magic-link'
	| 'reader-activation-otp-authentication'
	| 'reader-activation-reset-password'
	| 'reader-activation-delete-account'
	| 'reader-activation-change-email'
	| 'reader-activation-change-email-cancel'
	| 'reader-activation-non-reader-user';

// RAS config inherited from RAS wizard view.
type Config = {
	enabled?: boolean;
	enabled_account_link?: boolean;
	account_link_menu_locations?: [ 'tertiary-menu' ];
	newsletters_label?: string;
	terms_text?: string;
	terms_url?: string;
	sync_esp?: boolean;
	metadata_prefix?: string;
	sync_esp_delete?: boolean;
	// Empty string when unset; a list id (number) once selected.
	active_campaign_master_list?: number | string;
	constant_contact_list_id?: string;
	mailchimp_audience_id?: string;
	mailchimp_reader_default_status?: string;
	emails?: {
		[ key in EmailSlugs ]: {
			label: string;
			description: string;
			post_id: number;
			edit_link: string;
			subject: string;
			from_name: string;
			from_email: string;
			reply_to_email: string;
			status: string;
		};
	};
	sender_name?: string;
	sender_email_address?: string;
	contact_email_address?: string;
	// Reader Activation feature toggles surfaced on the Setup screen.
	enabled_account_link_menu?: boolean;
	verify_new_reader_accounts?: boolean;
	use_custom_lists?: boolean;
	newsletter_lists?: import( '../../../../packages/components/src/sortable-newsletter-list-control' ).SelectedNewsletterList[];
	newsletter_list_initial_size?: number;
	oauth_redirect_to_ras?: boolean;
	metadata_fields?: string[];
	// WooCommerce checkout / registration copy managed on the Setup screen.
	woocommerce_registration_required?: boolean;
	woocommerce_checkout_privacy_policy_text?: string;
	woocommerce_enable_subscription_confirmation?: boolean;
	woocommerce_subscription_confirmation_text?: string;
	woocommerce_enable_terms_confirmation?: boolean;
	woocommerce_terms_confirmation_text?: string;
	woocommerce_terms_confirmation_url?: string;
	woocommerce_post_checkout_success_text?: string;
	woocommerce_post_checkout_registration_success_text?: string;
};

type ConfigKey = keyof Config;

// Props for the Prequisite component.
type PrequisiteProps = {
	config: Config;
	getSharedProps: (
		configKey: string,
		type: string
	) => {
		onChange: ( value: string | boolean ) => void;
		disabled?: boolean;
		checked?: boolean;
		value?: string;
	};
	inFlight: boolean;
	saveConfig: ( config: Config ) => void;

	// Schema for prequisite object is defined in PHP class Reader_Activation::get_prerequisites_status().
	prerequisite: {
		active: boolean;
		plugins?: {
			[ pluginName: string ]: boolean; // Are the required plugins active?
		};
		label: string;
		description: string;
		warning?: string;
		instructions?: string;
		help_url: string;
		fields?: {
			[ K in ConfigKey ]: {
				label: string;
				description: string;
			};
		};
		href?: string;
		action_text?: string;
	};
};

type InputField = {
	name: string;
	type: string;
	label: string;
	description: string;
	required?: boolean;
	max_length?: number;
	default: string | number | boolean;
	value?: string | number | boolean;
	options?: {
		label: string;
		value: string | number;
	};
};

// Schema is defined in Newspack Campaigns: https://github.com/Automattic/newspack-popups/blob/trunk/includes/schemas/class-prompts.php
type PromptType = {
	status: string;
	slug: string;
	title: string;
	content: string;
	featured_image_id?: number;
	segments: [
		{
			id: number;
			name: string;
		},
	];
	options: PromptOptions;
	user_input_fields: [ InputField ];
	help_info?: {
		screenshot?: string;
		recommendations?: Array< string >;
		description?: string;
		url?: string;
	};
	ready?: boolean;
};

type PromptOptions = PromptOptionsBase & {
	post_types: Array< string >;
	archive_page_types: Array< string >;
	additional_classes: string;
	excluded_categories: [
		{
			id: number;
			name: string;
		},
	];
	excluded_tags: [
		{
			id: number;
			name: string;
		},
	];
	categories: [
		{
			id: number;
			name: string;
		},
	];
	tags: [
		{
			id: number;
			name: string;
		},
	];
	campaign_groups: [
		{
			id: number;
			name: string;
		},
	];
};

type InputValues = {
	[ fieldName: string ]: string | number | Array< string > | Array< number > | boolean;
};

// Props for the Prompt component.
type PromptProps = {
	inFlight: boolean;
	setInFlight: ( inFlight: boolean ) => void;
	prompt: PromptType;
	setPrompts: ( prompts: Array< PromptType > ) => void;
};

// A published content gate that force-enables reader verification, as returned
// by the audience-management GET endpoint (verification_required_by_gates).
type VerificationRequiredGate = {
	id: string | number;
	edit_url: string;
	title: string;
};

/**
 * Response of the audience-management GET/POST endpoints consumed by the
 * Audience wizard root (views/setup/index).
 */
type AudienceManagementResponse = {
	config: Config;
	prerequisites_status: Record< string, PrequisiteProps[ 'prerequisite' ] >;
	required_plugins?: Record< string, boolean >;
	can_esp_sync: {
		errors: Record< string, string > | string[];
	};
	verification_required_by_gates?: VerificationRequiredGate[];
};

/**
 * Props bag assembled by the Audience wizard root (views/setup/index) and
 * shared with every setup screen it renders (Setup, Campaign, Complete,
 * Content Gating, Payment, Platform Selection). The `headerText`/
 * `tabbedNavigation` members overlap with `WithWizardScreenProps`, which the
 * screens receive from `withWizardScreen`.
 */
type AudienceSetupSharedProps = {
	headerText: string;
	tabbedNavigation?: import( '../../../../packages/components/src/with-wizard-screen' ).WithWizardScreenProps[ 'tabbedNavigation' ];
	wizardApiFetch: import( '../../../../packages/components/src/with-wizard' ).WithWizardInjectedProps[ 'wizardApiFetch' ];
	inFlight: boolean;
	error: false | WpFetchError;
	fetchConfig: () => Promise< void >;
	updateConfig: ( key: string, val: unknown ) => void;
	saveConfig: ( data: Partial< Config > ) => Promise< void >;
	setInFlight: ( inFlight: boolean ) => void;
	setError: ( error: false | WpFetchError ) => void;
	getSharedProps: PrequisiteProps[ 'getSharedProps' ];
	espSyncErrors: Record< string, string > | string[];
	prerequisites: Record< string, PrequisiteProps[ 'prerequisite' ] > | null;
	config: Config;
	requiredPlugins: Record< string, boolean >;
	onChangePlatform: () => void;
	platform?: string;
	verificationRequiredByGates: VerificationRequiredGate[];
};

/**
 * Props of the platform chooser screen (components/platform-selection): the
 * shared setup props plus the chooser-specific callbacks and flags.
 */
type PlatformSelectionProps = AudienceSetupSharedProps & {
	onComplete: () => void;
	onCancel?: () => void;
	showEnableToggle?: boolean;
	platformSelected?: boolean;
};

/**
 * Local config managed by the Content Gating screen (views/setup/content-gating)
 * and its child controls. Distinct from the Reader Activation `Config`.
 */
type ContentGatingViewConfig = {
	gate_status?: string;
	edit_gate_url?: string;
	plans?: unknown[];
	require_all_plans?: boolean;
	show_on_subscription_tab?: boolean;
	has_newsletters?: boolean;
	newsletter_link_bypass_enabled?: boolean;
	content_gifting?: ContentGiftingConfig;
	countdown_banner?: MeteringCountdownConfig;
};

/**
 * Props of the Content Gifting and Metered Countdown child controls, which
 * receive the Content Gating screen's config and its setters.
 */
type ContentGatingChildProps = {
	config: ContentGatingViewConfig;
	setConfig: import( 'react' ).Dispatch< import( 'react' ).SetStateAction< ContentGatingViewConfig > >;
	updateConfig: ( newConfig: Partial< ContentGatingViewConfig > ) => void;
	noBorder?: boolean;
};
