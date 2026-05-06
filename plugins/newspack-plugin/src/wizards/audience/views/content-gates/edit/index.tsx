/**
 * Content Gates edit component.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { useDispatch } from '@wordpress/data';
import { createInterpolateElement, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { commentAuthorAvatar, currencyDollar, envelope, pencil, postList, settings } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from '../consts';
import { CardSettingsGroup, Divider, Grid, Router, SectionHeader, TextControl, useConfirmDialog } from '../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import { useWizardData } from '../../../../../../packages/components/src/wizard/store/utils';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import ContentRules from './content-rules';
import Registration from './registration';
import CustomAccess from './custom-access';
import { getEditGateLayoutUrl, getGateStatus, getGateStatusBadgeLevel } from '../utils';

const { useHistory } = Router;

type ContentGateEditProps = {
	history: { push: ( path: string ) => void };
	match: { params: { id: string; type: string } };
	updateGatesData: ( gates: Gate[] ) => void;
	slug?: string;
	isNewsletter?: boolean;
};

const getContentTypeFromRules = ( rules: GateContentRule[] ): 'all' | 'custom' | undefined => {
	if ( rules.length === 0 ) {
		return undefined;
	}
	if ( rules.length !== 1 ) {
		return 'custom';
	}
	const [ rule ] = rules;
	if ( rule.slug === 'newsletters' ) {
		if ( Array.isArray( rule.value ) && rule.value.length === 0 ) {
			return 'all';
		}
		return 'custom';
	}
	if ( rule.slug !== 'post_types' || ! Array.isArray( rule.value ) ) {
		return 'custom';
	}
	if ( rule.value.length !== 1 || rule.value[ 0 ] !== 'post' ) {
		return 'custom';
	}
	return 'all';
};

const Edit = ( { match, updateGatesData, slug = AUDIENCE_CONTENT_GATES_WIZARD_SLUG, isNewsletter = false }: ContentGateEditProps ) => {
	const DEFAULT_GATE: Gate = {
		id: 0,
		title: '',
		priority: 0,
		status: 'publish',
		content_rules: isNewsletter ? [ { slug: 'newsletters', value: [] } ] : [ { slug: 'post_types', value: [ 'post' ] } ],
		registration: { active: false, metering: { enabled: false, count: 1, period: 'month' }, require_verification: false, gate_layout_id: 0 },
		custom_access: { active: false, metering: { enabled: false, count: 1, period: 'month' }, gate_layout_id: 0, access_rules: [] },
	};

	const history = useHistory();
	const { id: _id, type } = match.params;
	const id = _id ? parseInt( _id ) : 0;
	const { gates = null as unknown as Gate[] } = useWizardData( slug ) as WizardData;
	const { wizardApiFetch, isFetching, errorMessage, resetError } = useWizardApiFetch( slug );
	const { addNotice, resetNotices, setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ gate, setGate ] = useState< Gate >( ( gates && gates.find( g => g.id === id ) ) || DEFAULT_GATE ); // eslint-disable-line @typescript-eslint/no-unused-vars
	const [ title, setTitle ] = useState< string >( gate.title );
	const [ isRenaming, setIsRenaming ] = useState< boolean >( false );
	const [ isDeleting, setIsDeleting ] = useState< boolean >( false );
	const [ contentRules, setContentRules ] = useState< GateContentRule[] >( gate.content_rules );
	const [ registration, setRegistration ] = useState< Registration >( gate.registration );
	const [ customAccess, setCustomAccess ] = useState< CustomAccess >( gate.custom_access );
	const [ contentType, setContentType ] = useState< 'all' | 'custom' | undefined >( type as 'all' | 'custom' | undefined );
	const [ status, setStatus ] = useState< GateStatus >( gate.status );
	const isNew = _id === 'new' || ! id;
	const isSaving = useRef( false );
	const gatesRef = useRef< Gate[] >( gates );
	const savedCustomRules = useRef< GateContentRule[] >( gate.content_rules );

	useEffect( () => {
		if ( Array.isArray( gates ) ) {
			gatesRef.current = gates;
		}
	}, [ gates ] );

	const isDirty =
		isNew ||
		title !== gate.title ||
		JSON.stringify( contentRules ) !== JSON.stringify( gate.content_rules ) ||
		JSON.stringify( registration ) !== JSON.stringify( gate.registration ) ||
		JSON.stringify( customAccess ) !== JSON.stringify( gate.custom_access );

	const { confirmDialog: navBlockDialog } = useConfirmDialog( {
		when: isDirty && ! isSaving.current,
		message: __( 'You have unsaved changes that will be lost. Discard changes?', 'newspack-plugin' ),
		confirmButtonText: __( 'Discard changes', 'newspack-plugin' ),
		hideTitle: true,
	} );
	const { confirmDialog: deleteDialog, requestConfirm: requestDelete } = useConfirmDialog( {
		title: __( 'Are you sure?', 'newspack-plugin' ),
		confirmButtonText: __( 'Delete', 'newspack-plugin' ),
		isDestructive: true,
		message: createInterpolateElement(
			sprintf(
				// translators: %s is the gate title.
				__( 'This will <strong>permanently delete</strong> "%s" and cannot be undone.', 'newspack-plugin' ),
				gate.title
			),
			{ strong: <strong /> }
		),
	} );

	const handleCreate = useCallback(
		( redirectToLayout: '' | 'registration' | 'custom_access' = '' ) => {
			if ( isFetching ) {
				return;
			}
			isSaving.current = true;
			resetNotices();
			resetError();
			const _gate = {
				...gate,
				title,
				content_rules: contentRules,
				registration,
				custom_access: customAccess,
			};
			wizardApiFetch< Gate >(
				{
					path: `/newspack/v1/wizard/${ slug }`,
					method: 'POST',
					data: { gate: _gate },
				},
				{
					onSuccess( data ) {
						updateGatesData( [ ...gatesRef.current, { ...data } ] );
						if ( redirectToLayout !== '' ) {
							window.location.assign( getEditGateLayoutUrl( data.id, redirectToLayout ) );
						} else {
							history.push( '/content-gates' );
						}
						addNotice( {
							// translators: %s is the gate title.
							message: sprintf( __( '"%s" gate created.', 'newspack-plugin' ), title ),
							type: 'success',
							id: 'content-gate-created',
							actions: [ { label: __( 'Edit', 'newspack-plugin' ), url: `#/edit/${ data.id }` } ],
						} );
					},
					onFinally: () => {
						isSaving.current = false;
					},
				}
			);
		},
		[ gate, contentRules, registration, customAccess, status, title ]
	);

	const handleSave = useCallback( () => {
		if ( isFetching ) {
			return;
		}
		isSaving.current = true;
		resetError();
		resetNotices();
		const gateTitle = title || gate.title;
		const _gate = {
			...gate,
			title,
			content_rules: contentRules,
			registration,
			custom_access: customAccess,
			status,
		};
		wizardApiFetch< Gate >(
			{
				path: `/newspack/v1/wizard/${ slug }/${ gate.id }`,
				method: 'POST',
				data: { gate: _gate },
			},
			{
				onSuccess( data: Gate ) {
					updateGatesData( gatesRef.current.map( g => ( g.id === data.id ? data : g ) ) );
					history.push( '/content-gates' );
					addNotice( {
						message: sprintf(
							// translators: %s is the gate title.
							__( '%s gate updated.', 'newspack-plugin' ),
							gateTitle ? `"${ gateTitle }"` : __( 'Content', 'newspack-plugin' )
						),
						type: 'success',
						id: 'content-gate-updated',
					} );
				},
				onFinally: () => {
					isSaving.current = false;
				},
			}
		);
	}, [ gate, contentRules, registration, customAccess, status, title ] );

	const updateStatus = useRef< ( _status: GateStatus ) => void >();
	const handleStatusChange = ( _status: GateStatus ) => {
		if ( isFetching ) {
			return;
		}
		isSaving.current = true;
		resetError();
		resetNotices();
		const prevStatus = gate.status;
		const gateTitle = gate.title;
		const _gate = {
			...gate,
			status: _status,
		};
		wizardApiFetch< Gate >(
			{
				path: `/newspack/v1/wizard/${ slug }/${ gate.id }`,
				method: 'POST',
				data: { gate: _gate },
			},
			{
				onSuccess( data: Gate ) {
					updateGatesData( gates.map( g => ( g.id === data.id ? data : g ) ) );
					addNotice( {
						message: sprintf(
							// translators: 1: the gate title, or "Content" if we can't determine the gate title. 2: the gate status.
							__( '%1$s gate %2$s.', 'newspack-plugin' ),
							gateTitle ? `"${ gateTitle }"` : __( 'Content', 'newspack-plugin' ),
							prevStatus === 'publish' ? __( 'disabled', 'newspack-plugin' ) : __( 'enabled', 'newspack-plugin' )
						),
						type: 'success',
						id: 'content-gate-status-changed',
						actions: [ { label: __( 'Undo', 'newspack-plugin' ), onClick: () => updateStatus.current?.( prevStatus ) } ],
					} );
				},
				onFinally: () => {
					isSaving.current = false;
				},
			}
		);
	};
	updateStatus.current = handleStatusChange;

	const handleDelete = useCallback( () => {
		if ( isFetching ) {
			return;
		}
		resetError();
		resetNotices();
		setIsDeleting( true );
		wizardApiFetch(
			{
				path: `/newspack/v1/wizard/${ slug }/${ id }`,
				method: 'DELETE',
			},
			{
				onSuccess() {
					const deletedGate = gatesRef.current.find( g => g.id === id );
					const gateTitle = deletedGate?.title || title;
					const newGates = gatesRef.current.filter( g => g.id !== id );
					updateGatesData( newGates );
					history.push( `/content-gates` );
					addNotice( {
						// translators: %s is the gate title.
						message: sprintf( __( '“%s” gate deleted.', 'newspack-plugin' ), gateTitle ),
						type: 'success',
						id: 'content-gate-deleted',
					} );
				},
				onFinally() {
					setIsDeleting( false );
				},
			}
		);
	}, [ id, title, isFetching ] );

	// Load gate data.
	useEffect( () => {
		if ( isSaving.current || isFetching || isDeleting ) {
			return;
		}
		setHeaderData( {
			backNav: '#/content-gates',
			sectionName: isNew ? __( 'Add new', 'newspack-plugin' ) : __( 'Edit', 'newspack-plugin' ),
		} );
		if ( isNew ) {
			return;
		}
		const matchedGate = gates ? gates.find( g => g.id === id ) : null;
		if ( matchedGate === null || isDeleting || isFetching ) {
			return;
		}
		if ( matchedGate === undefined ) {
			addNotice( {
				// translators: %d is the content gate ID.
				message: sprintf( __( 'Content gate %d not found. Create a new gate?', 'newspack-plugin' ), id ),
				type: 'error',
				id: 'content-gate-not-found',
			} );
			setGate( DEFAULT_GATE );
			setTitle( '' );
			setContentRules( DEFAULT_GATE.content_rules );
			setRegistration( DEFAULT_GATE.registration );
			setCustomAccess( DEFAULT_GATE.custom_access );
			setStatus( 'draft' );
			setContentType( 'all' );
			history.push( `/edit/new/all` );
			return;
		}
		setGate( matchedGate );
		setTitle( matchedGate.title );
		setContentRules( matchedGate.content_rules );
		savedCustomRules.current = matchedGate.content_rules;
		setRegistration( matchedGate.registration );
		setCustomAccess( matchedGate.custom_access );
		setStatus( matchedGate.status );
		setContentType( getContentTypeFromRules( matchedGate.content_rules ) );
		resetError();
	}, [ gates, id, isDeleting, isFetching, isSaving, isNew ] );

	// Set header actions.
	useEffect( () => {
		const actions = [
			{
				type: 'primary',
				label: __( 'Save', 'newspack-plugin' ),
				action: isNew ? () => handleCreate() : handleSave,
				disabled:
					isFetching ||
					! title ||
					! contentRules.length ||
					( ! registration.active && ! customAccess.active ) ||
					( ! registration.active &&
						! customAccess.access_rules.some( ruleGroup =>
							ruleGroup.some(
								rule =>
									( Array.isArray( rule.value ) && rule.value?.length > 0 ) ||
									( ! Array.isArray( rule.value ) && rule.hasOwnProperty( 'value' ) )
							)
						) ),
			},
		];
		if ( ! isNew ) {
			actions.push( {
				type: 'more',
				label: __( 'Rename', 'newspack-plugin' ),
				action: () => setIsRenaming( true ),
				disabled: isFetching || isRenaming,
			} );
			if ( gate.status !== 'publish' ) {
				actions.push( {
					type: 'more',
					label: __( 'Activate', 'newspack-plugin' ),
					action: () => updateStatus.current?.( 'publish' ),
					disabled: isFetching,
				} );
			} else {
				actions.push( {
					type: 'more',
					label: __( 'Deactivate', 'newspack-plugin' ),
					action: () => updateStatus.current?.( 'draft' ),
					disabled: isFetching,
				} );
			}
			actions.push( {
				type: 'more',
				label: __( 'Delete', 'newspack-plugin' ),
				action: () => requestDelete( handleDelete ),
				disabled: isFetching,
				destructive: true,
			} );
		}
		setHeaderData( {
			actions,
			badges: isNew ? [] : [ { label: getGateStatus( gate.status ), level: getGateStatusBadgeLevel( gate.status ) } ],
			sectionTitle: isNew
				? sprintf(
						// translators: %s is the type of content to restrict.
						__( 'Add new %s', 'newspack-plugin' ),
						isNewsletter ? __( 'premium newsletter', 'newspack-plugin' ) : __( 'gate', 'newspack-plugin' )
				  )
				: title ||
				  sprintf(
						// translators: %s is the type of content to restrict.
						__( 'Untitled %s', 'newspack-plugin' ),
						isNewsletter ? __( 'premium newsletter', 'newspack-plugin' ) : __( 'gate', 'newspack-plugin' )
				  ),
		} );
	}, [
		contentRules.length,
		customAccess.active,
		gate.id,
		gate.status,
		handleCreate,
		handleSave,
		isFetching,
		isNew,
		isRenaming,
		registration.active,
		title,
	] );

	// Update content rules.
	useEffect( () => {
		if ( contentType === 'all' ) {
			savedCustomRules.current = contentRules;
			setContentRules( DEFAULT_GATE.content_rules );
		} else if ( contentType === 'custom' ) {
			setContentRules( savedCustomRules.current );
		}
	}, [ contentType ] );

	// Display API errors as notices.
	useEffect( () => {
		if ( errorMessage ) {
			addNotice( {
				message: errorMessage,
				type: 'error',
				id: 'content-gate-error',
			} );
		}
	}, [ errorMessage ] );

	// Update gate status.
	useEffect( () => {
		if ( ! isNew && status !== gate.status ) {
			updateStatus.current?.( status );
		}
	}, [ isNew, gate.status, status, updateStatus ] );

	return (
		<div className="newspack-content-gate__edit">
			{ navBlockDialog }
			{ deleteDialog }
			{ ( isNew || isRenaming ) && (
				<>
					<Grid columns={ 2 } gutter={ 32 }>
						<SectionHeader
							heading={ 2 }
							title={ sprintf(
								// translators: %s is the type of content to restrict.
								__( 'What should we call this %s?', 'newspack-plugin' ),
								isNewsletter ? __( 'premium newsletter', 'newspack-plugin' ) : __( 'gate', 'newspack-plugin' )
							) }
							description={ sprintf(
								// translators: %s is the type of content to restrict.
								__( 'Choose a name to help you find this %s later. It won’t be shown to readers.', 'newspack-plugin' ),
								isNewsletter ? __( 'premium newsletter', 'newspack-plugin' ) : __( 'gate', 'newspack-plugin' )
							) }
						/>
						<TextControl
							label={ sprintf(
								// translators: %s is the type of content to restrict.
								__( '%s name', 'newspack-plugin' ),
								isNewsletter ? __( 'premium newsletter', 'newspack-plugin' ) : __( 'gate', 'newspack-plugin' )
							) }
							placeholder={ sprintf(
								// translators: %s is the type of content to restrict.
								__( 'e.g. %s', 'newspack-plugin' ),
								isNewsletter ? __( 'Premium Lists', 'newspack-plugin' ) : __( 'Premium Articles', 'newspack-plugin' )
							) }
							value={ title }
							onChange={ setTitle }
							hideLabelFromVision
							__next40pxDefaultSize
						/>
					</Grid>
					<Divider alignment="full-width" variant="tertiary" />
				</>
			) }
			<Grid columns={ 2 } gutter={ 32 }>
				<SectionHeader
					heading={ 2 }
					title={ __( 'What would you like to restrict?', 'newspack-plugin' ) }
					description={ sprintf(
						// translators: 1: the type of content to restrict, 2: content or "lists".
						__( 'Choose whether to restrict all %1$s or select specific %2$s.', 'newspack-plugin' ),
						isNewsletter ? __( 'lists', 'newspack-plugin' ) : __( 'posts', 'newspack-plugin' ),
						isNewsletter ? __( 'lists', 'newspack-plugin' ) : __( 'content', 'newspack-plugin' )
					) }
				/>
				<VStack spacing={ 4 }>
					<CardSettingsGroup
						title={ sprintf(
							// translators: %s is the type of content to restrict.
							__( 'Restrict all %s', 'newspack-plugin' ),
							isNewsletter ? __( 'lists', 'newspack-plugin' ) : __( 'posts', 'newspack-plugin' )
						) }
						description={ sprintf(
							// translators: %s is the type of content to restrict.
							__( 'All %s on your site will require access.', 'newspack-plugin' ),
							isNewsletter ? __( 'lists', 'newspack-plugin' ) : __( 'posts', 'newspack-plugin' )
						) }
						icon={ isNewsletter ? envelope : postList }
						isActive={ contentType === 'all' }
						onEnable={ () => setContentType( 'all' ) }
						onHeaderClick={ () => setContentType( 'all' ) }
					/>
					<CardSettingsGroup
						title={ sprintf(
							// translators: %s is the type of content to restrict.
							__( 'Choose specific %s', 'newspack-plugin' ),
							isNewsletter ? __( 'lists', 'newspack-plugin' ) : __( 'content', 'newspack-plugin' )
						) }
						description={ sprintf(
							// translators: %s is the type of content to restrict.
							__( 'Select which %s to restrict using custom rules.', 'newspack-plugin' ),
							isNewsletter ? __( 'lists', 'newspack-plugin' ) : __( 'content', 'newspack-plugin' )
						) }
						icon={ settings }
						isActive={ contentType === 'custom' }
						onEnable={ () => setContentType( 'custom' ) }
						onHeaderClick={ () => setContentType( 'custom' ) }
					>
						<ContentRules rules={ contentRules } onChange={ setContentRules } isNewsletter={ isNewsletter } />
					</CardSettingsGroup>
				</VStack>
			</Grid>
			<Divider alignment="full-width" variant="tertiary" />
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					heading={ 2 }
					title={ sprintf(
						// translators: %s is the type of content to restrict.
						__( 'What’s required to access this %s?', 'newspack-plugin' ),
						isNewsletter ? __( 'list', 'newspack-plugin' ) : __( 'content', 'newspack-plugin' )
					) }
					description={ sprintf(
						// translators: 1: the type of content to restrict, 2: the metering description.
						__( 'Choose how readers can unlock this %1$s. Enable registered access, paid access, or both. %2$s', 'newspack-plugin' ),
						isNewsletter ? __( 'list', 'newspack-plugin' ) : __( 'content', 'newspack-plugin' ),
						isNewsletter
							? ''
							: __(
									'Each option can include metering to give readers limited free access before the restriction applies.',
									'newspack-plugin'
							  )
					) }
				/>
				<VStack spacing={ 4 }>
					{ ! isNewsletter && (
						<CardSettingsGroup
							actionType="toggle"
							title={ __( 'Registered access', 'newspack-plugin' ) }
							description={ sprintf(
								// translators: %s is the type of content to restrict.
								__( 'Readers must log in to view %s.', 'newspack-plugin' ),
								isNewsletter ? __( 'these lists', 'newspack-plugin' ) : __( 'this content', 'newspack-plugin' )
							) }
							headerAction={
								registration?.active
									? {
											label: __( 'Edit layout', 'newspack-plugin' ),
											href: ! isNew ? getEditGateLayoutUrl( gate.id, 'registration' ) : undefined,
											onClick: isNew ? () => handleCreate( 'registration' ) : undefined,
											icon: pencil,
									  }
									: undefined
							}
							icon={ commentAuthorAvatar }
							isActive={ registration?.active }
							onEnable={ () => setRegistration( { ...registration, active: ! registration.active } ) }
						>
							<Registration registration={ registration } onChange={ setRegistration } isNewsletter={ isNewsletter } />
						</CardSettingsGroup>
					) }
					<CardSettingsGroup
						actionType="toggle"
						title={ __( 'Paid access', 'newspack-plugin' ) }
						description={ __( 'Readers must meet at least one condition to gain access.', 'newspack-plugin' ) }
						headerAction={
							customAccess?.active && ! isNewsletter
								? {
										label: __( 'Edit layout', 'newspack-plugin' ),
										href: ! isNew ? getEditGateLayoutUrl( gate.id, 'custom_access' ) : undefined,
										onClick: isNew ? () => handleCreate( 'custom_access' ) : undefined,
										icon: pencil,
								  }
								: undefined
						}
						icon={ currencyDollar }
						isActive={ customAccess?.active }
						onEnable={ () => setCustomAccess( { ...customAccess, active: ! customAccess.active } ) }
					>
						<CustomAccess customAccess={ customAccess } onChange={ setCustomAccess } isNewsletter={ isNewsletter } />
					</CardSettingsGroup>
				</VStack>
			</Grid>
		</div>
	);
};
export default Edit;
