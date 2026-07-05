/**
 * Per-row + bulk actions for the Layouts list.
 */

import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

import { LAYOUT_CPT_SLUG } from '../../../utils/consts';
import ConfirmModal from '../../components/confirm-modal';
import { notifyError, notifySuccess } from '../../notices';
import { runBulk } from '../../utils/bulk-action';

import type { ListAction } from '../../types';
import type { LayoutItem } from './types';

const COLLECTION_PATH = `/wp/v2/${ LAYOUT_CPT_SLUG }`;

function buildEditUrl( item: LayoutItem ): string {
	const adminUrl = window.newspackNewslettersAdmin?.adminUrl || '/wp-admin/';
	return `${ adminUrl }post.php?post=${ item.id }&action=edit`;
}

const deleteOne = ( id: LayoutItem[ 'id' ] ) => apiFetch( { path: `${ COLLECTION_PATH }/${ id }?force=true`, method: 'DELETE' } );

function copyTitle( source: LayoutItem ): string {
	// `??` would pick auto-drafts' empty `title.raw` and produce "Copy of ".
	const raw = ( source?.title?.raw ?? '' ).trim();
	const rendered = ( source?.title?.rendered ?? '' ).trim();
	const sourceTitle = raw || rendered || __( 'Untitled', 'newspack-newsletters' );
	return sprintf(
		/* translators: %s: original layout title */
		__( 'Copy of %s', 'newspack-newsletters' ),
		sourceTitle
	);
}

// Synthetic `prebuilt-<n>` id has no REST counterpart, so the copy is
// built from the in-memory item; status=draft so the user can review.
async function duplicatePrebuilt( item: LayoutItem ): Promise< unknown > {
	return apiFetch( {
		path: COLLECTION_PATH,
		method: 'POST',
		data: {
			status: 'draft',
			title: copyTitle( item ),
			content: item?.content?.raw ?? '',
		},
	} );
}

async function duplicateSaved( item: LayoutItem ): Promise< unknown > {
	// Re-fetch in `context=edit` so the duplicate is robust against
	// future callers passing a leaner item shape than the list payload.
	const source = await apiFetch< LayoutItem >( { path: `${ COLLECTION_PATH }/${ item.id }?context=edit` } );
	return apiFetch( {
		path: COLLECTION_PATH,
		method: 'POST',
		data: {
			status: 'publish',
			title: copyTitle( source ),
			content: source?.content?.raw ?? '',
			meta: {
				font_header: source?.meta?.font_header ?? '',
				font_body: source?.meta?.font_body ?? '',
				background_color: source?.meta?.background_color ?? '',
				text_color: source?.meta?.text_color ?? '',
				custom_css: source?.meta?.custom_css ?? '',
				campaign_defaults: source?.meta?.campaign_defaults ?? '',
				disable_auto_ads: !! source?.meta?.disable_auto_ads,
			},
		},
	} );
}

const duplicateOne = ( item: LayoutItem ): Promise< unknown > => ( item?.is_prebuilt ? duplicatePrebuilt( item ) : duplicateSaved( item ) );

// Prebuilts are bundled JSON, shared across every site, and locked from
// every mutating action in this view.
const isUserOwned = ( item: LayoutItem ): boolean => ! item?.is_prebuilt;

interface GetActionsArgs {
	onRenameStart: ( item: LayoutItem ) => void;
	onMutated: () => void;
}

export function getActions( { onRenameStart, onMutated }: GetActionsArgs ): ListAction< LayoutItem >[] {
	const editAction: ListAction< LayoutItem > = {
		id: 'edit',
		label: __( 'Edit', 'newspack-newsletters' ),
		isPrimary: true,
		isEligible: isUserOwned,
		callback: items => {
			const item = items[ 0 ];
			if ( ! item ) {
				return;
			}
			window.location.href = buildEditUrl( item );
		},
	};

	const duplicateAction: ListAction< LayoutItem > = {
		id: 'duplicate',
		label: __( 'Duplicate', 'newspack-newsletters' ),
		callback: async items => {
			const item = items[ 0 ];
			if ( ! item ) {
				return;
			}
			try {
				await duplicateOne( item );
				onMutated();
				notifySuccess( __( 'Layout duplicated.', 'newspack-newsletters' ) );
			} catch ( error ) {
				notifyError( __( 'Failed to duplicate layout.', 'newspack-newsletters' ) );
			}
		},
	};

	const renameAction: ListAction< LayoutItem > = {
		id: 'rename',
		label: __( 'Rename', 'newspack-newsletters' ),
		isEligible: isUserOwned,
		callback: items => {
			const item = items[ 0 ];
			if ( ! item ) {
				return;
			}
			onRenameStart( item );
		},
	};

	const deleteAction: ListAction< LayoutItem > = {
		id: 'delete-permanently',
		label: __( 'Delete', 'newspack-newsletters' ),
		isDestructive: true,
		supportsBulk: true,
		isEligible: isUserOwned,
		modalSize: 'small',
		RenderModal: ( { items, closeModal } ) => (
			<ConfirmModal
				items={ items }
				closeModal={ closeModal }
				confirmLabel={ __( 'Delete permanently', 'newspack-newsletters' ) }
				confirmingLabel={ __( 'Deleting…', 'newspack-newsletters' ) }
				question={ sprintf(
					/* translators: %d: number of layouts */
					_n(
						'Permanently delete %d layout? Newsletters created from it keep their content; only the saved layout entry is removed. This cannot be undone.',
						'Permanently delete %d layouts? Newsletters created from them keep their content; only the saved layout entries are removed. This cannot be undone.',
						items.length,
						'newspack-newsletters'
					),
					items.length
				) }
				isDestructive
				onConfirm={ list =>
					runBulk( list, item => deleteOne( item.id ), {
						refresh: onMutated,
						successPlural: n => _n( 'Layout deleted.', 'Layouts deleted.', n, 'newspack-newsletters' ),
						failurePlural: n =>
							sprintf(
								/* translators: %d: number that failed */
								_n(
									'Failed to delete %d layout. Please try again.',
									'Failed to delete %d layouts. Please try again.',
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

	return [ editAction, duplicateAction, renameAction, deleteAction ];
}

/**
 * Update a layout's title. Rejects on failure so the caller can leave
 * the inline-rename UI in place for retry.
 *
 * @param id    Post id.
 * @param title New title (already trimmed).
 * @return Promise resolving to the updated post on success.
 */
export function renameLayout( id: LayoutItem[ 'id' ], title: string ): Promise< unknown > {
	return apiFetch( {
		path: `${ COLLECTION_PATH }/${ id }`,
		method: 'POST',
		data: { title },
	} );
}
