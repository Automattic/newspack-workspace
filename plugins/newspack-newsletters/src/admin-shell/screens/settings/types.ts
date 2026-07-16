/**
 * Shared types for the standalone Settings screen — provider selection,
 * options schema, and subscription lists. Local to this screen; the
 * DataView list screens have their own shared shapes in `../../types`.
 */

/** Whether a subscription list row is site-local or materialised from the ESP. */
export type ListKind = 'local' | 'esp';

/** A subscription list row from `GET /newspack-newsletters/v1/lists`. */
export interface SubscriptionListRow {
	id?: string;
	db_id: number;
	title?: string;
	name?: string;
	remote_name?: string;
	description?: string;
	type?: string;
	type_label?: string;
	active?: boolean;
	audience?: string;
	[ key: string ]: unknown;
}

/** A service-provider choice for the `<SelectControl>` in `ProviderSection`. */
export interface ProviderChoice {
	slug: string;
	name: string;
}

/** OAuth connection state for the active provider, when it supports OAuth. */
export interface OAuthState {
	valid: boolean;
	auth_url: string;
}

/** The active provider's persisted state, as returned by the settings REST endpoint. */
export interface ProviderState {
	selected: string;
	credentials_set: Record< string, boolean >;
	status?: boolean;
	oauth?: OAuthState | null;
}

/** A single entry in the newsletter-options / letterhead schema. */
export interface OptionsSchemaField {
	key: string;
	label: string;
	type?: string;
	default?: unknown;
	help?: string;
	help_url?: string;
	placeholder?: string;
	provider?: string;
}

/** The aggregated settings payload from `GET /newspack-newsletters/v1/admin-shell/settings`. */
export interface SettingsData {
	provider?: ProviderState;
	providers?: ProviderChoice[];
	options?: Record< string, string | boolean >;
	schema?: OptionsSchemaField[];
	lists_can_add_local?: boolean;
}

/** A client-side credential field descriptor rendered by `ProviderSection`. */
export interface ProviderCredentialField {
	key: string;
	label: string;
	help?: string;
	helpURL?: string;
	placeholder?: string;
}
