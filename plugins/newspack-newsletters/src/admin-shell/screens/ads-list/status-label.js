/**
 * Map the consolidated `kind` value from the
 * `newspack_newsletters_ad_status` REST field to a translated
 * user-facing label.
 *
 * Keep in sync with the `enum` in `Ads_List_REST::register_rest_fields`.
 */

import { __ } from '@wordpress/i18n';

import { createStatusLabelModule } from '../../utils/status-label';

export const { STATUS_KIND_LABELS, statusKindLabel } = createStatusLabelModule( () => ( {
	active: __( 'Active', 'newspack-newsletters' ),
	scheduled: __( 'Scheduled', 'newspack-newsletters' ),
	expired: __( 'Expired', 'newspack-newsletters' ),
	draft: __( 'Draft', 'newspack-newsletters' ),
	trash: __( 'Trash', 'newspack-newsletters' ),
} ) );

export { isTrashed } from '../../utils/status-label';
