/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Accordion, AccordionPanel, Divider, Grid, SectionHeader, useUnsavedChangesDialog } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import WizardsTab from '../../../wizards-tab';
import { SettingsField } from './settings-field';

import './configure-view.scss';

// Coerce a value to boolean. Values can arrive from WP options as scalar
// strings (`'1'`/`'0'`/`'true'`/`'false'`/`''`); note `Boolean( '0' )` is `true`
// in JS, so the falsy string forms are matched explicitly.
const toBool = value => ( typeof value === 'string' ? ! [ '', '0', 'false' ].includes( value.toLowerCase() ) : Boolean( value ) );

// Compare a draft field value against its saved server value. Field values are
// scalars (string/boolean) or arrays of strings (metadata/checkbox lists). The
// backend can round-trip these unfaithfully — metadata arrays come back in
// canonical order (`array_intersect`), and booleans as `'1'`/`''` — so arrays
// are compared as sets and booleans are coerced, else a saved field would stay
// stuck "dirty".
const valuesMatch = ( a, b ) => {
	if ( Array.isArray( a ) && Array.isArray( b ) ) {
		return a.length === b.length && a.every( value => b.includes( value ) );
	}
	if ( typeof a === 'boolean' || typeof b === 'boolean' ) {
		return toBool( a ) === toBool( b );
	}
	return a === b;
};

// Remount the inner view when the integration id changes so the local draft is
// per-integration. Switching integrations (same Route, new params) and leaving
// the view both reset the draft structurally — no unmount cleanup effect needed.
export const ConfigureView = props => <ConfigureViewInner key={ props.match?.params?.integrationId } { ...props } />;

const ConfigureViewInner = ( { integrations, loading, inFlightChanges, saving, onSave, match } ) => {
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	const integrationId = match?.params?.integrationId;
	const integration = integrations[ integrationId ];

	// The live draft is local to the view. Seeded from the request layer's retry
	// buffer so returning to an integration whose last save failed re-shows the
	// unsaved edit. Dies at unmount — that is the discard.
	const [ draft, setDraft ] = useState( () => inFlightChanges?.[ integrationId ] || {} );

	// Header Save action is registered once per hasPending/saving transition, so
	// its closure reads the latest draft through a ref rather than a stale copy.
	const draftRef = useRef( draft );
	draftRef.current = draft;

	const hasPending = Object.keys( draft ).length > 0;
	const integrationSaving = saving[ integrationId ];

	const { confirmDialog: navBlockDialog } = useUnsavedChangesDialog( {
		when: hasPending && ! integrationSaving && !! integration,
	} );

	const handleFieldChange = ( fieldKey, value ) => {
		setDraft( prev => ( { ...prev, [ fieldKey ]: value } ) );
	};

	// Split settings into groups.
	const { settingsFields, inboundField, outboundField } = useMemo( () => {
		if ( ! integration?.settings ) {
			return { settingsFields: [], inboundField: null, outboundField: null };
		}
		const settings = [];
		let inbound = null;
		let outbound = null;
		for ( const field of integration.settings ) {
			if ( field.key === 'incoming_metadata_fields' ) {
				inbound = field;
			} else if ( field.key === 'outgoing_metadata_fields' ) {
				outbound = field;
			} else {
				settings.push( field );
			}
		}
		return { settingsFields: settings, inboundField: inbound, outboundField: outbound };
	}, [ integration?.settings ] );

	// A successful save (or refetch) replaces integration.settings with the saved
	// server values. Drop draft keys that now match the server value so the draft
	// clears even when the initiating view has remounted, while genuine unsaved
	// edits (typed during the in-flight window, or a still-failing field) survive.
	useEffect( () => {
		if ( ! integration?.settings ) {
			return;
		}
		setDraft( prev => {
			const keys = Object.keys( prev );
			if ( keys.length === 0 ) {
				return prev;
			}
			let changed = false;
			const next = {};
			for ( const key of keys ) {
				const field = integration.settings.find( f => f.key === key );
				if ( field && valuesMatch( field.value, prev[ key ] ) ) {
					changed = true;
					continue;
				}
				next[ key ] = prev[ key ];
			}
			return changed ? next : prev;
		} );
	}, [ integration?.settings ] );

	// Set the static header data (name/title/description) only when the
	// integration identity changes. Avoids per-keystroke churn from
	// hasPending/saving updates feeding through SET_HEADER_DATA.
	useEffect( () => {
		if ( ! integration ) {
			return;
		}
		setHeaderData( {
			sectionName: integration.name,
			sectionTitle: integration.name,
			sectionDescription: integration.description,
		} );
	}, [ integration?.id, integration?.name, integration?.description, setHeaderData ] );

	// Update only the header actions when save state changes. The action reads
	// the draft ref so it always submits the latest edit.
	useEffect( () => {
		if ( ! integration ) {
			return;
		}
		setHeaderData( {
			actions: [
				{
					type: 'primary',
					label: __( 'Save', 'newspack-plugin' ),
					action: () => {
						// The draft clears via the reconcile effect once the parent
						// reflects the saved values; on failure the draft is retained.
						onSave( integrationId, draftRef.current ).catch( () => {} );
					},
					disabled: ! hasPending || integrationSaving,
				},
			],
		} );
	}, [ integration?.id, hasPending, integrationSaving, integrationId, onSave, setHeaderData ] );

	// Reset header data when navigating to a missing integration so the
	// previous integration's name/actions don't linger in the breadcrumb.
	const wasIntegrationMissing = useRef( false );
	useEffect( () => {
		const isMissing = ! loading && ! integration;
		if ( isMissing && ! wasIntegrationMissing.current ) {
			setHeaderData( {
				sectionName: '',
				sectionTitle: '',
				sectionDescription: '',
				actions: [],
			} );
		}
		wasIntegrationMissing.current = isMissing;
	}, [ loading, integration, setHeaderData ] );

	if ( ! loading && ! integration ) {
		return (
			<WizardsTab title={ __( 'Integration not found', 'newspack-plugin' ) }>
				<p>{ __( 'The requested integration could not be found.', 'newspack-plugin' ) }</p>
			</WizardsTab>
		);
	}

	if ( ! integration ) {
		return <WizardsTab isFetching={ loading } />;
	}

	const getFieldValue = field => {
		if ( field.key in draft ) {
			return draft[ field.key ];
		}
		return field.value;
	};

	const handleCheckboxListChange = ( fieldKey, currentValue, optionName, checked ) => {
		const selected = Array.isArray( currentValue ) ? currentValue : [];
		const newValue = checked ? [ ...selected, optionName ] : selected.filter( f => f !== optionName );
		handleFieldChange( fieldKey, newValue );
	};

	const fieldIsVisible = field => {
		if ( ! field.condition || typeof field.condition !== 'object' ) {
			return true;
		}
		const ref = settingsFields.find( f => f.key === field.condition.field );
		if ( ! ref ) {
			return true;
		}
		const refValue = getFieldValue( ref );
		// For boolean conditions, coerce both sides so a string-typed option value
		// (`'1'`/`'0'`/`''`) still matches — strict equality would otherwise hide
		// dependent fields until the parent is re-saved.
		if ( typeof field.condition.equals === 'boolean' ) {
			return toBool( refValue ) === field.condition.equals;
		}
		return refValue === field.condition.equals;
	};

	return (
		<>
			{ navBlockDialog }
			<div className={ `newspack-configure-view${ loading ? ' is-fetching' : '' }` }>
				{ /* Section 1: Settings */ }
				{ settingsFields.length > 0 && (
					<Grid columns={ 2 } gutter={ 32 }>
						<SectionHeader heading={ 2 } title={ __( 'Settings', 'newspack-plugin' ) } />
						<Grid columns={ 1 } gutter={ 24 }>
							{ settingsFields.filter( fieldIsVisible ).map( field => (
								<SettingsField
									key={ field.key }
									field={ field }
									value={ getFieldValue( field ) }
									onChange={ val => handleFieldChange( field.key, val ) }
								/>
							) ) }
						</Grid>
					</Grid>
				) }

				{ /* Section 2: Inbound */ }
				{ inboundField && (
					<>
						<Divider alignment="full-width" variant="tertiary" marginTop={ 64 } marginBottom={ 64 } />
						<Grid columns={ 2 } gutter={ 32 } noMargin>
							<SectionHeader heading={ 2 } title={ __( 'Inbound', 'newspack-plugin' ) } noMargin />
							<Grid columns={ 1 } rowGap={ 8 } noMargin>
								{ ( inboundField.options || [] ).map( option => {
									// Framework injects options as { value, label } objects
									// (see class-integration.php:get_settings_config()), but accepts bare strings
									// for backward compatibility.
									const optionValue = typeof option === 'string' ? option : option.value;
									const optionLabel = typeof option === 'string' ? option : option.label || option.value;
									const currentValue = getFieldValue( inboundField );
									const selected = Array.isArray( currentValue ) ? currentValue : [];
									return (
										<CheckboxControl
											className="newspack-checkbox-control"
											key={ optionValue }
											label={ optionLabel }
											checked={ selected.includes( optionValue ) }
											onChange={ checked => handleCheckboxListChange( inboundField.key, currentValue, optionValue, checked ) }
										/>
									);
								} ) }
							</Grid>
						</Grid>
					</>
				) }

				{ /* Section 3: Outbound */ }
				{ outboundField && (
					<>
						<Divider alignment="full-width" variant="tertiary" marginTop={ 64 } marginBottom={ 64 } />
						<Grid columns={ 2 } gutter={ 32 } noMargin>
							<SectionHeader heading={ 2 } title={ __( 'Outbound', 'newspack-plugin' ) } noMargin />
							<Accordion hideSingleTitle>
								{ ( outboundField.grouped_options || [] ).map( ( group, index ) => {
									const currentValue = getFieldValue( outboundField );
									const selected = Array.isArray( currentValue ) ? currentValue : [];
									return (
										<AccordionPanel key={ group.section } title={ group.section } defaultOpen={ index === 0 }>
											<Grid columns={ 1 } rowGap={ 8 } noMargin>
												{ group.fields.map( fieldName => (
													<CheckboxControl
														className="newspack-checkbox-control"
														key={ fieldName }
														label={ fieldName }
														checked={ selected.includes( fieldName ) }
														onChange={ checked =>
															handleCheckboxListChange( outboundField.key, currentValue, fieldName, checked )
														}
													/>
												) ) }
											</Grid>
										</AccordionPanel>
									);
								} ) }
							</Accordion>
						</Grid>
					</>
				) }
			</div>
		</>
	);
};
