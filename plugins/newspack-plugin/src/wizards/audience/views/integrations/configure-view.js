/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';
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

	return (
		<WizardsTab isFetching={ loading }>
			<div className="newspack-configure-view">
				{ /* Section 1: Settings */ }
				{ settingsFields.length > 0 && (
					<Grid columns={ 2 } gutter={ 32 }>
						<SectionHeader heading={ 2 } title={ __( 'Settings', 'newspack-plugin' ) } />
						<Grid columns={ 1 } rowGap={ 16 }>
							{ settingsFields.map( field => (
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
