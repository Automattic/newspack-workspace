/**
 * WordPress dependencies.
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, Grid } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import WizardsSection from '../../../wizards-section';

const BillingFields = () => {
	const wizardData = useWizardData( 'newspack-audience/billing-fields' );
	const { updateWizardSettings, saveWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const isQuietLoading = useSelect( ( select: any ) => select( WIZARD_STORE_NAMESPACE ).isQuietLoading() ?? false, [] );

	if ( ! wizardData ) {
		return null;
	}

	const changeHandler = ( value: any ) =>
		updateWizardSettings( {
			slug: 'newspack-audience/billing-fields',
			path: [ 'billing_fields' ],
			value,
		} );

	const onSave = () =>
		saveWizardSettings( {
			slug: 'newspack-audience/billing-fields',
		} );

	const availableFields = wizardData.available_billing_fields;
	const orderNotesField = wizardData.order_notes_field;
	if ( ! availableFields || ! Object.keys( availableFields ).length ) {
		return null;
	}

	const billingFields = wizardData.billing_fields.length ? wizardData.billing_fields : Object.keys( availableFields );

	return (
		<WizardsSection
			title={ __( 'Billing Fields', 'newspack-plugin' ) }
			description={ __(
				'Configure the billing fields shown in the modal checkout form. Fields marked with (*) are required if shown. Note that for shippable products, address fields will always be shown.',
				'newspack-plugin'
			) }
			className={ isQuietLoading ? 'is-fetching' : '' }
		>
			<Grid columns={ 3 } rowGap={ 16 }>
				{ Object.keys( availableFields ).map( fieldKey => (
					<CheckboxControl
						key={ fieldKey }
						label={ availableFields[ fieldKey ].label + ( availableFields[ fieldKey ].required ? ' *' : '' ) }
						checked={ billingFields.includes( fieldKey ) }
						disabled={ fieldKey === 'billing_email' } // Email is always required.
						onChange={ () => {
							let newFields = [ ...billingFields ];
							if ( billingFields.includes( fieldKey ) ) {
								newFields = newFields.filter( field => field !== fieldKey );
							} else {
								newFields = [ ...newFields, fieldKey ];
							}
							changeHandler( newFields );
						} }
					/>
				) ) }
				{ orderNotesField && (
					<CheckboxControl
						label={ orderNotesField.label }
						checked={ billingFields.includes( 'order_comments' ) }
						onChange={ () => {
							let newFields = [ ...billingFields ];
							if ( billingFields.includes( 'order_comments' ) ) {
								newFields = newFields.filter( field => field !== 'order_comments' );
							} else {
								newFields = [ ...newFields, 'order_comments' ];
							}
							changeHandler( newFields );
						} }
					/>
				) }
			</Grid>
			<div className="newspack-buttons-card">
				<Button variant="primary" onClick={ onSave } disabled={ isQuietLoading }>
					{ isQuietLoading ? __( 'Saving…', 'newspack-plugin' ) : __( 'Save Settings', 'newspack-plugin' ) }
				</Button>
			</div>
		</WizardsSection>
	);
};

export default BillingFields;
