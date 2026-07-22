/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl, SelectControl } from '@wordpress/components';
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

/**
 * Build the operator dropdown options for an incoming metadata field.
 *
 * Options are primarily driven by the field's `value_type`, which the
 * integration declares (e.g. a date field shouldn't offer the "Number"
 * range operator, and a single-select field shouldn't offer it either).
 * The built-in ESP integration maps its provider field types onto these
 * value_types (number/date/datetime/select/multiselect), so those fields
 * hit the typed cases above. A field left as a plain `string` (or an
 * unrecognized value_type) falls back to the has-options heuristic:
 * enumerated fields (those the ESP returns with a fixed option set) can be
 * matched against a single value or any of several; free-form fields are
 * matched as text or as a numeric range.
 *
 * @param {Object}  field              The incoming field option object.
 * @param {string}  [field.value_type] The field's declared value type.
 * @param {boolean} field.has_options  Whether the field is enumerated.
 * @return {{label: string, value: string}[]} Operator options for a SelectControl.
 */
export const operatorOptionsForField = field => {
	switch ( field?.value_type ) {
		case 'number':
			return [ { label: __( 'Number', 'newspack-plugin' ), value: 'range' } ];
		case 'date':
		case 'datetime':
			return [ { label: __( 'Text', 'newspack-plugin' ), value: 'default' } ];
		case 'multiselect':
			return [ { label: __( 'Multiple values', 'newspack-plugin' ), value: 'list__in' } ];
		case 'select':
			return [
				{ label: __( 'Single value', 'newspack-plugin' ), value: 'default' },
				{ label: __( 'Multiple values', 'newspack-plugin' ), value: 'list__in' },
			];
		case 'boolean':
			// A boolean can't be range- or list-matched; only exact (text) match applies.
			return [ { label: __( 'Text', 'newspack-plugin' ), value: 'default' } ];
		default:
			// 'string' / unknown: fall back to the options-presence heuristic.
			return field?.has_options
				? [
						{ label: __( 'Single value', 'newspack-plugin' ), value: 'default' },
						{ label: __( 'Multiple values', 'newspack-plugin' ), value: 'list__in' },
				  ]
				: [
						{ label: __( 'Text', 'newspack-plugin' ), value: 'default' },
						{ label: __( 'Number', 'newspack-plugin' ), value: 'range' },
				  ];
	}
};

/**
 * Toggle an incoming field in or out of the enabled operator map.
 *
 * Enabling a field seeds it with the field's own default matching function
 * (falling back to `default`); disabling removes the key entirely.
 *
 * @param {Object}  currentMap                 The current { key => operator } map.
 * @param {Object}  option                     The field option object.
 * @param {string}  option.value               The field key.
 * @param {string}  [option.matching_function] The field's default operator.
 * @param {boolean} checked                    Whether the field is now enabled.
 * @return {Object} The next { key => operator } map.
 */
export const toggleField = ( currentMap, option, checked ) => {
	const next = { ...( currentMap || {} ) };
	if ( checked ) {
		next[ option.value ] = option.matching_function || 'default';
	} else {
		delete next[ option.value ];
	}
	return next;
};

/**
 * Reconcile stored operators against each field's declared `value_type`.
 *
 * A field enabled before its integration declared a `value_type` keeps whatever
 * operator was stored then (typically `default`), which may no longer be offered
 * for that type. The row already *displays* the first valid option, but when
 * that's the only option the SelectControl can never fire `onChange` to persist
 * it — leaving the label ('Number') disagreeing with the effective operator
 * ('default'/exact match). Folding the repair into the next save keeps the two
 * in step without arming the unsaved-changes guard on mere page load.
 *
 * Only enabled fields (keys present in the map) are touched.
 *
 * @param {Object}   currentMap The stored { key => operator } map.
 * @param {Object[]} options    The inbound field's option objects.
 * @return {Object} The reconciled map, or `currentMap` itself when nothing changed.
 */
export const reconcileOperators = ( currentMap, options ) => {
	const map = currentMap || {};
	const next = { ...map };
	let changed = false;
	( options || [] ).forEach( option => {
		const key = option?.value;
		if ( ! key || ! Object.prototype.hasOwnProperty.call( map, key ) ) {
			return;
		}
		const valid = operatorOptionsForField( option );
		if ( valid.some( o => o.value === map[ key ] ) ) {
			return;
		}
		const fallback = valid[ 0 ]?.value;
		if ( undefined !== fallback && fallback !== map[ key ] ) {
			next[ key ] = fallback;
			changed = true;
		}
	} );
	return changed ? next : map;
};

// Coerce a value to boolean. Values can arrive from WP options as scalar
// strings (`'1'`/`'0'`/`'true'`/`'false'`/`''`); note `Boolean( '0' )` is `true`
// in JS, so the falsy string forms are matched explicitly.
const toBool = value => ( typeof value === 'string' ? ! [ '', '0', 'false' ].includes( value.toLowerCase() ) : Boolean( value ) );

// True for a plain `{ key => value }` map (the shape `incoming_metadata_fields`
// uses), excluding arrays and null so those keep their own comparison branches.
const isMap = value => null !== value && 'object' === typeof value && ! Array.isArray( value );

// Compare two field values for equivalence. Field values are scalars
// (string/boolean), arrays of strings (metadata/checkbox lists), or a
// `{ key => operator }` map (incoming metadata fields). The backend can
// round-trip these unfaithfully — metadata arrays come back in canonical order
// (`array_intersect`), and booleans as `'1'`/`''` — so arrays are compared as
// sets, booleans are coerced, and maps are compared key-by-key, else a saved
// field would stay stuck "dirty". Maps need their own branch because reference
// equality would report a net-zero edit (toggle a field on then off, or change
// an operator and change it back) as pending, keeping the Save action and the
// unsaved-changes guard armed for semantically-unchanged settings.
const valuesMatch = ( a, b ) => {
	if ( Array.isArray( a ) && Array.isArray( b ) ) {
		return a.length === b.length && a.every( value => b.includes( value ) );
	}
	if ( typeof a === 'boolean' || typeof b === 'boolean' ) {
		return toBool( a ) === toBool( b );
	}
	if ( isMap( a ) && isMap( b ) ) {
		const keys = Object.keys( a );
		return (
			keys.length === Object.keys( b ).length && keys.every( key => Object.prototype.hasOwnProperty.call( b, key ) && a[ key ] === b[ key ] )
		);
	}
	return a === b;
};

// Remount the inner view when the integration id changes so the local draft is
// per-integration. Switching integrations (same Route, new params) and leaving
// the view both reset the draft structurally — no unmount cleanup effect needed.
export const ConfigureView = props => <ConfigureViewInner key={ props.match?.params?.integrationId } { ...props } />;

const ConfigureViewInner = ( { integrations, loading, inFlightChanges, saving, onSave, onDiscardChanges, match } ) => {
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

	// Diff against saved values, not key presence, so a revert disarms the guard.
	const hasPending = useMemo( () => {
		const keys = Object.keys( draft );
		if ( keys.length === 0 ) {
			return false;
		}
		if ( ! integration?.settings ) {
			return true;
		}
		return keys.some( key => {
			const field = integration.settings.find( f => f.key === key );
			return ! field || ! valuesMatch( field.value, draft[ key ] );
		} );
	}, [ draft, integration?.settings ] );
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

	// Save submits the draft plus, when it needs repair, a reconciled inbound
	// operator map (see reconcileOperators). Read through a ref for the same reason
	// the draft is: the Save action closure is only re-registered on a
	// hasPending/saving transition. Piggybacking on a save the user already asked
	// for avoids both a write-on-load and a guard armed by settings nobody touched.
	const buildSavePayloadRef = useRef( null );
	buildSavePayloadRef.current = () => {
		const payload = { ...draftRef.current };
		if ( ! inboundField ) {
			return payload;
		}
		const currentMap = ( inboundField.key in payload ? payload[ inboundField.key ] : inboundField.value ) || {};
		if ( ! isMap( currentMap ) ) {
			return payload;
		}
		const reconciled = reconcileOperators( currentMap, inboundField.options );
		if ( reconciled !== currentMap ) {
			payload[ inboundField.key ] = reconciled;
		}
		return payload;
	};

	// The parent clears the retry buffer on save success; drop submitted keys not
	// re-edited since. Matching the submitted value (not the server's) survives
	// backend normalization (`'' → 'NP_'`, `'5' → 5`) that equality would misread.
	const lastInFlightRef = useRef( inFlightChanges?.[ integrationId ] );
	useEffect( () => {
		const submitted = lastInFlightRef.current;
		const current = inFlightChanges?.[ integrationId ];
		lastInFlightRef.current = current;
		if ( ! submitted || current ) {
			return;
		}
		setDraft( prev => {
			const keys = Object.keys( prev );
			let changed = false;
			const next = {};
			for ( const key of keys ) {
				if ( key in submitted && valuesMatch( submitted[ key ], prev[ key ] ) ) {
					changed = true;
					continue;
				}
				next[ key ] = prev[ key ];
			}
			return changed ? next : prev;
		} );
	}, [ inFlightChanges, integrationId ] );

	// Clear the retry buffer once a reverted draft is clean, else it re-seeds the
	// stale failed edit on the next visit.
	useEffect( () => {
		if ( ! hasPending && ! integrationSaving && inFlightChanges?.[ integrationId ] ) {
			onDiscardChanges?.( integrationId );
		}
	}, [ hasPending, integrationSaving, inFlightChanges, integrationId, onDiscardChanges ] );

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
						onSave( integrationId, buildSavePayloadRef.current() ).catch( () => {} );
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
			<div className="newspack-configure-view">
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
									// Options are always { value, label, matching_function, has_options } objects
									// for this field (see class-integration.php:get_settings_config()).
									const optionValue = option.value;
									const optionLabel = option.label || option.value;
									// The stored value for this field is a { key => operator } map, not an array:
									// a key present means enabled, and its value is the chosen matching operator.
									const currentMap = getFieldValue( inboundField ) || {};
									const checked = Object.prototype.hasOwnProperty.call( currentMap, optionValue );
									const operatorOptions = operatorOptionsForField( option );
									// If the stored operator isn't among the options offered for this field's
									// current value_type (e.g. a field enabled before it declared a type), fall
									// back to the first option so the control never shows a value with no option.
									const selectedOperator = operatorOptions.some( o => o.value === currentMap[ optionValue ] )
										? currentMap[ optionValue ]
										: operatorOptions[ 0 ]?.value;
									return (
										<div className="newspack-configure-view__inbound-field" key={ optionValue }>
											<CheckboxControl
												className="newspack-checkbox-control"
												label={ optionLabel }
												checked={ checked }
												onChange={ isChecked =>
													handleFieldChange( inboundField.key, toggleField( currentMap, option, isChecked ) )
												}
											/>
											{ checked && (
												<SelectControl
													className="newspack-configure-view__inbound-operator"
													label={ __( 'Segment as', 'newspack-plugin' ) }
													hideLabelFromVision
													value={ selectedOperator }
													options={ operatorOptions }
													onChange={ operator =>
														handleFieldChange( inboundField.key, {
															...currentMap,
															[ optionValue ]: operator,
														} )
													}
												/>
											) }
										</div>
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
										<AccordionPanel key={ `${ index }-${ group.section }` } title={ group.section } defaultOpen={ index === 0 }>
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
