/**
 * TypeScript type definitions for Nextdoor integration
 */

export interface NextdoorSettings {
	client_id: string;
	client_secret: string;
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

export interface OAuthResponse {
	login_url?: string;
}

export interface ClaimPageResponse {
	page_id?: number;
	success?: boolean;
}

export interface OnboardingProps {
	settings: NextdoorSettings;
	status: NextdoorStatus;
	error: string | null;
	updateSettings: ( settings: Partial< NextdoorSettings > ) => Promise< NextdoorSettings >;
	startOAuthFlow: ( email: string, country: string ) => Promise< OAuthResponse >;
	claimPage: ( publicationUrl: string, test?: boolean ) => Promise< ClaimPageResponse >;
	setError: ( error: string | null ) => void;
	disconnect: () => Promise< void >;
}

export interface SettingsProps {
	settings: NextdoorSettings;
	status: NextdoorStatus;
	error: string | null;
	updateSettings: ( settings: Partial< NextdoorSettings > ) => Promise< NextdoorSettings >;
	setError: ( error: string | null ) => void;
	disconnect: () => Promise< void >;
}
