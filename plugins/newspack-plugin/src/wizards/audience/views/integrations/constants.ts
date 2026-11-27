/**
 * Shared module-level constants, types and helpers for the integration activity
 * logs view and its detail modal.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatSiteDateTime } from '../../../../../packages/components/src/dataviews/format-date';
import type { StatusName, StatusTone } from '../../../../../packages/components/src/status-indicator';
import type { BadgeIntent } from '../../../../../packages/components/src/types';

/**
 * A settings-field option, as injected by the integrations framework
 * (see class-integration.php:get_settings_config()).
 */
export type IntegrationFieldOption = {
	value: string;
	label?: string;
};

/**
 * An inbound metadata-field option: a named field the publisher can segment on,
 * carrying the field's declared value type and default matching operator.
 */
export type IntegrationIncomingFieldOption = {
	value: string;
	label?: string;
	matching_function?: string;
	has_options?: boolean;
	value_type?: string;
};

/**
 * A settings-field value, as stored and returned by the settings endpoint. The
 * inbound metadata field stores a `{ fieldKey => operator }` map rather than a
 * scalar or list.
 */
export type IntegrationFieldValue = string | number | boolean | string[] | Record< string, string >;

/**
 * A group of outgoing-metadata options, keyed by UI section
 * (see Sync\Metadata::get_grouped_default_fields()).
 */
export type IntegrationGroupedOptions = {
	section: string;
	fields: string[];
	field_details?: Record< string, { status?: string; description?: string } >;
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
	required?: boolean;
	/** A checkbox field may render as an Enabled/Disabled toggle group. */
	control?: string;
	/** OAuth fields: URL starting the connect flow. */
	oauth_url?: string;
	/** OAuth fields: URL revoking the connection. */
	disconnect_url?: string;
	/**
	 * Options as { value, label } objects; metadata fields may also receive
	 * bare strings for backward compatibility, and inbound fields carry the
	 * richer option shape.
	 */
	options?: Array< string | IntegrationFieldOption | IntegrationIncomingFieldOption >;
	/** Outgoing-metadata fields: options grouped by section. */
	grouped_options?: IntegrationGroupedOptions[];
	/** Shows the field only when the referenced field matches. */
	condition?: { field: string; equals: boolean | string | number };
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
 * A how-to step for an integration (see Integration::get_guide()).
 */
export type IntegrationGuideStep = {
	title: string;
	description: string;
	link?: { url: string; label: string };
};

/**
 * A single integration's settings payload
 * (see Integrations::get_all_integration_settings()).
 */
export type IntegrationConfig = {
	id?: string;
	name?: string;
	description?: string;
	enabled?: boolean;
	is_connected?: boolean;
	unsupported_reason?: string;
	unsupported_action_label?: string;
	provider?: string;
	setup_url?: string;
	settings?: IntegrationSettingsField[];
	required_plugins?: IntegrationRequiredPlugin[];
	guide?: IntegrationGuideStep[];
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
 * The DataViews view state driving a logs list request. Re-exported from the
 * DataViews component so the list views and the pure query helpers agree.
 */
export type { View as LogsView } from '../../../../../packages/components/src/dataviews';

/**
 * A row of the scheduled-actions list endpoint. IDs come through as numbers
 * (raw wpdb column values).
 */
export type IntegrationLogItem = {
	id: string;
	timestamp: string;
	event: string;
	status: string;
	email?: string | null;
};

/**
 * The scheduled-actions list endpoint response.
 */
export type IntegrationLogsResponse = {
	items: IntegrationLogItem[];
	total: number;
};

/**
 * A normalized Action Scheduler log entry (see Action_Scheduler::get_action_logs()).
 */
export type IntegrationLogEntry = {
	date_gmt: string;
	message: string;
};

/**
 * The single scheduled-action details endpoint response.
 */
export type IntegrationActionDetails = {
	action: {
		id: string;
		event: string;
		email?: string | null;
		status: string;
		scheduled_date_gmt: string;
		attempts: number;
		last_attempt_gmt?: string | null;
		group: string;
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
 * A notice shown while and after a scheduled action runs.
 */
export type RunActionNotice = {
	message: string;
	type: string;
};

/**
 * The retry state carried by a push-log entry.
 */
export type PushLogRetry = {
	is_pending?: boolean;
	is_running?: boolean;
	action_id: number;
	scheduled_at?: string;
};

/**
 * A row of the push-log (sync activity) endpoint: one sync for one reader.
 */
export type PushLogItem = {
	id: string;
	email: string;
	operation: string;
	status: string;
	context?: string;
	created_at: string;
	updated_at: string;
	repeat_count: number;
	attempts?: number;
	max_attempts?: number;
	error_class?: string;
	error_code?: string;
	error_message?: string;
	retry?: PushLogRetry;
};

/**
 * The push-log list endpoint response.
 */
export type PushLogResponse = {
	items: PushLogItem[];
	total: number;
	retention_days?: { success?: number; failed?: number } | null;
};

/**
 * One field in a push-log entry's detail view.
 */
export type PushLogField = {
	key: string;
	label: string;
	before?: string | number | null;
	after?: string | number | null;
	changed?: boolean;
	volatile?: boolean;
};

/**
 * The single push-log entry details endpoint response.
 */
export type PushLogDetails = {
	entry: PushLogItem;
	compared_to?: PushLogItem | null;
	fields: PushLogField[];
};

/**
 * The display mapping for a status: its label, the status indicator's mark, an
 * optional tone, and the badge intent.
 */
export type IntegrationStatusDisplay = {
	label: string;
	status: StatusName;
	tone?: StatusTone;
	intent: BadgeIntent;
};

export const API_BASE = '/newspack/v1/wizard/newspack-audience-integrations/settings';

// A cancelled job is a deliberate stop, not a failure, so it must not share
// `failed`'s treatment in either the column or the detail modal's badge. The
// design system files terminal, non-actionable states under `none`, and the
// column keeps it neutral rather than the red other cancelled states take.
//
// `canceled` is Action Scheduler's own spelling, so it stays as the library writes it.
export const STATUS_MAP: Record< string, IntegrationStatusDisplay > = {
	complete: { label: __( 'Complete', 'newspack-plugin' ), status: 'done', intent: 'stable' },
	failed: { label: __( 'Failed', 'newspack-plugin' ), status: 'error', intent: 'high' },
	pending: { label: __( 'Pending', 'newspack-plugin' ), status: 'pending', intent: 'draft' },
	'in-progress': { label: __( 'In progress', 'newspack-plugin' ), status: 'progress', intent: 'informational' },
	canceled: { label: __( 'Canceled', 'newspack-plugin' ), status: 'cancelled', tone: 'neutral', intent: 'none' },
};

export const PUSH_LOG_STATUS_MAP: Record< string, { label: string; status: StatusName; intent: BadgeIntent } > = {
	success: { label: __( 'Synced', 'newspack-plugin' ), status: 'done', intent: 'stable' },
	retrying: { label: __( 'Retrying', 'newspack-plugin' ), status: 'progress', intent: 'informational' },
	failed: { label: __( 'Failed', 'newspack-plugin' ), status: 'error', intent: 'high' },
};

export const PUSH_LOG_OPERATION_LABELS: Record< string, string > = {
	upsert: __( 'Update', 'newspack-plugin' ),
	flag: __( 'Deletion flag', 'newspack-plugin' ),
	delete: __( 'Deletion', 'newspack-plugin' ),
};

// What the sync made of an error, in words that say who can act on it.
export const PUSH_LOG_ERROR_CLASS_LABELS: Record< string, string > = {
	transient: __( 'Temporary error', 'newspack-plugin' ),
	permanent_contact: __( 'Rejected by the provider', 'newspack-plugin' ),
	permanent_config: __( 'Account problem', 'newspack-plugin' ),
	benign: __( 'Already up to date', 'newspack-plugin' ),
};

export function formatTimestamp( gmt?: string | null ): string {
	if ( ! gmt ) {
		return '';
	}
	return formatSiteDateTime( `${ gmt }+00:00` );
}
