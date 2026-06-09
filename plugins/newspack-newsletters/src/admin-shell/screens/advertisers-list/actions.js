/**
 * Per-row + bulk actions for the Advertisers list.
 *
 * Delete is `force=true` — REST taxonomy terms can't be trashed, only
 * removed outright.
 */

import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

import ConfirmModal from '../../components/confirm-modal';
import { runBulk } from '../../utils/bulk-action';

const TAXONOMY_PATH = '/wp/v2/newspack_nl_advertiser';

const deleteOne = id => apiFetch( { path: `${ TAXONOMY_PATH }/${ id }?force=true`, method: 'DELETE' } );

export function getActions( { onEdit, onMutated } ) {
	const editAction = {
		id: 'edit',
		label: __( 'Edit', 'newspack-newsletters' ),
		isPrimary: true,
		callback: items => {
			const item = items[ 0 ];
			if ( ! item ) {
				return;
			}
			onEdit( item );
		},
	};

	const deleteAction = {
		id: 'delete-permanently',
		label: __( 'Delete', 'newspack-newsletters' ),
		isDestructive: true,
		supportsBulk: true,
		RenderModal: ( { items, closeModal } ) => (
			<ConfirmModal
				items={ items }
				closeModal={ closeModal }
				confirmLabel={ __( 'Delete permanently', 'newspack-newsletters' ) }
				confirmingLabel={ __( 'Deleting…', 'newspack-newsletters' ) }
				question={ sprintf(
					/* translators: %d: number of advertisers */
					_n(
						'Permanently delete %d advertiser? Ads referencing it will lose the assignment. This cannot be undone.',
						'Permanently delete %d advertisers? Ads referencing them will lose the assignment. This cannot be undone.',
						items.length,
						'newspack-newsletters'
					),
					items.length
				) }
				isDestructive
				onConfirm={ list =>
					runBulk( list, item => deleteOne( item.id ), {
						// `onMutated` refetches both the paginated list and
						// the all-advertisers cache that powers the parent
						// picker — without the second refetch, a deleted
						// term would linger in the modal's TreeSelect and
						// fail server-side if picked as a parent.
						refresh: onMutated,
						successPlural: n => _n( 'Advertiser deleted.', 'Advertisers deleted.', n, 'newspack-newsletters' ),
						failurePlural: n =>
							sprintf(
								/* translators: %d: number that failed */
								_n(
									'Failed to delete %d advertiser. Please try again.',
									'Failed to delete %d advertisers. Please try again.',
									n,
									'newspack-newsletters'
								),
								n
							),
					} )
				}
			/>
		),
	};

	return [ editAction, deleteAction ];
}
