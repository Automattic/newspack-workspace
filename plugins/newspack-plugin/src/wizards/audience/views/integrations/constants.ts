/**
 * Shared module-level constants, types and helpers for the integrations
 * settings views, the activity logs view and its detail modal.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { dateI18n, getSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import type { BadgeLevel } from '../../../../../packages/components/src/badge';

export const API_BASE = '/newspack/v1/wizard/newspack-audience-integrations/settings';

/**
 * A settings-field option, as injected by the integrations framework
 * (see class-integration.php:get_settings_config()).
 */
export type IntegrationFieldOption = {
	value: string;
	label?: string;
};

/**
 * A settings-field value, as stored and returned by the settings endpoint.
 */
export type IntegrationFieldValue = string | number | boolean | string[];

/**
 * A group of outgoing-metadata options, keyed by UI section
 * (see Sync\Metadata::get_grouped_default_fields()).
 */
export type IntegrationGroupedOptions = {
	section: string;
	fields: string[];
};

/**
 * A single integration settings field declaration
 * (see class-integration.php:get_settings_config()).
 */
export type IntegrationSettingsField = {
	key: string;
	type?: string;
	label?: string;
	description?: string;
	placeholder?: string;
	help_url?: string;
	/** OAuth fields: URL starting the connect flow. */
	oauth_url?: string;
	/** OAuth fields: URL revoking the connection. */
	disconnect_url?: string;
	/**
	 * Options as { value, label } objects; metadata fields may also receive
	 * bare strings for backward compatibility.
	 */
	options?: Array< string | IntegrationFieldOption >;
	/** Outgoing-metadata fields: options grouped by section. */
	grouped_options?: IntegrationGroupedOptions[];
	value?: IntegrationFieldValue;
};

/**
 * A plugin an integration depends on (see Integration::get_required_plugins()).
 */
export type IntegrationRequiredPlugin = {
	slug: string;
	name: string;
	is_active: boolean;
	is_installed?: boolean;
};

/**
 * A single integration's settings payload
 * (see Integrations::get_all_integration_settings()).
 */
export type IntegrationConfig = {
	id: string;
	name: string;
	description: string;
	enabled: boolean;
	is_set_up: boolean;
	setup_url: string;
	settings: IntegrationSettingsField[];
	required_plugins: IntegrationRequiredPlugin[];
};

/**
 * The settings endpoint response: integrations keyed by ID.
 */
export type IntegrationsSettings = Record< string, IntegrationConfig >;

/**
 * Per-integration map of unsaved settings-field changes, keyed by field key.
 */
export type IntegrationPendingChanges = Record< string, Record< string, IntegrationFieldValue > >;

/**
 * A row of the integration activity logs list endpoint. IDs come through as
 * strings (raw wpdb column values serialized to JSON).
 */
export type IntegrationLogItem = {
	id: string;
	timestamp: string;
	event: string;
	status: string;
	email: string | null;
};

/**
 * The activity logs list endpoint response.
 */
export type IntegrationLogsResponse = {
	items: IntegrationLogItem[];
	total: number;
	page: number;
	per_page: number;
};

/**
 * A normalized ActionScheduler log entry (see Action_Scheduler::get_action_logs()).
 */
export type IntegrationLogEntry = {
	date_gmt: string;
	message: string;
};

/**
 * The single-action details endpoint response.
 */
export type IntegrationActionDetails = {
	action: {
		id: string;
		hook: string;
		event: string;
		email: string | null;
		status: string;
		scheduled_date_gmt: string;
		attempts: number;
		last_attempt_gmt: string;
		group: string;
		priority: number;
		/** Decoded action payload; the raw string when the payload is not valid JSON. */
		args: unknown;
	};
	logs: IntegrationLogEntry[];
};

/**
 * The run-action endpoint response.
 */
export type IntegrationRunActionResponse = {
	status: string;
	message?: string;
};

/**
 * Badge label/level pair for an integration action status.
 */
export type IntegrationActionStatusBadge = {
	label: string;
	level: BadgeLevel;
};

export const STATUS_MAP: Record< string, IntegrationActionStatusBadge | undefined > = {
	complete: { label: __( 'Complete', 'newspack-plugin' ), level: 'success' },
	failed: { label: __( 'Failed', 'newspack-plugin' ), level: 'error' },
	pending: { label: __( 'Pending', 'newspack-plugin' ), level: 'info' },
	'in-progress': { label: __( 'In progress', 'newspack-plugin' ), level: 'info' },
	canceled: { label: __( 'Canceled', 'newspack-plugin' ), level: 'warning' },
};

/**
 * Format a GMT timestamp using the site's datetime format.
 *
 * @param gmt The GMT timestamp, e.g. `2024-01-01T00:00:00`.
 * @return The localized, formatted timestamp, or an empty string.
 */
export function formatTimestamp( gmt?: string | null ): string {
	if ( ! gmt ) {
		return '';
	}
	const dateFormat = getSettings().formats.datetime || 'F j, Y, g:i a';
	return dateI18n( dateFormat, `${ gmt }+00:00` );
}
