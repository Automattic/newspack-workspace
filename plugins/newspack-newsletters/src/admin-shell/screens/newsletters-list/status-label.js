/**
 * Map the consolidated `kind` value from the `newspack_newsletters_status`
 * REST field to a translated user-facing label.
 *
 * Keep in sync with the `enum` in `Newsletters_List_REST::register_rest_fields`.
 */

import { __ } from '@wordpress/i18n';

import { createStatusLabelModule } from '../../utils/status-label';
import { isManualProvider } from '../../../utils/service-provider';

export const { STATUS_KIND_LABELS, statusKindLabel } = createStatusLabelModule( () => ( {
	// The manual provider doesn't send through an ESP, so a published newsletter reads "Published", not "Sent".
	sent: isManualProvider() ? __( 'Published', 'newspack-newsletters' ) : __( 'Sent', 'newspack-newsletters' ),
	scheduled: __( 'Scheduled', 'newspack-newsletters' ),
	draft: __( 'Draft', 'newspack-newsletters' ),
	trash: __( 'Trash', 'newspack-newsletters' ),
} ) );

export { isTrashed } from '../../utils/status-label';
