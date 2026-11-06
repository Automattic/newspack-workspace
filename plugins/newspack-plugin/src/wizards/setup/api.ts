import apiFetch from '@wordpress/api-fetch';

export type InstallState = {
	approach: 'generated' | 'import' | null;
	complete: boolean;
};

export type PluginInfo = {
	Slug: string;
	Name: string;
	Status: string;
	Configured?: boolean;
	[ key: string ]: unknown;
};

export type InitialCheck = {
	plugins: PluginInfo[];
	is_ssl: boolean;
};

export type NextSteps = {
	reader_revenue: string;
	newsletters: string | null;
	ads: string | null;
};

const BASE = '/newspack/v1/wizard/newspack-setup-wizard';

export const fetchInitialCheck = (): Promise<
	InitialCheck & {
		install_state: InstallState;
		homepage_edit_url: string | null;
		next_steps?: NextSteps;
	}
> => apiFetch( { path: `${ BASE }/initial-check/` } );

export const saveInstallState = ( state: InstallState ): Promise< InstallState > =>
	apiFetch( { path: `${ BASE }/install-state`, method: 'POST', data: state } );

export type SelectOption = { value: string | null; label: string };

export type Profile = {
	site_icon?: { id: number | string; url: string } | false | null;
	site_title?: string;
	tagline?: string;
	countrystate?: string;
	currency?: string;
	[ key: string ]: unknown;
};

export type ProfileResponse = {
	profile: Profile;
	currencies: SelectOption[];
	countries: SelectOption[];
	wpseo_fields: { key: string; label: string; placeholder: string }[];
	location_hints: {
		site_locale: string;
		country_currencies: Record< string, string >;
		imported_currency: string;
	};
};

const PROFILE = '/newspack/v1/profile/';

export const fetchProfile = (): Promise< ProfileResponse > => apiFetch( { path: PROFILE } );

export const saveProfile = ( profile: Profile ): Promise< unknown > => apiFetch( { path: PROFILE, method: 'POST', data: { profile } } );
