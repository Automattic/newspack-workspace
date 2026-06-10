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
import { getLocalListModalExtensions } from '../../../wizard-bridge/extensions';

const LOCAL_PATH = '/newspack-newsletters/v1/lists/local';
const LISTS_PATH = '/newspack-newsletters/v1/lists';
const AUDIENCES_PATH = '/newspack-newsletters/v1/lists/audiences';

export default function LocalListModal( { list = null, kind = 'local', onClose, onSaved } ) {
	const isEsp = kind === 'esp';
	// ESP rows are edit-only — remote lists are materialised from the provider.
	const isEdit = isEsp || Boolean( list?.db_id );

	const [ title, setTitle ] = useState( list?.title || '' );
	const [ description, setDescription ] = useState( list?.description || '' );
	const [ audience, setAudience ] = useState( list?.audience || '' );
	const [ audiences, setAudiences ] = useState( [] );
	const [ audienceLabel, setAudienceLabel ] = useState( __( 'List', 'newspack-newsletters' ) );
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
		apiFetch( { path: AUDIENCES_PATH } )
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
		const options = audiences.map( a => ( { label: a.name, value: a.id } ) );
		// Empty audience means "leave wiring untouched" server-side, so only offer it when there's no wiring to leave.
		if ( ! list?.audience ) {
			return [ { label: __( 'Configure later', 'newspack-newsletters' ), value: '' }, ...options ];
		}
		return options;
	}, [ audiences, list?.audience ] );

	const submit = async event => {
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
			path = `${ LISTS_PATH }/${ list.db_id }`;
			method = 'PATCH';
			data = { title: trimmedTitle, description };
		} else if ( isEdit ) {
			path = `${ LOCAL_PATH }/${ list.db_id }`;
			method = 'PATCH';
			data = { title: trimmedTitle, description, audience };
		} else {
			path = LOCAL_PATH;
			method = 'POST';
			data = { title: trimmedTitle, description, audience };
		}

		try {
			const saved = await apiFetch( { path, method, data } );
			const ctx = { listId: saved?.db_id, list: saved, mode: isEdit ? 'edit' : 'add', kind };
			// Re-read the registry at submit time so extensions registered after the modal mounted still run.
			// `Promise.resolve().then(...)` so a sync throw inside an extension is a settled rejection, not a list-save failure.
			const results = await Promise.allSettled(
				getLocalListModalExtensions( kind ).map( ext =>
					typeof ext.onSave === 'function' ? Promise.resolve().then( () => ext.onSave( ctx ) ) : Promise.resolve()
				)
			);
			results.forEach( result => {
				if ( result.status === 'rejected' ) {
					notifyError( result.reason?.message || __( 'A modal extension failed after save.', 'newspack-newsletters' ) );
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
			setError( err?.message || fallback );
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
						{ extensions.map( ( ext, index ) => (
							<div key={ index } className="newspack-newsletters-local-list-modal__extension">
								{ typeof ext.render === 'function' ? ext.render( { list, mode: isEdit ? 'edit' : 'add', kind, isBusy } ) : null }
							</div>
						) ) }
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
