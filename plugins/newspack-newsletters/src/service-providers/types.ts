/**
 * Shared types for the newsletters ESP (email service provider) integrations.
 *
 * Every provider under `service-providers/<name>` implements a subset of the
 * `ServiceProvider` contract; `getServiceProvider()` merges the active
 * provider's implementation with its `name`. Editor and admin-shell code
 * consumes these shapes, so they are exported as a normal module (import them;
 * they are not global).
 */

/**
 * WordPress dependencies
 */
import type { ComponentType, ReactElement } from 'react';

/** A send list or sublist (segment/group) as returned by the ESP. */
export interface SendList {
	id: string | number;
	name?: string;
	label?: string;
	count?: number | string;
	entity_type?: string;
	[ key: string ]: unknown;
}

/** An ESP campaign folder. */
export interface CampaignFolder {
	id: string | number;
	name?: string;
	[ key: string ]: unknown;
}

/** The ESP campaign object embedded in the retrieve response. */
export interface Campaign {
	status?: string;
	current_status?: string;
	recipients?: {
		list_id?: string;
		recipient_count?: number | string;
		[ key: string ]: unknown;
	};
	settings?: {
		folder_id?: string;
		[ key: string ]: unknown;
	};
	[ key: string ]: unknown;
}

/**
 * Data returned from an ESP's `retrieve` endpoint, aggregated in the editor
 * store. All members are optional because the store seeds an empty object and
 * fills it incrementally.
 */
export interface NewsletterData {
	campaign?: Campaign;
	lists?: SendList[];
	sublists?: SendList[];
	folders?: CampaignFolder[];
	[ key: string ]: unknown;
}

/**
 * Newsletter post meta relevant to sending and previewing. Post meta is an
 * open bag, so unknown keys resolve to `unknown` and must be narrowed.
 */
export interface NewsletterMeta {
	send_list_id?: string;
	send_sublist_id?: string;
	is_public?: boolean;
	newsletter_sent?: boolean | number | string;
	newspack_email_html?: string;
	template_id?: number;
	stringifiedCampaignDefaults?: string;
	campaign_defaults?: string;
	field_name?: string;
	mc_folder_id?: string;
	font_body?: string;
	font_header?: string;
	background_color?: string;
	text_color?: string;
	custom_css?: string;
	[ key: string ]: unknown;
}

/**
 * Props passed to a provider's `ProviderSidebar` component. Individual
 * providers read only the subset they need; the manual provider uses the
 * `render*` callbacks while ESP providers use the meta editing props.
 */
export interface ProviderSidebarProps {
	inFlight?: boolean;
	postId?: number;
	meta?: NewsletterMeta;
	updateMeta?: ( meta: Partial< NewsletterMeta > ) => void;
	renderSubject?: () => ReactElement;
	renderPreviewText?: () => ReactElement;
}

/**
 * The contract each ESP integration implements. Members are optional because
 * providers implement only what they support; `getServiceProvider()` fills the
 * gaps (e.g. an absent `ProviderSidebar` falls back to a no-op at the call
 * site).
 */
export interface ServiceProvider {
	/** Human-readable provider name shown in the UI. */
	displayName?: string;
	/** Whether the ESP authenticates via OAuth rather than API keys. */
	hasOauth?: boolean;
	/** Sidebar panel rendered for provider-specific data and controls. */
	ProviderSidebar?: ComponentType< ProviderSidebarProps >;
	/** Renders campaign info in the pre-send confirmation modal. */
	renderPreSendInfo?: ( newsletterData?: NewsletterData, meta?: NewsletterMeta ) => ReactElement | null;
	/** Renders info in the post-update modal (manual provider only). */
	renderPostUpdateInfo?: ( newsletterData?: NewsletterData ) => ReactElement | null;
	/** Determines whether the campaign has already been sent. */
	isCampaignSent?: ( newsletterData: NewsletterData, postStatus?: string ) => boolean;
}

/** The active provider merged with its registry `name`. */
export type ActiveServiceProvider = ServiceProvider & { name?: string };
