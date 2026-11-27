import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { ComponentType } from 'react';

import { notifyError } from '../admin-shell/notices';
import LocalListModalComponent from '../admin-shell/screens/settings/local-list-modal';
import LocalListDeleteModal from '../admin-shell/screens/settings/local-list-delete-modal';
import type { ListKind, SubscriptionListRow } from '../admin-shell/screens/settings/types';
import { EVENTS } from './events';

/** A local or ESP list row, as passed through the open/confirm/saved events. */
type LocalList = SubscriptionListRow;

// `LocalListModal` (`src/admin-shell/screens/settings/local-list-modal.tsx`) is mid-migration
// and its `list` prop isn't typed yet; treat it as an opaque boundary here with the props
// this file passes (mirrors the sibling `LocalListDeleteModal`'s already-typed `list` prop).
interface LocalListModalProps {
	list?: LocalList | null;
	kind?: ListKind;
	onClose: () => void;
	onSaved: ( result: { list: LocalList; mode: 'add' | 'edit'; kind: ListKind } ) => void;
}
const LocalListModal = LocalListModalComponent as ComponentType< LocalListModalProps >;

/** Mounted-modal state derived from the open event. */
interface ModalState {
	mode: string;
	list: LocalList | null | undefined;
	kind: ListKind;
}

/** `detail` payloads of the document events the host listens to and emits. */
interface OpenModalDetail {
	mode?: string;
	list?: LocalList;
	kind?: string;
}
interface ConfirmDeleteDetail {
	list?: LocalList;
}
interface SavedDetail {
	list?: LocalList;
	mode?: string;
	kind?: string;
}

export default function LocalListModalHost() {
	const [ modalState, setModalState ] = useState< ModalState | null >( null );
	const [ deletePending, setDeletePending ] = useState< LocalList | null >( null );
	const [ deletingId, setDeletingId ] = useState< number | string | null | undefined >( null );

	const closeModal = useCallback( () => setModalState( null ), [] );
	const closeDelete = useCallback( () => setDeletePending( null ), [] );

	useEffect( () => {
		const handleOpen = ( event: Event ) => {
			const { mode, list, kind } = ( event as CustomEvent< OpenModalDetail > ).detail || {};
			// ESP mode is edit-only and requires a row with a db_id; bail on malformed payloads instead of mounting a modal that would crash on submit.
			if ( kind === 'esp' ) {
				if ( ! list?.db_id ) {
					return;
				}
				setModalState( { mode: 'edit', list, kind: 'esp' } );
				return;
			}
			setModalState( {
				mode: mode || 'add',
				list: mode === 'edit' ? list : null,
				kind: 'local',
			} );
		};
		const handleConfirmDelete = ( event: Event ) => {
			const list = ( event as CustomEvent< ConfirmDeleteDetail > ).detail?.list;
			if ( list ) {
				setDeletePending( list );
			}
		};
		document.addEventListener( EVENTS.OPEN_MODAL, handleOpen );
		document.addEventListener( EVENTS.OPEN_CONFIRM_DELETE, handleConfirmDelete );
		// Listeners installed; signal readiness. A sync consumer dispatch on `bridge-mounted` must land here, not before.
		window.newspackNewslettersBridgeReady = true;
		document.dispatchEvent( new CustomEvent( EVENTS.BRIDGE_MOUNTED, { detail: {} } ) );
		return () => {
			document.removeEventListener( EVENTS.OPEN_MODAL, handleOpen );
			document.removeEventListener( EVENTS.OPEN_CONFIRM_DELETE, handleConfirmDelete );
			window.newspackNewslettersBridgeReady = false;
		};
	}, [] );

	const handleSaved = useCallback( ( saved: SavedDetail ) => {
		document.dispatchEvent(
			new CustomEvent( EVENTS.LOCAL_LIST_SAVED, {
				detail: { listId: saved?.list?.db_id, mode: saved?.mode, list: saved?.list, kind: saved?.kind },
			} )
		);
	}, [] );

	const confirmDelete = useCallback( async () => {
		if ( ! deletePending ) {
			return;
		}
		const list = deletePending;
		setDeletingId( list.db_id );
		try {
			await apiFetch( {
				path: `/newspack-newsletters/v1/lists/local/${ list.db_id }`,
				method: 'DELETE',
			} );
			document.dispatchEvent( new CustomEvent( EVENTS.LOCAL_LIST_DELETED, { detail: { listId: list.db_id } } ) );
			setDeletePending( null );
		} catch ( err ) {
			notifyError( ( err as { message?: string } )?.message || __( 'Could not delete the local list.', 'newspack-newsletters' ), {
				explicitDismiss: true,
			} );
		} finally {
			setDeletingId( null );
		}
	}, [ deletePending ] );

	return (
		<>
			{ modalState && (
				<LocalListModal
					key={ `${ modalState.mode }:${ modalState.kind }:${ modalState.list?.db_id || '' }` }
					list={ modalState.list }
					kind={ modalState.kind }
					onClose={ closeModal }
					onSaved={ handleSaved }
				/>
			) }
			{ deletePending && (
				<LocalListDeleteModal
					list={ deletePending }
					onConfirm={ confirmDelete }
					onCancel={ closeDelete }
					isBusy={ deletingId === deletePending.db_id }
				/>
			) }
		</>
	);
}
