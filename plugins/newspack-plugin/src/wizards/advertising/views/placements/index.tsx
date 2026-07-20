/**
 * Ads Global Placements Settings.
 */

/**
 * External dependencies
 */
import classnames from 'classnames';
import isEqual from 'lodash/isEqual';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import type { APIFetchOptions } from '@wordpress/api-fetch';
import { Fragment, useState, useEffect, createPortal } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { __experimentalHStack as HStack, __experimentalVStack as VStack, Snackbar, ToggleControl } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { Button, CardForm, Grid, Notice, withWizardScreen } from '../../../../../packages/components/src';
import PlacementControl from '../../components/placement-control';
import type { AdsApiError, Bidder, Placement, PlacementData, Provider } from '../../types';

/**
 * Treat an apiFetch rejection as a REST API error shape.
 *
 * @param err The apiFetch rejection reason.
 * @return The error, as an API error shape.
 */
const asApiError = ( err: unknown ) => err as AdsApiError;

/**
 * Advertising Placements management screen.
 */
const Placements = () => {
	const [ initialized, setInitialized ] = useState( false );
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState< AdsApiError | null >( null );
	const [ providers, setProviders ] = useState< Provider[] >( [] );
	const [ editingPlacement, setEditingPlacement ] = useState< string | null >( null );
	const [ isEnabling, setIsEnabling ] = useState( false );
	const [ originalData, setOriginalData ] = useState< PlacementData | null >( null );
	const [ placements, setPlacements ] = useState< Record< string, Placement > >( {} );
	const [ bidders, setBidders ] = useState< Record< string, Bidder > >( {} );
	const [ biddersError, setBiddersError ] = useState< AdsApiError | null >( null );
	const [ notice, setNotice ] = useState< { id: number; content: string } | null >( null );

	const placementsApiFetch = async ( options: APIFetchOptions< true > ) => {
		try {
			const data = await apiFetch< Record< string, Placement > >( options );
			setPlacements( data );
			setError( null );
		} catch ( err ) {
			setError( asApiError( err ) );
		}
	};
	const handlePlacementToggle = ( placement: string ) => async ( value: boolean ) => {
		setInFlight( true );
		let success = false;
		try {
			const data = await apiFetch< Record< string, Placement > >( {
				path: `/newspack-ads/v1/placements/${ placement }`,
				method: value ? 'POST' : 'DELETE',
			} );
			setPlacements( data );
			setError( null );
			success = true;
		} catch ( err ) {
			setError( asApiError( err ) );
		}
		setInFlight( false );
		if ( success && value ) {
			setIsEnabling( true );
			setEditingPlacement( placement );
		}
		return success;
	};
	const handlePlacementChange = ( placementKey: string, hookKey?: string ) => ( value: PlacementData ) => {
		const placementData = placements[ placementKey ]?.data;
		let data = {
			...placementData,
			...value,
		};
		if ( hookKey ) {
			data = {
				...placementData,
				hooks: {
					...placementData?.hooks,
					[ hookKey ]: value,
				},
			};
		}
		setPlacements( {
			...placements,
			[ placementKey ]: {
				...placements[ placementKey ],
				data,
			},
		} );
	};
	const updatePlacement = async ( placementKey: string ) => {
		setInFlight( true );
		let success = false;
		try {
			await apiFetch( {
				path: `/newspack-ads/v1/placements/${ placementKey }`,
				method: 'POST',
				data: placements[ placementKey ].data,
			} );
			success = true;
			setError( null );
		} catch ( err ) {
			setError( asApiError( err ) );
		}
		setInFlight( false );
		return success;
	};
	const isEnabled = ( placementKey: string ) => {
		return placements[ placementKey ].data?.enabled;
	};

	// Fetch placements, providers and bidders.
	useEffect( () => {
		const fetchData = async () => {
			setInFlight( true );
			await placementsApiFetch( { path: '/newspack-ads/v1/placements' } );
			try {
				const data = await apiFetch< Provider[] >( { path: '/newspack-ads/v1/providers' } );
				setProviders( data );
			} catch ( err ) {
				setError( asApiError( err ) );
			}
			try {
				const data = await apiFetch< Record< string, Bidder > >( { path: '/newspack-ads/v1/bidders' } );
				setBidders( data );
			} catch ( err ) {
				setBiddersError( asApiError( err ) );
			}
			setInitialized( true );
			setInFlight( false );
		};
		fetchData();
	}, [] );

	const cancelEditing = async () => {
		if ( isEnabling && editingPlacement ) {
			const success = await handlePlacementToggle( editingPlacement )( false );
			if ( ! success ) {
				return;
			}
		} else if ( editingPlacement && originalData ) {
			// Revert dirty edits so other cards' hasChanges doesn't see them
			// before the silent refetch completes.
			setPlacements( {
				...placements,
				[ editingPlacement ]: {
					...placements[ editingPlacement ],
					data: originalData,
				},
			} );
		}
		setIsEnabling( false );
		setOriginalData( null );
		setEditingPlacement( null );
	};

	// Silently refetch placements data when exiting edit panel.
	useEffect( () => {
		if ( ! editingPlacement && initialized ) {
			placementsApiFetch( { path: '/newspack-ads/v1/placements' } );
		}
	}, [ editingPlacement ] );

	return (
		<Fragment>
			{ ! inFlight && ! providers.length && <Notice isWarning noticeText={ __( 'There is no provider available.', 'newspack-plugin' ) } /> }
			<Grid columns={ 12 } noMargin gutter={ 0 }>
				<h1 style={ { gridColumn: 'span 4' } }>{ __( 'Placements', 'newspack-plugin' ) }</h1>
				<VStack
					spacing={ 4 }
					style={ { gridColumn: 'span 8' } }
					className={ classnames( {
						'newspack-wizard-ads-placements': true,
						'newspack-wizard-section__is-loading': inFlight && ! Object.keys( placements ).length,
					} ) }
				>
					{ Object.keys( placements ).map( key => {
						const placement = placements[ key ];
						// Const alias, so the narrowing survives into the hooks render callback below.
						const placementHooks = placement.hooks;
						const enabled = isEnabled( key );
						const isEditing = editingPlacement === key;
						const hasChanges = isEditing && ! isEqual( placement.data, originalData );
						let hasAdUnit = true;
						if ( placement.hook_name ) {
							hasAdUnit = !! placement.data?.ad_unit;
						} else if ( placement.hooks ) {
							hasAdUnit = Object.keys( placement.hooks ).every( hookKey => !! placement.data?.hooks?.[ hookKey ]?.ad_unit );
						}

						/* translators: %s: placement name (e.g. "Sticky Footer"). */
						const editButtonLabel = sprintf( __( 'Edit %s', 'newspack-plugin' ), placement.name );
						/* translators: %s: placement name (e.g. "Sticky Footer"). */
						const cancelButtonLabel = sprintf( __( 'Cancel editing %s', 'newspack-plugin' ), placement.name );
						/* translators: %s: placement name (e.g. "Sticky Footer"). */
						const enableButtonLabel = sprintf( __( 'Enable %s', 'newspack-plugin' ), placement.name );

						return (
							<CardForm
								key={ key }
								title={ placement.name }
								description={ placement.description }
								badge={
									enabled && ! ( isEditing && isEnabling )
										? { level: 'success', text: __( 'Enabled', 'newspack-plugin' ) }
										: undefined
								}
								actions={
									enabled ? (
										<Button
											variant="tertiary"
											size="compact"
											// Load-bearing: the grid stack keeps both labels in the DOM (only `visibility: hidden`), so this aria-label is the button's only clean accessible name.
											aria-label={ isEditing ? cancelButtonLabel : editButtonLabel }
											disabled={ inFlight || ( !! editingPlacement && ! isEditing ) }
											onClick={ () => {
												if ( isEditing ) {
													cancelEditing();
												} else {
													setOriginalData( placement.data );
													setEditingPlacement( key );
												}
											} }
										>
											<span className="newspack-wizard-ads-placements__toggle-label">
												<span className={ classnames( { 'is-visible': isEditing } ) }>
													{ __( 'Cancel', 'newspack-plugin' ) }
												</span>
												<span className={ classnames( { 'is-visible': ! isEditing } ) }>
													{ __( 'Edit', 'newspack-plugin' ) }
												</span>
											</span>
										</Button>
									) : (
										<Button
											variant="secondary"
											size="compact"
											aria-label={ enableButtonLabel }
											isBusy={ inFlight }
											disabled={ inFlight || ! providers.length || !! editingPlacement }
											onClick={ () => handlePlacementToggle( key )( true ) }
										>
											{ __( 'Enable', 'newspack-plugin' ) }
										</Button>
									)
								}
								isOpen={ isEditing }
								onRequestClose={ cancelEditing }
								className={ classnames( 'newspack-wizard-ads-placement', {
									'newspack-wizard-ads-placement--enabled': enabled,
								} ) }
							>
								<VStack spacing={ 4 }>
									{ error && <Notice isError noticeText={ error.message } /> }
									{ biddersError && <Notice isWarning noticeText={ biddersError.message } /> }
									{ ( enabled || isEnabling ) && placement.hook_name && (
										<PlacementControl
											providers={ providers }
											bidders={ bidders }
											value={ placement.data }
											disabled={ inFlight }
											onChange={ handlePlacementChange( key ) }
										/>
									) }
									{ placementHooks &&
										Object.keys( placementHooks ).map( hookKey => {
											const hook = {
												hookKey,
												...placementHooks[ hookKey ],
											};
											return (
												<PlacementControl
													key={ hookKey }
													label={ hook.name + ' ' + __( 'Ad Unit', 'newspack-plugin' ) }
													providers={ providers }
													bidders={ bidders }
													value={ placement.data?.hooks ? placement.data.hooks[ hookKey ] : {} }
													disabled={ inFlight }
													onChange={ handlePlacementChange( key, hookKey ) }
												/>
											);
										} ) }
									{ placement.supports?.indexOf( 'stick_to_top' ) > -1 && (
										<ToggleControl
											label={ __( 'Stick to Top', 'newspack-plugin' ) }
											checked={ !! placement.data?.stick_to_top }
											onChange={ value => {
												setPlacements( {
													...placements,
													[ key ]: {
														...placements[ key ],
														data: {
															...placements[ key ].data,
															stick_to_top: value,
														},
													},
												} );
											} }
										/>
									) }
									<HStack justify="flex-start" spacing={ 2 }>
										<Button
											variant="primary"
											size="compact"
											isBusy={ inFlight }
											disabled={ inFlight || ( isEnabling ? ! hasAdUnit : ! hasChanges ) }
											onClick={ async () => {
												const success = await updatePlacement( key );
												if ( ! success ) {
													return;
												}
												const name = placement.name;
												setIsEnabling( false );
												setOriginalData( null );
												setEditingPlacement( null );
												// translators: %s: placement name.
												const enabledContent = sprintf( __( '%s enabled.', 'newspack-plugin' ), name );
												// translators: %s: placement name.
												const updatedContent = sprintf( __( '%s updated.', 'newspack-plugin' ), name );
												const savedContent = isEnabling ? enabledContent : updatedContent;
												setNotice( { id: Date.now(), content: savedContent } );
											} }
										>
											{ isEnabling ? __( 'Enable', 'newspack-plugin' ) : __( 'Update', 'newspack-plugin' ) }
										</Button>
										{ ! isEnabling && (
											<Button
												variant="tertiary"
												size="compact"
												isBusy={ inFlight }
												isDestructive
												disabled={ inFlight }
												onClick={ async () => {
													const name = placement.name;
													const success = await handlePlacementToggle( key )( false );
													if ( ! success ) {
														return;
													}
													setEditingPlacement( null );
													// translators: %s: placement name.
													const disabledContent = sprintf( __( '%s disabled.', 'newspack-plugin' ), name );
													setNotice( { id: Date.now(), content: disabledContent } );
												} }
											>
												{ __( 'Disable', 'newspack-plugin' ) }
											</Button>
										) }
									</HStack>
								</VStack>
							</CardForm>
						);
					} ) }
				</VStack>
			</Grid>
			{ notice &&
				createPortal(
					<div className="newspack-wizard-ads-placements__snackbar">
						<Snackbar key={ notice.id } onRemove={ () => setNotice( null ) }>
							{ notice.content }
						</Snackbar>
					</div>,
					document.getElementById( 'wpbody' ) ?? document.body
				) }
		</Fragment>
	);
};

export default withWizardScreen( Placements );
