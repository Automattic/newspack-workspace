/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl, SelectControl } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useMemo, useRef } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Accordion, Divider, Grid, SectionHeader } from '../../../../../packages/components/src';
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
 * For `value_type: 'string'` (or an unrecognized value_type, e.g. the
 * legacy 'boolean'), fall back to the has-options heuristic so the
 * built-in ESP integration — which only ever declares 'string' or
 * 'boolean' — is unchanged: enumerated fields (those the ESP returns
 * with a fixed option set) can be matched against a single value or any
 * of several; free-form fields are matched as text or as a numeric range.
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
			return [ { label: __( 'Text', 'newspack-plugin' ), value: 'default' } ];
		case 'multiselect':
			return [ { label: __( 'Multiple values', 'newspack-plugin' ), value: 'list__in' } ];
		case 'select':
			return [
				{ label: __( 'Single value', 'newspack-plugin' ), value: 'default' },
				{ label: __( 'Multiple values', 'newspack-plugin' ), value: 'list__in' },
			];
		default:
			// 'string' / 'boolean' / unknown: fall back to the options-presence heuristic.
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

export const ConfigureView = ( { integrations, loading, pendingChanges, saving, onFieldChange, onSave, match } ) => {
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	const integrationId = match?.params?.integrationId;
	const integration = integrations[ integrationId ];

	const hasPending = pendingChanges[ integrationId ] && Object.keys( pendingChanges[ integrationId ] ).length > 0;

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

	// Update only the header actions when save state changes.
	const integrationSaving = saving[ integrationId ];
	useEffect( () => {
		if ( ! integration ) {
			return;
		}
		setHeaderData( {
			actions: [
				{
					type: 'primary',
					label: __( 'Save', 'newspack-plugin' ),
					action: () => onSave( integrationId ),
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
		if ( pendingChanges[ integrationId ] && field.key in pendingChanges[ integrationId ] ) {
			return pendingChanges[ integrationId ][ field.key ];
		}
		return field.value;
	};

	const handleCheckboxListChange = ( fieldKey, currentValue, optionName, checked ) => {
		const selected = Array.isArray( currentValue ) ? currentValue : [];
		const newValue = checked ? [ ...selected, optionName ] : selected.filter( f => f !== optionName );
		onFieldChange( integrationId, fieldKey, newValue );
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
		// For boolean conditions, coerce both sides — values can arrive from WP options
		// as scalar strings (`'1'`/`'0'`/`'true'`/`'false'`/`''`) after migration or from
		// the REST layer, so strict equality would hide dependent fields until the parent
		// is re-saved. Note `Boolean( '0' )` is `true` in JS, so the falsy string forms
		// are matched explicitly rather than via a bare `Boolean()` cast.
		if ( typeof field.condition.equals === 'boolean' ) {
			const normalized = typeof refValue === 'string' ? ! [ '', '0', 'false' ].includes( refValue.toLowerCase() ) : Boolean( refValue );
			return normalized === field.condition.equals;
		}
		return refValue === field.condition.equals;
	};

	return (
		<WizardsTab isFetching={ loading }>
			<div className="newspack-configure-view">
				{ /* Section 1: Settings */ }
				{ settingsFields.length > 0 && (
					<Grid columns={ 2 } gutter={ 32 }>
						<SectionHeader heading={ 2 } title={ __( 'Settings', 'newspack-plugin' ) } />
						<Grid columns={ 1 } rowGap={ 16 }>
							{ settingsFields.filter( fieldIsVisible ).map( field => (
								<SettingsField
									key={ field.key }
									field={ field }
									value={ getFieldValue( field ) }
									onChange={ val => onFieldChange( integrationId, field.key, val ) }
								/>
							) ) }
						</Grid>
					</Grid>
				) }

				{ /* Section 2: Inbound */ }
				{ inboundField && (
					<>
						<Divider alignment="full-width" variant="tertiary" marginTop={ 32 } marginBottom={ 32 } />
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
									return (
										<div key={ optionValue }>
											<CheckboxControl
												className="newspack-checkbox-control"
												label={ optionLabel }
												checked={ checked }
												onChange={ isChecked =>
													onFieldChange( integrationId, inboundField.key, toggleField( currentMap, option, isChecked ) )
												}
											/>
											{ checked && (
												<SelectControl
													label={ __( 'Segment as', 'newspack-plugin' ) }
													hideLabelFromVision
													value={ currentMap[ optionValue ] }
													options={ operatorOptionsForField( option ) }
													onChange={ operator =>
														onFieldChange( integrationId, inboundField.key, {
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
						<Divider alignment="full-width" variant="tertiary" marginTop={ 32 } marginBottom={ 32 } />
						<Grid columns={ 2 } gutter={ 32 } noMargin>
							<SectionHeader heading={ 2 } title={ __( 'Outbound', 'newspack-plugin' ) } noMargin />
							<div>
								{ ( outboundField.grouped_options || [] ).map( ( group, index ) => {
									const currentValue = getFieldValue( outboundField );
									const selected = Array.isArray( currentValue ) ? currentValue : [];
									return (
										<Accordion key={ group.section } title={ group.section } defaultOpen={ index === 0 }>
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
										</Accordion>
									);
								} ) }
							</div>
						</Grid>
					</>
				) }
			</div>
		</WizardsTab>
	);
};
