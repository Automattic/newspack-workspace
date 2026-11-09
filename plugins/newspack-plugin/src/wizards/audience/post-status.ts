/**
 * The shared status name for each WordPress post status the Audience lists surface,
 * and the label they show for it.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import type { StatusName } from '../../../packages/components/src/status-indicator';

const POST_STATUSES = {
	publish: 'active',
	future: 'scheduled',
	draft: 'draft',
	pending: 'pending',
	private: 'private',
	trash: 'trash',
} as const;

export const postStatus = ( status: string ): StatusName => POST_STATUSES[ status as keyof typeof POST_STATUSES ] ?? 'draft';

/**
 * The label an Audience list shows for a post status. A live item is Active and a
 * draft is Inactive, the words content gates and discounts use for the same two
 * states; any other status keeps its WordPress label.
 *
 * @param status The post status.
 * @param label  The status's WordPress label.
 * @return The label to show.
 */
export const audienceStatusLabel = ( status: string, label: string ): string => {
	if ( 'publish' === status ) {
		return __( 'Active', 'newspack-plugin' );
	}
	if ( 'draft' === status ) {
		return __( 'Inactive', 'newspack-plugin' );
	}
	return label;
};
