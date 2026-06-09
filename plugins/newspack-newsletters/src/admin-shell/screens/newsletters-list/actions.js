/**
 * Per-row + bulk actions for the Newsletters list.
 *
 * Status transitions stay in the editor — the service-provider base
 * class fires an ESP send on `transition_post_status` to publish or
 * private, so bulk publishing here would dispatch irreversibly.
 */

import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { edit, trash } from '@wordpress/icons';

import { getAdminUrl } from '../../admin-globals';
import ConfirmModal from '../../components/confirm-modal';
import RenameForm from '../../components/rename-form';
import { runBulk } from '../../utils/bulk-action';
import { isTrashed } from './status-label';

const POSTS_PATH = '/wp/v2/newspack_nl_cpt';

const trashOne = id => apiFetch( { path: `${ POSTS_PATH }/${ id }`, method: 'DELETE' } );

// PATCH `status: 'draft'` for every restore — the actual landing
// status depends on whether the newsletter has already been sent:
//
//   - Never-sent: `Newspack_Newsletters_Service_Provider::insert_post_data`
//     leaves `draft` alone, so the row restores as a draft.
//   - Already-sent: the same filter forces the row back to its
//     controlled status (`publish` if `is_public`, otherwise `private`)
//     so a sent newsletter cannot accidentally land in draft.
//
// Both branches are safe vs the ESP-send guard: the provider's send
// only fires when transitioning INTO publish/private from a non-sent
// state, and `is_newsletter_sent()` short-circuits the second case.
// We expose this as plain "Restore" — naming it "Restore as draft"
// would mislead users because that's only true for never-sent rows.
const restoreOne = id =>
	apiFetch( {
		path: `${ POSTS_PATH }/${ id }`,
		method: 'POST',
		data: { status: 'draft' },
	} );

const deleteOne = id => apiFetch( { path: `${ POSTS_PATH }/${ id }?force=true`, method: 'DELETE' } );

// Toggling `is_public` on a `publish`/`private` newsletter goes
// through `Newspack_Newsletters_Service_Provider::updated_post_meta`,
// which calls `wp_update_post` and **does** fire
// `transition_post_status` (between `publish` and `private`). Toggling
// it on a draft leaves the status alone entirely. Either way the
// provider's send guard sees `is_newsletter_sent()` truthy on
// already-published rows and skips dispatch — so this action is safe
// from re-sending campaigns, but it is *not* purely meta-only.
const setIsPublic = ( id, isPublic ) =>
	apiFetch( {
		path: `${ POSTS_PATH }/${ id }`,
		method: 'POST',
		data: { meta: { is_public: !! isPublic } },
	} );

// Eligibility predicates extracted to constants so each non-modal bulk
// callback can re-apply them to the selection. DataViews only filters
// by `isEligible` automatically for **modal** bulk actions; plain
// callback bulk actions get the full selected set, so without this
// guard a user who selected a trashed + scheduled row would unschedule
// the latter when they hit "Restore".
const isMakePublicEligible = item => ! isTrashed( item ) && ! item?.meta?.is_public;
const isMakeNonPublicEligible = item => ! isTrashed( item ) && !! item?.meta?.is_public;

const visibilitySuccess = n =>
	sprintf(
		/* translators: %d: number of newsletters updated */
		_n( 'Visibility updated for %d newsletter.', 'Visibility updated for %d newsletters.', n, 'newspack-newsletters' ),
		n
	);

const visibilityFailure = n =>
	sprintf(
		/* translators: %d: number of newsletters that failed */
		_n( 'Failed to update visibility for %d newsletter.', 'Failed to update visibility for %d newsletters.', n, 'newspack-newsletters' ),
		n
	);

export function getActions( { refresh, openQuickEdit } ) {
	const editAction = {
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

	const quickEditAction = {
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

	const renameAction = {
		id: 'rename',
		label: __( 'Rename', 'newspack-newsletters' ),
		modalHeader: __( 'Rename', 'newspack-newsletters' ),
		modalSize: 'medium',
		isEligible: item => ! isTrashed( item ),
		RenderModal: ( { items, closeModal } ) => (
			<RenameForm
				item={ items[ 0 ] }
				postPath={ POSTS_PATH }
				fieldLabel={ __( 'Subject', 'newspack-newsletters' ) }
				savedMessage={ __( 'Newsletter renamed.', 'newspack-newsletters' ) }
				closeModal={ closeModal }
				onSaved={ refresh }
			/>
		),
	};

	const viewAction = {
		id: 'view-public-page',
		label: __( 'View public page', 'newspack-newsletters' ),
		// `is_public` and a REST `link` are not enough — drafts/scheduled/
		// private rows can carry both but have no live public-facing page.
		// Only `publish` posts are publicly viewable; private rows are
		// admin-only even with `is_public` momentarily out of sync.
		isEligible: item => 'publish' === item?.status && !! item?.link,
		callback: items => {
			const item = items[ 0 ];
			if ( item?.link ) {
				window.open( item.link, '_blank', 'noopener' );
			}
		},
	};

	const makePublicAction = {
		id: 'make-public',
		label: __( 'Set visibility to Email and web', 'newspack-newsletters' ),
		supportsBulk: true,
		// Hide on already-public rows and on trashed rows; nothing to do
		// in the first case, dangerous-feeling in the second.
		isEligible: isMakePublicEligible,
		callback: async items => {
			const eligible = items.filter( isMakePublicEligible );
			if ( eligible.length === 0 ) {
				return;
			}
			await runBulk( eligible, item => setIsPublic( item.id, true ), {
				refresh,
				successPlural: visibilitySuccess,
				failurePlural: visibilityFailure,
			} );
		},
	};

	const makeNonPublicAction = {
		id: 'make-non-public',
		label: __( 'Set visibility to Email only', 'newspack-newsletters' ),
		supportsBulk: true,
		isEligible: isMakeNonPublicEligible,
		callback: async items => {
			const eligible = items.filter( isMakeNonPublicEligible );
			if ( eligible.length === 0 ) {
				return;
			}
			await runBulk( eligible, item => setIsPublic( item.id, false ), {
				refresh,
				successPlural: visibilitySuccess,
				failurePlural: visibilityFailure,
			} );
		},
	};

	const trashAction = {
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
					/* translators: %d: number of newsletters */
					_n(
						'Move %d newsletter to the trash? You can restore it from the Trash filter later.',
						'Move %d newsletters to the trash? You can restore them from the Trash filter later.',
						items.length,
						'newspack-newsletters'
					),
					items.length
				) }
				isDestructive
				onConfirm={ list =>
					runBulk( list, item => trashOne( item.id ), {
						refresh,
						successPlural: n => _n( 'Newsletter moved to trash.', 'Newsletters moved to trash.', n, 'newspack-newsletters' ),
						failurePlural: n =>
							sprintf(
								/* translators: %d: number that failed */
								_n(
									'Failed to trash %d newsletter. Please try again.',
									'Failed to trash %d newsletters. Please try again.',
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

	const restoreAction = {
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
				successPlural: n => _n( 'Newsletter restored.', 'Newsletters restored.', n, 'newspack-newsletters' ),
				failurePlural: n =>
					sprintf(
						/* translators: %d: number that failed */
						_n( 'Failed to restore %d newsletter.', 'Failed to restore %d newsletters.', n, 'newspack-newsletters' ),
						n
					),
			} );
		},
	};

	const deleteAction = {
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
					/* translators: %d: number of newsletters */
					_n(
						'Permanently delete %d newsletter? This cannot be undone.',
						'Permanently delete %d newsletters? This cannot be undone.',
						items.length,
						'newspack-newsletters'
					),
					items.length
				) }
				isDestructive
				onConfirm={ list =>
					runBulk( list, item => deleteOne( item.id ), {
						refresh,
						successPlural: n => _n( 'Newsletter deleted.', 'Newsletters deleted.', n, 'newspack-newsletters' ),
						failurePlural: n =>
							sprintf(
								/* translators: %d: number that failed */
								_n( 'Failed to delete %d newsletter.', 'Failed to delete %d newsletters.', n, 'newspack-newsletters' ),
								n
							),
					} )
				}
			/>
		),
	};

	return [ quickEditAction, trashAction, makePublicAction, makeNonPublicAction, editAction, renameAction, viewAction, restoreAction, deleteAction ];
}
