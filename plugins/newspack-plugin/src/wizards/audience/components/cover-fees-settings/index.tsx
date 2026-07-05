/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl, ToggleControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { Button, Grid, TextControl } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import WizardsSection from '../../../wizards-section';

export const CoverFeesSettings = () => {
	const settings = useWizardData( 'newspack-audience/cover-fees' );
	const { updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const isQuietLoading = useSelect( select => select( WIZARD_STORE_NAMESPACE ).isQuietLoading() ?? false, [] );

	const changeHandler = ( key, value ) =>
		updateWizardSettings( {
			slug: 'newspack-audience/cover-fees',
			path: [ key ],
			value,
		} );

	const { saveWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const onSave = () =>
		saveWizardSettings( {
			slug: 'newspack-audience/cover-fees',
		} );

	return (
		<>
			<WizardsSection
				title={ __( 'Collect transaction fees', 'newspack-plugin' ) }
				description={ __(
					'Allow readers to optionally cover transaction fees imposed by payment processors when making donations or subscribing.',
					'newspack-plugin'
				) }
				className={ isQuietLoading ? 'is-fetching' : '' }
			>
				<ToggleControl
					label={ __( 'Collect transaction fees', 'newspack-plugin' ) }
					checked={ settings.allow_covering_fees }
					disabled={ isQuietLoading }
					onChange={ () => {
						changeHandler( 'allow_covering_fees', ! settings.allow_covering_fees );
						onSave();
					} }
				/>
				<Grid rowGap={ 16 }>
					<TextControl
						type="number"
						step="0.1"
						value={ settings.fee_multiplier }
						label={ __( 'Fee multiplier', 'newspack-plugin' ) }
						onChange={ value => changeHandler( 'fee_multiplier', value ) }
						disabled={ isQuietLoading }
					/>
					<TextControl
						type="number"
						step="0.1"
						value={ settings.fee_static }
						label={ __( 'Fee static portion', 'newspack-plugin' ) }
						onChange={ value => changeHandler( 'fee_static', value ) }
						disabled={ isQuietLoading }
					/>
					<TextControl
						value={ settings.allow_covering_fees_label }
						label={ __( 'Custom message', 'newspack-plugin' ) }
						placeholder={ __( 'A message to explain the transaction fee option (optional).', 'newspack-plugin' ) }
						onChange={ value => changeHandler( 'allow_covering_fees_label', value ) }
						disabled={ isQuietLoading }
					/>
				</Grid>
				<Grid rowGap={ 16 }>
					<CheckboxControl
						label={ __( 'Cover fees by default', 'newspack-plugin' ) }
						checked={ settings.allow_covering_fees_default }
						onChange={ () => changeHandler( 'allow_covering_fees_default', ! settings.allow_covering_fees_default ) }
						help={ __( 'If enabled, the option to cover the transaction fee will be checked by default.', 'newspack-plugin' ) }
						disabled={ isQuietLoading }
					/>
					<CheckboxControl
						label={ __( 'Donations only', 'newspack-plugin' ) }
						checked={ settings.allow_covering_fees_donations_only }
						onChange={ () => changeHandler( 'allow_covering_fees_donations_only', ! settings.allow_covering_fees_donations_only ) }
						help={ __( 'If enabled, the option to cover the transaction fee will only be available for donations.', 'newspack-plugin' ) }
						disabled={ isQuietLoading }
					/>
				</Grid>
				<div className="newspack-buttons-card">
					<Button isPrimary onClick={ onSave } disabled={ isQuietLoading }>
						{ __( 'Save Settings', 'newspack-plugin' ) }
					</Button>
				</div>
			</WizardsSection>
		</>
	);
};
