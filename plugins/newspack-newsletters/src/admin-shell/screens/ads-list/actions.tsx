/**
 * Per-row + bulk actions for the Ads list.
 *
 * Trash / Restore / Delete are safe here — unlike the newsletters
 * list, the ads CPT has no `transition_post_status` ESP-send hazard.
 */

import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { trash } from '@wordpress/icons';

import { getAdminUrl } from '../../admin-globals';
import ConfirmModal from '../../components/confirm-modal';
import RenameForm from '../../components/rename-form';
import { runBulk } from '../../utils/bulk-action';
import { isTrashed } from './status-label';

import type { ListAction, PostItem } from '../../types';

// `@wordpress/icons` (v11.8, pinned by this workspace) has never exported an
// `edit` icon — only `pencil` — so this has always resolved to `undefined` at
// runtime and the Quick Edit action has never actually shown an icon. Kept
// as-is (not swapped for `pencil`) to avoid a visible behaviour change here;
// flagged as a likely bug to fix separately.
const edit = undefined;

const POSTS_PATH = '/wp/v2/newspack_nl_ads_cpt';

const trashOne = ( id: number ) => apiFetch( { path: `${ POSTS_PATH }/${ id }`, method: 'DELETE' } );

// Restoring an ad updates the post status back to `draft` via POST
// (the WP REST posts update verb). Unlike newsletters, ads have no
// controlled-status logic in `insert_post_data`, so the row stays as
// draft until the publisher edits and publishes it again. That's the
// intended behaviour for the ads lifecycle (date-driven activation).
const restoreOne = ( id: number ) =>
	apiFetch( {
		path: `${ POSTS_PATH }/${ id }`,
		method: 'POST',
		data: { status: 'draft' },
	} );

const deleteOne = ( id: number ) => apiFetch( { path: `${ POSTS_PATH }/${ id }?force=true`, method: 'DELETE' } );

interface GetActionsArgs {
	refresh: () => void;
	openQuickEdit: ( item: PostItem ) => void;
}

export function getActions( { refresh, openQuickEdit }: GetActionsArgs ): ListAction[] {
	const editAction: ListAction = {
		id: 'edit',
		label: __( 'Edit', 'newspack-newsletters' ),
		callback: items => {
			const item = items[ 0 ];
			if ( ! item ) {
				return;
			}
			window.location.href = `${ getAdminUrl() }post.php?post=${ item.id }&action=edit`;
		},
	};

	const quickEditAction: ListAction = {
		id: 'quick-edit',
		label: __( 'Quick Edit', 'newspack-newsletters' ),
		isPrimary: true,
		icon: edit,
		isEligible: item => ! isTrashed( item ),
		callback: items => {
			const item = items[ 0 ];
			if ( ! item || typeof openQuickEdit !== 'function' ) {
				return;
			}
			openQuickEdit( item );
		},
	};

	const renameAction: ListAction = {
		id: 'rename',
		label: __( 'Rename', 'newspack-newsletters' ),
		modalHeader: __( 'Rename', 'newspack-newsletters' ),
		modalSize: 'medium',
		isEligible: item => ! isTrashed( item ),
		RenderModal: ( { items, closeModal } ) => (
			<RenameForm
				item={ items[ 0 ] }
				postPath={ POSTS_PATH }
				fieldLabel={ __( 'Title', 'newspack-newsletters' ) }
				savedMessage={ __( 'Ad renamed.', 'newspack-newsletters' ) }
				closeModal={ closeModal }
				onSaved={ refresh }
			/>
		),
	};

	const trashAction: ListAction = {
		id: 'trash',
		label: __( 'Trash', 'newspack-newsletters' ),
		isPrimary: true,
		icon: trash,
		modalHeader: __( 'Move to trash', 'newspack-newsletters' ),
		isDestructive: true,
		supportsBulk: true,
		isEligible: item => ! isTrashed( item ),
		RenderModal: ( { items, closeModal } ) => (
			<ConfirmModal
				items={ items }
				closeModal={ closeModal }
				confirmLabel={ __( 'Move to trash', 'newspack-newsletters' ) }
				confirmingLabel={ __( 'Moving…', 'newspack-newsletters' ) }
				question={ sprintf(
					/* translators: %d: number of ads */
					_n(
						'Move %d ad to the trash? You can restore it from the Trash filter later.',
						'Move %d ads to the trash? You can restore them from the Trash filter later.',
						items.length,
						'newspack-newsletters'
					),
					items.length
				) }
				isDestructive
				onConfirm={ list =>
					runBulk( list, item => trashOne( item.id ), {
						refresh,
						successPlural: n => _n( 'Ad moved to trash.', 'Ads moved to trash.', n, 'newspack-newsletters' ),
						failurePlural: n =>
							sprintf(
								/* translators: %d: number that failed */
								_n(
									'Failed to trash %d ad. Please try again.',
									'Failed to trash %d ads. Please try again.',
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

	const restoreAction: ListAction = {
		id: 'restore',
		label: __( 'Restore', 'newspack-newsletters' ),
		supportsBulk: true,
		isEligible: isTrashed,
		callback: async items => {
			const eligible = items.filter( isTrashed );
			if ( eligible.length === 0 ) {
				return;
			}
			await runBulk( eligible, item => restoreOne( item.id ), {
				refresh,
				successPlural: n => _n( 'Ad restored.', 'Ads restored.', n, 'newspack-newsletters' ),
				failurePlural: n =>
					sprintf(
						/* translators: %d: number that failed */
						_n( 'Failed to restore %d ad.', 'Failed to restore %d ads.', n, 'newspack-newsletters' ),
						n
					),
			} );
		},
	};

	const deleteAction: ListAction = {
		id: 'delete-permanently',
		label: __( 'Delete permanently', 'newspack-newsletters' ),
		isDestructive: true,
		supportsBulk: true,
		isEligible: isTrashed,
		RenderModal: ( { items, closeModal } ) => (
			<ConfirmModal
				items={ items }
				closeModal={ closeModal }
				confirmLabel={ __( 'Delete permanently', 'newspack-newsletters' ) }
				confirmingLabel={ __( 'Deleting…', 'newspack-newsletters' ) }
				question={ sprintf(
					/* translators: %d: number of ads */
					_n(
						'Permanently delete %d ad? This cannot be undone.',
						'Permanently delete %d ads? This cannot be undone.',
						items.length,
						'newspack-newsletters'
					),
					items.length
				) }
				isDestructive
				onConfirm={ list =>
					runBulk( list, item => deleteOne( item.id ), {
						refresh,
						successPlural: n => _n( 'Ad deleted.', 'Ads deleted.', n, 'newspack-newsletters' ),
						failurePlural: n =>
							sprintf(
								/* translators: %d: number that failed */
								_n( 'Failed to delete %d ad.', 'Failed to delete %d ads.', n, 'newspack-newsletters' ),
								n
							),
					} )
				}
			/>
		),
	};

	return [ quickEditAction, trashAction, editAction, renameAction, restoreAction, deleteAction ];
}
