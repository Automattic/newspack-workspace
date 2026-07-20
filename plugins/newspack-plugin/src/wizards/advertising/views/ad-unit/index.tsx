/**
 * New/Edit Ad Unit Screen
 */

/**
 * WordPress dependencies.
 */
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { trash } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { Button, Card, Grid, Notice, TextControl, withWizardScreen } from '../../../../../packages/components/src';
import type { RenderPrimaryButton } from '../../../../../packages/components/src/with-wizard-screen';
import AdUnitSizeControl, { getSizes } from '../../components/ad-unit-size-control';
import type { AdUnit as AdUnitData, AdUnitSizeOption, AdsWizardApiFetch, GoogleAdManagerServiceData } from '../../types';

export type AdUnitViewProps = {
	adUnit: AdUnitData;
	service: string;
	serviceData?: GoogleAdManagerServiceData;
	wizardApiFetch?: AdsWizardApiFetch;
	onChange: ( adUnit: AdUnitData ) => void;
	onSave: ( id: AdUnitData[ 'id' ] ) => void;
	onCancel: () => void;
};

/**
 * New/Edit Ad Unit Screen.
 */
class AdUnit extends Component< AdUnitViewProps & { renderPrimaryButton: RenderPrimaryButton } > {
	/**
	 * Handle an update to an ad unit field.
	 *
	 * @param adUnitChangesOrKey Ad Unit field name or object containing changes.
	 * @param value              New value for field.
	 */
	handleOnChange( adUnitChangesOrKey: keyof AdUnitData | Partial< AdUnitData >, value?: AdUnitData[ keyof AdUnitData ] ) {
		const { adUnit, onChange, service } = this.props;
		if ( typeof adUnitChangesOrKey === 'string' ) {
			onChange( { ...adUnit, ad_service: service, [ adUnitChangesOrKey ]: value } );
		} else {
			onChange( { ...adUnit, ad_service: service, ...adUnitChangesOrKey } );
		}
	}

	getSizeOptions(): AdUnitSizeOption[] {
		const { adUnit } = this.props;
		const sizes = adUnit.sizes && Array.isArray( adUnit.sizes ) ? adUnit.sizes : [];
		let sizeOptions: AdUnitSizeOption[] = [ ...sizes ];
		if ( adUnit.fluid ) {
			sizeOptions = [ ...sizeOptions, 'fluid' ];
		}
		return sizeOptions;
	}

	getNextAvailableSize(): AdUnitSizeOption {
		const sizes = getSizes();
		const options = this.getSizeOptions().map( size => size.toString() );
		const index = sizes.map( size => size.toString() ).findIndex( size => ! options.includes( size ) );
		return sizes[ index ] || [ 0, 0 ];
	}

	/**
	 * Render.
	 */
	render() {
		const { adUnit, service, onSave, onCancel } = this.props;
		const { id, code, fluid = false, name = '', path = [] } = adUnit;
		const isLegacy = adUnit.is_legacy;
		const isExistingAdUnit = id !== 0;
		const sizes = adUnit.sizes && Array.isArray( adUnit.sizes ) ? adUnit.sizes : [];
		const isInvalidSize = ! fluid && sizes.length === 0;
		const sizeOptions = this.getSizeOptions();
		const getCodeValue = () => {
			if ( isLegacy ) {
				return code;
			}
			if ( ! path.length ) {
				return code;
			}
			return `${ path.map( parent => parent.code ).join( '/' ) }/${ code }`;
		};
		return (
			<>
				<Card headerActions noBorder>
					<h2>{ __( 'Ad Unit Details', 'newspack-plugin' ) }</h2>
				</Card>

				<Grid gutter={ 32 }>
					<TextControl
						label={ __( 'Name', 'newspack-plugin' ) }
						value={ name || '' }
						onChange={ ( value: string ) => this.handleOnChange( 'name', value ) }
					/>
					{ ( isExistingAdUnit || isLegacy ) && (
						<TextControl
							label={ __( 'Code', 'newspack-plugin' ) }
							value={ getCodeValue() }
							className="code"
							help={
								isLegacy
									? undefined
									: __(
											"Identifies the ad unit in the associated ad tag. Once you've created the ad unit, you can't change the code.",
											'newspack-plugin'
									  )
							}
							disabled={ ! isLegacy }
							onChange={ ( value: string ) => this.handleOnChange( 'code', value ) }
						/>
					) }
				</Grid>

				<Card headerActions noBorder>
					<h2>{ sizeOptions.length > 1 ? __( 'Ad Unit Sizes', 'newspack-plugin' ) : __( 'Ad Unit Size', 'newspack-plugin' ) }</h2>
					<Button variant="secondary" onClick={ () => this.handleOnChange( 'sizes', [ ...sizes, this.getNextAvailableSize() ] ) }>
						{ __( 'Add New Size', 'newspack-plugin' ) }
					</Button>
				</Card>

				{ isInvalidSize && (
					<Notice
						isWarning
						noticeText={ __( 'The ad unit must have at least one valid size or fluid size enabled.', 'newspack-plugin' ) }
					/>
				) }

				<Grid columns={ 4 } gutter={ 8 } className="newspack-grid__thead">
					<span>{ __( 'Size', 'newspack-plugin' ) }</span>
					<span>{ __( 'Width', 'newspack-plugin' ) }</span>
					<span>{ __( 'Height', 'newspack-plugin' ) }</span>
					<span className="screen-reader-text">{ __( 'Action', 'newspack-plugin' ) }</span>
				</Grid>

				{ sizeOptions.map( ( size, index ) => (
					<Grid columns={ 4 } gutter={ 8 } className="newspack-grid__tbody" key={ index }>
						<AdUnitSizeControl
							selectedOptions={ sizeOptions }
							value={ size }
							onChange={ value => {
								const adUnitChanges: Partial< AdUnitData > = {};
								const prevValue = sizeOptions[ index ];
								if ( prevValue === 'fluid' ) {
									adUnitChanges.fluid = false;
								}
								if ( value === 'fluid' ) {
									sizes.splice( index, 1 );
									adUnitChanges.fluid = true;
								} else {
									sizes[ index ] = value;
								}
								adUnitChanges.sizes = sizes;
								this.handleOnChange( adUnitChanges );
							} }
						/>
						<Button
							onClick={ () => {
								if ( size === 'fluid' ) {
									this.handleOnChange( 'fluid', false );
								} else {
									sizes.splice( index, 1 );
									this.handleOnChange( 'sizes', sizes );
								}
							} }
							icon={ trash }
							disabled={ sizeOptions.length <= 1 }
							label={ __( 'Delete', 'newspack-plugin' ) }
							showTooltip={ true }
						/>
					</Grid>
				) ) }

				<div className="newspack-buttons-card">
					<Button
						disabled={ name.length === 0 || ( isLegacy && code.length === 0 ) || isInvalidSize }
						variant="primary"
						onClick={ () => onSave( id ) }
					>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
					<Button variant="secondary" onClick={ () => onCancel() } href={ `#/${ service }` }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
				</div>
			</>
		);
	}
}

export default withWizardScreen< AdUnitViewProps >( AdUnit );
