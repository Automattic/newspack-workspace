import apiFetch from '@wordpress/api-fetch';
import {
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { notifyError } from '../../notices';
import LocalListDeleteModal from './local-list-delete-modal';
import LocalListModal from './local-list-modal';

export default function ListsSection( { lists, isLoading, error, canAddLocal, onPatchList, onLocalListChanged } ) {
	// `null` = closed, `'add'` = create modal, `{ list, kind }` = edit modal pre-populated.
	const [ modalState, setModalState ] = useState( null );
	const [ deletingId, setDeletingId ] = useState( null );
	const [ pendingDelete, setPendingDelete ] = useState( null );
	const closeModal = () => setModalState( null );
	const cancelDelete = () => setPendingDelete( null );

	const confirmDelete = async () => {
		if ( ! pendingDelete ) {
			return;
		}
		const list = pendingDelete;
		setDeletingId( list.db_id );
		try {
			await apiFetch( {
				path: `/newspack-newsletters/v1/lists/local/${ list.db_id }`,
				method: 'DELETE',
			} );
			if ( onLocalListChanged ) {
				onLocalListChanged();
			}
			setPendingDelete( null );
		} catch ( err ) {
			notifyError( err?.message || __( 'Could not delete the local list.', 'newspack-newsletters' ) );
			setPendingDelete( null );
		} finally {
			setDeletingId( null );
		}
	};

	const handleToggleActive = async ( list, next ) => {
		try {
			await onPatchList( list.db_id, { active: next } );
		} catch ( err ) {
			notifyError( err?.message || __( 'Could not update the subscription list.', 'newspack-newsletters' ) );
		}
	};

	const handleModalSaved = () => {
		if ( onLocalListChanged ) {
			onLocalListChanged();
		}
	};

	if ( error ) {
		return (
			<Card>
				<CardHeader>
					<h2>{ __( 'Subscription lists', 'newspack-newsletters' ) }</h2>
				</CardHeader>
				<CardBody>
					<Notice status="error" isDismissible={ false }>
						{ error?.message || __( 'Could not load lists from the configured provider.', 'newspack-newsletters' ) }
					</Notice>
				</CardBody>
			</Card>
		);
	}

	if ( isLoading ) {
		return (
			<Card>
				<CardHeader>
					<h2>{ __( 'Subscription lists', 'newspack-newsletters' ) }</h2>
				</CardHeader>
				<CardBody>
					<p>{ __( 'Loading lists…', 'newspack-newsletters' ) }</p>
				</CardBody>
			</Card>
		);
	}

	const renderModal = () => {
		if ( ! modalState ) {
			return null;
		}
		if ( modalState === 'add' ) {
			return <LocalListModal key="add:local" list={ null } kind="local" onClose={ closeModal } onSaved={ handleModalSaved } />;
		}
		return (
			<LocalListModal
				key={ `edit:${ modalState.kind }:${ modalState.list?.db_id }` }
				list={ modalState.list }
				kind={ modalState.kind }
				onClose={ closeModal }
				onSaved={ handleModalSaved }
			/>
		);
	};

	if ( ! lists?.length ) {
		return (
			<Card>
				<CardHeader>
					<h2>{ __( 'Subscription lists', 'newspack-newsletters' ) }</h2>
					{ canAddLocal && (
						<Button variant="secondary" onClick={ () => setModalState( 'add' ) }>
							{ __( 'Add new local list', 'newspack-newsletters' ) }
						</Button>
					) }
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'No lists found for the configured provider yet. Save provider settings first if you have just connected.',
							'newspack-newsletters'
						) }
					</p>
				</CardBody>
				{ renderModal() }
			</Card>
		);
	}

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Subscription lists', 'newspack-newsletters' ) }</h2>
				{ canAddLocal && (
					<Button variant="secondary" onClick={ () => setModalState( 'add' ) }>
						{ __( 'Add new local list', 'newspack-newsletters' ) }
					</Button>
				) }
			</CardHeader>
			<CardBody>
				<VStack spacing={ 6 }>
					<span>{ __( 'Manage which lists are available for subscription.', 'newspack-newsletters' ) }</span>
					<VStack spacing={ 6 }>
						{ lists.map( list => {
							const isLocal = list.type === 'local';
							const needsAudience = isLocal && ! list.audience;
							const rowBusy = deletingId === list.db_id;
							return (
								<VStack key={ list.id } spacing={ 2 } className="newspack-newsletters-settings__list-row">
									<ToggleControl
										label={
											<>
												<strong>
													{ list.title || list.name || list.remote_name || __( '(unnamed list)', 'newspack-newsletters' ) }
												</strong>
												{ list.description && (
													<span className="newspack-newsletters-settings__list-description">{ list.description }</span>
												) }
											</>
										}
										checked={ !! list.active && ! needsAudience }
										onChange={ next => handleToggleActive( list, next ) }
										help={
											needsAudience
												? __( 'Configure an audience to enable this list.', 'newspack-newsletters' )
												: list.type_label || ''
										}
										disabled={ needsAudience || rowBusy }
										__nextHasNoMarginBottom
									/>
									<HStack justify="flex-start" spacing={ 2 } expanded={ false }>
										<Button
											variant="link"
											onClick={ () => setModalState( { list, kind: isLocal ? 'local' : 'esp' } ) }
											disabled={ rowBusy }
										>
											{ __( 'Edit', 'newspack-newsletters' ) }
										</Button>
										{ isLocal && (
											<Button
												variant="link"
												isDestructive
												onClick={ () => setPendingDelete( list ) }
												isBusy={ deletingId === list.db_id }
												disabled={ rowBusy }
											>
												{ __( 'Delete', 'newspack-newsletters' ) }
											</Button>
										) }
									</HStack>
								</VStack>
							);
						} ) }
					</VStack>
				</VStack>
			</CardBody>

			{ renderModal() }

			<LocalListDeleteModal
				list={ pendingDelete }
				onConfirm={ confirmDelete }
				onCancel={ cancelDelete }
				isBusy={ deletingId === pendingDelete?.db_id }
			/>
		</Card>
	);
}
