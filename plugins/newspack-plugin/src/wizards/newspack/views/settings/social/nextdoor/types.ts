/**
 * TypeScript type definitions for Nextdoor integration
 */

export interface NextdoorSettings {
	client_id: string;
	publication_url: string;
	allowed_roles: string[];
}

export interface NextdoorStatus {
	is_connected: boolean;
	has_credentials: boolean;
	has_centralized_credentials: boolean;
	has_tokens: boolean;
	has_page: boolean;
	token_valid: boolean;
}

export interface NextdoorData {
	module_enabled_nextdoor: boolean;
	is_connected: boolean;
	connection_status: NextdoorStatus;
	settings: NextdoorSettings;
}

/**
 * The endpoint's write shape: a flat partial of the settings it accepts, plus
 * the module flag. It is deliberately not a `Partial< NextdoorData >` — the
 * server takes these fields at the top level and ignores the read-only ones.
 */
export interface NextdoorUpdatePayload {
	module_enabled_nextdoor?: boolean;
	client_id?: string;
	client_secret?: string;
	allowed_roles?: string[];
}

export interface OAuthResponse {
	login_url?: string;
}

export interface ClaimPageResponse {
	page_id?: number;
	success?: boolean;
}

export interface NextdoorFormProps {
	settings: NextdoorSettings;
	status: NextdoorStatus;
	error: string | null;
	updateSettings: ( payload: NextdoorUpdatePayload ) => Promise< void >;
	startOAuthFlow: ( email: string, country: string ) => Promise< OAuthResponse >;
	claimPage: ( publicationUrl: string, test?: boolean ) => Promise< ClaimPageResponse >;
	setError: ( error: string | null ) => void;
	renderSecondaryActions?: () => React.ReactNode;
}
