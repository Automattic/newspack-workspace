import apiFetch from '@wordpress/api-fetch';
import {
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	Button,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { notifyError } from '../../notices';
import { errorMessage } from '../../utils/errors';
import { getLocalListModalExtensions } from '../../../wizard-bridge/extensions';

import type { ReactNode } from 'react';
import type { ListKind, SubscriptionListRow } from './types';

const LOCAL_PATH = '/newspack-newsletters/v1/lists/local';
const LISTS_PATH = '/newspack-newsletters/v1/lists';
const AUDIENCES_PATH = '/newspack-newsletters/v1/lists/audiences';

interface AudienceOption {
	id: string | number;
	name: string;
}

interface AudiencesResponse {
	audiences?: AudienceOption[];
	audience_label?: string;
	help_before_save?: string;
}

/** Context passed to an extension's `render`, mirrored from `LocalListModalExtension`'s open-ended shape. */
type ExtensionRenderContext = {
	list: SubscriptionListRow | null;
	mode: 'add' | 'edit';
	kind: ListKind;
	isBusy: boolean;
};

/** Context passed to an extension's `onSave`, mirrored from `LocalListModalExtension`'s open-ended shape. */
type ExtensionSaveContext = {
	listId?: string | number;
	list: SubscriptionListRow;
	mode: 'add' | 'edit';
	kind: ListKind;
};

type ExtensionRender = ( ctx: ExtensionRenderContext ) => ReactNode;
type ExtensionOnSave = ( ctx: ExtensionSaveContext ) => unknown;

interface LocalListModalProps {
	list?: SubscriptionListRow | null;
	kind?: ListKind;
	onClose: () => void;
	onSaved: ( result: { list: SubscriptionListRow; mode: 'add' | 'edit'; kind: ListKind } ) => void;
}

export default function LocalListModal( { list = null, kind = 'local', onClose, onSaved }: LocalListModalProps ) {
	const isEsp = kind === 'esp';
	// ESP rows are edit-only — remote lists are materialised from the provider.
	const isEdit = isEsp || Boolean( list?.db_id );

	const [ title, setTitle ] = useState( list?.title || '' );
	const [ description, setDescription ] = useState( list?.description || '' );
	const [ audience, setAudience ] = useState( list?.audience || '' );
	const [ audiences, setAudiences ] = useState< AudienceOption[] >( [] );
	const [ audienceLabel, setAudienceLabel ] = useState< string >( __( 'List', 'newspack-newsletters' ) );
	const [ audienceHelp, setAudienceHelp ] = useState( '' );
	const [ audiencesLoaded, setAudiencesLoaded ] = useState( false );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const extensions = getLocalListModalExtensions( kind );

	useEffect( () => {
		if ( isEsp ) {
			setAudiencesLoaded( true );
			return undefined;
		}
		let cancelled = false;
		apiFetch< AudiencesResponse >( { path: AUDIENCES_PATH } )
			.then( payload => {
				if ( cancelled ) {
					return;
				}
				setAudiences( Array.isArray( payload?.audiences ) ? payload.audiences : [] );
				if ( payload?.audience_label ) {
					setAudienceLabel( payload.audience_label );
				}
				if ( payload?.help_before_save ) {
					setAudienceHelp( payload.help_before_save );
				}
			} )
			.catch( () => {
				/* leave audiences empty — modal still works without the picker */
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setAudiencesLoaded( true );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ isEsp ] );

	const audienceOptions = useMemo( () => {
		const options = audiences.map( a => ( { label: a.name, value: String( a.id ) } ) );
		// Empty audience means "leave wiring untouched" server-side, so only offer it when there's no wiring to leave.
		if ( ! list?.audience ) {
			return [ { label: __( 'Configure later', 'newspack-newsletters' ), value: '' }, ...options ];
		}
		return options;
	}, [ audiences, list?.audience ] );

	const submit = async ( event: React.FormEvent ) => {
		event.preventDefault();

		const trimmedTitle = title.trim();
		if ( ! trimmedTitle ) {
			setError( __( 'List title is required.', 'newspack-newsletters' ) );
			return;
		}

		if ( isEsp && ! list?.db_id ) {
			setError( __( 'Missing list reference.', 'newspack-newsletters' ) );
			return;
		}

		setIsBusy( true );
		setError( '' );

		let path;
		let method;
		let data;
		if ( isEsp ) {
			// Reachable only once the `! list?.db_id` guard above has passed.
			path = `${ LISTS_PATH }/${ list!.db_id }`;
			method = 'PATCH';
			data = { title: trimmedTitle, description };
		} else if ( isEdit ) {
			// `isEdit` is `isEsp || Boolean(list?.db_id)`; the `isEsp` branch is handled above, so here it's the `db_id` disjunct.
			path = `${ LOCAL_PATH }/${ list!.db_id }`;
			method = 'PATCH';
			data = { title: trimmedTitle, description, audience };
		} else {
			path = LOCAL_PATH;
			method = 'POST';
			data = { title: trimmedTitle, description, audience };
		}

		try {
			const saved = await apiFetch< SubscriptionListRow >( { path, method, data } );
			const ctx: ExtensionSaveContext = { listId: saved?.db_id, list: saved, mode: isEdit ? 'edit' : 'add', kind };
			// Re-read the registry at submit time so extensions registered after the modal mounted still run.
			// `Promise.resolve().then(...)` so a sync throw inside an extension is a settled rejection, not a list-save failure.
			const results = await Promise.allSettled(
				getLocalListModalExtensions( kind ).map( ext => {
					const onSave = ext.onSave as ExtensionOnSave | undefined;
					return typeof onSave === 'function' ? Promise.resolve().then( () => onSave( ctx ) ) : Promise.resolve();
				} )
			);
			results.forEach( result => {
				if ( result.status === 'rejected' ) {
					notifyError( errorMessage( result.reason ) || __( 'A modal extension failed after save.', 'newspack-newsletters' ) );
				}
			} );
			onSaved( { list: saved, mode: isEdit ? 'edit' : 'add', kind } );
			onClose();
		} catch ( err ) {
			let fallback;
			if ( isEsp ) {
				fallback = __( 'Could not update subscription list. Please try again.', 'newspack-newsletters' );
			} else if ( isEdit ) {
				fallback = __( 'Could not update local list. Please try again.', 'newspack-newsletters' );
			} else {
				fallback = __( 'Could not create local list. Please try again.', 'newspack-newsletters' );
			}
			setError( errorMessage( err ) || fallback );
			setIsBusy( false );
		}
	};

	let modalTitle;
	if ( isEsp ) {
		modalTitle = __( 'Edit subscription list', 'newspack-newsletters' );
	} else if ( isEdit ) {
		modalTitle = __( 'Edit local list', 'newspack-newsletters' );
	} else {
		modalTitle = __( 'Add new local list', 'newspack-newsletters' );
	}

	return (
		<Modal
			title={ modalTitle }
			onRequestClose={ isBusy ? () => {} : onClose }
			shouldCloseOnEsc={ ! isBusy }
			shouldCloseOnClickOutside={ ! isBusy }
			size="medium"
			className="newspack-newsletters-local-list-modal"
		>
			{ ! audiencesLoaded ? (
				<HStack justify="center" style={ { minHeight: 200 } }>
					<Spinner />
				</HStack>
			) : (
				<form onSubmit={ submit }>
					<VStack spacing={ 4 }>
						{ error && (
							<Notice status="error" isDismissible={ false }>
								{ error }
							</Notice>
						) }
						<TextControl
							label={ __( 'List title', 'newspack-newsletters' ) }
							value={ title }
							onChange={ setTitle }
							required
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextareaControl
							label={ __( 'List description', 'newspack-newsletters' ) }
							help={ __( 'Optional description for this list.', 'newspack-newsletters' ) }
							value={ description }
							onChange={ setDescription }
							__nextHasNoMarginBottom
						/>
						{ ! isEsp && audiencesLoaded && audiences.length > 0 && (
							<SelectControl
								label={ audienceLabel }
								value={ audience }
								options={ audienceOptions }
								onChange={ setAudience }
								help={ audienceHelp }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						) }
						{ extensions.map( ( ext, index ) => {
							const render = ext.render as ExtensionRender | undefined;
							return (
								<div key={ index } className="newspack-newsletters-local-list-modal__extension">
									{ typeof render === 'function' ? render( { list, mode: isEdit ? 'edit' : 'add', kind, isBusy } ) : null }
								</div>
							);
						} ) }
						<HStack justify="flex-end" spacing={ 2 }>
							<Button variant="tertiary" onClick={ onClose } disabled={ isBusy }>
								{ __( 'Cancel', 'newspack-newsletters' ) }
							</Button>
							<Button variant="primary" type="submit" isBusy={ isBusy } disabled={ isBusy }>
								{ isEdit ? __( 'Save changes', 'newspack-newsletters' ) : __( 'Add list', 'newspack-newsletters' ) }
							</Button>
						</HStack>
					</VStack>
				</form>
			) }
		</Modal>
	);
}
