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
	active_campaign_master_list?: number;
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
};

type ConfigKey = keyof Config;

// Props for the Prequisite component.
type PrequisiteProps = {
	config: Config;
	slug: string;
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
	skipPrerequisite: ( data: { prerequisite: string; skip: boolean } ) => void;

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
		action_enabled?: boolean;
		disabled_text?: string;
		is_unavailable?: boolean;
		is_skipped?: boolean;
		skippable?: boolean;
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
	setPrompts: ( prompts: boolean | Array< PromptType > ) => void;
	unblock: () => void;
};
