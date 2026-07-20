/**
 * Shared types for the Newsletters wizard.
 */

/**
 * Event names exposed by the Newspack Newsletters wizard bridge on
 * `window.newspackNewslettersEvents`.
 */
export type NewslettersBridgeEvents = {
	BRIDGE_MOUNTED: string;
	OPEN_MODAL: string;
	OPEN_CONFIRM_DELETE: string;
	LOCAL_LIST_SAVED: string;
	LOCAL_LIST_DELETED: string;
};

/**
 * A subscription list as returned by the newsletters plugin's lists endpoint.
 */
export type SubscriptionList = {
	id?: string;
	db_id?: number;
	name: string;
	description?: string;
	active?: boolean;
	type?: string;
	type_label?: string;
	edit_link?: string;
};

/**
 * The value of a newsletters setting: text-ish settings are strings, checkbox
 * settings are booleans.
 */
export type NewslettersSettingValue = string | boolean;

/**
 * An option of a select-type newsletters setting.
 */
export type NewslettersSettingOption = {
	value: string;
	name: string;
};

/**
 * A single newsletters setting's metadata, as returned by the wizard's
 * settings endpoint.
 */
export type NewslettersSetting = {
	key: string;
	description?: string;
	help?: string;
	helpURL?: string;
	placeholder?: string;
	options?: NewslettersSettingOption[];
	provider?: string;
	type?: string;
	value?: NewslettersSettingValue;
	onboarding?: boolean;
};

/**
 * Provider-specific labels returned by the wizard's settings endpoint.
 */
export type NewslettersLabels = {
	local_list_explanation?: string;
};

/**
 * The wizard's settings endpoint response, also used as the fetched-config
 * state shape.
 */
export type NewslettersWizardData = {
	configured?: boolean;
	esp_connected?: boolean;
	labels?: NewslettersLabels;
	settings?: Record< string, NewslettersSetting >;
};

/**
 * A flat map of setting key to value, as sent to the settings endpoint on
 * save. The service provider is always a string value.
 */
export interface NewslettersConfigValues {
	newspack_newsletters_service_provider?: string;
	[ key: string ]: NewslettersSettingValue | undefined;
}

/**
 * An error surfaced in the newsletters settings UI: an API error response or
 * a locally-built message.
 */
export type NewslettersError = {
	message?: string;
	code?: string;
};

declare global {
	interface Window {
		/** Data localized for the newsletters wizard screen. */
		newspack_newsletters_wizard: {
			new_subscription_lists_url?: string;
		};
		/** Event names exposed by the newsletters wizard bridge, when loaded. */
		newspackNewslettersEvents?: NewslettersBridgeEvents;
		/** Readiness flag set by the newsletters wizard bridge before it announces itself. */
		newspackNewslettersBridgeReady?: boolean;
	}
}
