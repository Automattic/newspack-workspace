/**
 * Wizards Toggle Header Card Component. Uses render props pattern to allow for custom card content that can update state in card.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment, useEffect, useState, useCallback } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Grid, Button } from '../../packages/components/src';
import WizardsActionCard from './wizards-action-card';
import WizardError from './errors/class-wizard-error';
import { useWizardApiFetch } from './hooks/use-wizard-api-fetch';

/**
 * A few helper validation callbacks. Allows consuming components to define validation callbacks by string i.e. 'isNonEmptyString'
 */
function validationErrorHandler( { setError }: { setError: ( value: WizardErrorType ) => void } ) {
	return {
		/**
		 * Check if the value is a non-empty number
		 *
		 * @param value String value to validate
		 * @return true if string is all numbers, false otherwise
		 */
		isIntegerId( value: string ) {
			const trimmedValue = value.trim();
			let errorMessage = '';
			if ( trimmedValue === '' ) {
				errorMessage = __( 'Value cannot be empty!', 'newspack-plugin' );
			} else if ( ! /^[0-9]+$/.test( trimmedValue ) ) {
				errorMessage = __( 'Value may only contain numbers!', 'newspack-plugin' );
			} else if ( trimmedValue === '0' ) {
				errorMessage = __( 'Value cannot be zero!', 'newspack-plugin' );
			}
			if ( errorMessage ) {
				setError( new WizardError( errorMessage, 'invalid_input_int_id' ) );
				return false;
			}
			return Number( trimmedValue ) > 0;
		},
		/**
		 * Check if the value is a non-empty string
		 *
		 * @param value String value to validate
		 * @return true if string is non-empty, false otherwise
		 */
		isId( value: string ) {
			const trimmedValue = value.trim();
			let errorMessage = '';
			if ( trimmedValue === '' ) {
				errorMessage = __( 'Value cannot be empty!', 'newspack-plugin' );
			} else if ( ! /^[a-zA-Z0-9]+$/.test( value.trim() ) ) {
				errorMessage = __( 'Value may only contain numbers and letters.', 'newspack-plugin' );
			}
			if ( errorMessage ) {
				setError( new WizardError( errorMessage, 'invalid_input_id' ) );
				return false;
			}
			return true;
		},
	};
}

/**
 * Wizard Toggle Header Card Component
 *
 * @param props                    Wizard Toggle Header Card Component props
 * @param props.title              Title of the card
 * @param props.description        Card description
 * @param props.namespace          Namespace for the wizard API
 * @param props.path               Path for the wizard API requests
 * @param props.defaultValue       Default value and schema for storage
 * @param props.fieldValidationMap Array of field id and validation callback
 * @param props.renderProp         Render prop for the card children
 * @param props.onToggle           Callback for the toggle
 * @param props.onChecked          Callback for the checked state
 */
const WizardsToggleHeaderCard = < T extends Record< string, any > >( {
	title,
	description,
	namespace,
	path,
	defaultValue,
	fieldValidationMap,
	renderProp,
	onToggle = ( active, data ) => ( { ...data, active } ),
	onChecked = ( data: T ) => data.active,
}: WizardsToggleHeaderCardProps< T > ) => {
	const { wizardApiFetch, isFetching, errorMessage, setError, resetError } = useWizardApiFetch( namespace );
	const [ settings, setSettings ] = useState< T >( { ...defaultValue } );
	const [ settingsUpdates, setSettingsUpdates ] = useState< T >( { ...defaultValue } );

	const fieldValidations = validationErrorHandler( { setError } );

	useEffect( () => {
		wizardApiFetch< T >(
			{ path },
			{
				onSuccess: ( res: T ) => {
					setSettings( res );
					setSettingsUpdates( res );
				},
			}
		);
	}, [] );

	const updateSettings = useCallback(
		( data: T, isToggleSave = false ) => {
			resetError();

			if ( ! isToggleSave ) {
				for ( const [ field, validate ] of fieldValidationMap ) {
					if ( validate.dependsOn ) {
						const [ [ key, value ] ] = Object.entries( validate.dependsOn );
						if ( settingsUpdates[ key as keyof T ] !== value ) {
							continue;
						}
					}
					if ( typeof validate.callback === 'string' ) {
						if ( ! fieldValidations[ validate.callback ]( settingsUpdates[ field ] ) ) {
							return;
						}
					} else if ( typeof validate.callback === 'function' ) {
						const validationError = validate.callback( settingsUpdates[ field ] );
						if ( validationError ) {
							setError( new WizardError( validationError, `invalid_${ field.toString() }` ) );
							return;
						}
					}
				}
			}

			wizardApiFetch< T >(
				{
					path,
					method: 'POST',
					data,
					updateCacheMethods: [ 'GET' ],
				},
				{
					onSuccess: ( res: T ) => {
						setSettings( res );
						setSettingsUpdates( res );
					},
				}
			);
		},
		[ fieldValidationMap, fieldValidations, resetError, settingsUpdates, wizardApiFetch ]
	);

	const renderCard = (
		renderCallback: ( a: {
			updates: T;
			settings: T;
			isFetching?: boolean;
			setSettingsUpdates: React.Dispatch< React.SetStateAction< T > >;
		} ) => React.ReactNode
	) => {
		const isChecked = onChecked( settingsUpdates );
		return (
			<WizardsActionCard
				title={ title }
				description={ isFetching && ! isChecked ? __( 'Loading…', 'newspack-plugin' ) : description }
				hasGreyHeader={ isChecked }
				actionContent={
					isChecked && (
						<Button variant="primary" disabled={ isFetching } onClick={ () => updateSettings( settingsUpdates ) }>
							{ isFetching ? __( 'Loading…', 'newspack-plugin' ) : __( 'Save Settings', 'newspack-plugin' ) }
						</Button>
					)
				}
				error={ errorMessage }
				disabled={ isFetching }
				toggleOnChange={ active => {
					updateSettings( onToggle( active, settingsUpdates ), true );
				} }
				toggleChecked={ isChecked }
			>
				{ isChecked && renderCallback( { updates: settingsUpdates, setSettingsUpdates, settings } ) }
			</WizardsActionCard>
		);
	};

	return renderCard( () => (
		<Fragment>
			<Grid noMargin rowGap={ 16 } columns={ 1 }>
				{ renderProp( { settingsUpdates, setSettingsUpdates, isFetching } ) }
			</Grid>
		</Fragment>
	) );
};

export default WizardsToggleHeaderCard;
