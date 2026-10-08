/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { createPortal, useEffect, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { Notice, Snackbar, ToggleControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Button, Grid, SectionHeader, withWizardScreen, useUnsavedChangesDialog } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import WizardsTab from '../../../wizards-tab';
import GroupLabels from '../../components/group-labels';

const DATA_STORE_KEY = 'newspack-audience/group-labels';

const AdvancedSettingsScreen = withWizardScreen( ( { children } ) => <>{ children }</> );

const toSettings = settings => ( {
	label_singular: settings.label_singular ?? '',
	label_plural: settings.label_plural ?? '',
	name_from_billing: !! settings.name_from_billing,
} );

// The server trims labels and deletes an override saved as empty, so a label only counts as changed once trimmed.
const isChanged = ( value, savedValue ) => ( 'string' === typeof value ? value.trim() : value ) !== savedValue;

export default function AdvancedSettings( props ) {
	const settings = useWizardData( DATA_STORE_KEY );
	const isLoading = useSelect( select => select( WIZARD_STORE_NAMESPACE ).isLoading(), [] );
	const { wizardApiFetch, setAPIDataForWizard } = useDispatch( WIZARD_STORE_NAMESPACE );

	const [ values, setValues ] = useState( toSettings( settings ) );
	const [ inFlight, setInFlight ] = useState( false );
	const [ saveError, setSaveError ] = useState( null );
	// The legacy audience wizard has no store snackbar outlet.
	const [ savedCount, setSavedCount ] = useState( 0 );

	useEffect( () => {
		setValues( toSettings( settings ) );
	}, [ settings.label_singular, settings.label_plural, settings.name_from_billing ] );

	const saved = toSettings( settings );
	// Send only edited fields, so a stale value for an untouched field never overwrites a newer saved one.
	const changes = Object.fromEntries( Object.entries( values ).filter( ( [ key, value ] ) => isChanged( value, saved[ key ] ) ) );
	const isDirty = Object.keys( changes ).length > 0;

	const save = () => {
		setSaveError( null );
		setInFlight( true );
		wizardApiFetch( {
			path: `/newspack/v1/wizard/${ DATA_STORE_KEY }`,
			method: 'POST',
			data: changes,
			isLocalError: true,
			isQuietFetch: true,
		} )
			.then( data => {
				setAPIDataForWizard( { slug: DATA_STORE_KEY, data } );
				setValues( toSettings( data ) );
				setSavedCount( count => count + 1 );
			} )
			.catch( setSaveError )
			.finally( () => setInFlight( false ) );
	};

	const { confirmDialog } = useUnsavedChangesDialog( { when: isDirty } );

	const headerActions = (
		<Button variant="primary" onClick={ save } disabled={ isLoading || inFlight || ! isDirty }>
			{ __( 'Save', 'newspack-plugin' ) }
		</Button>
	);

	return (
		<AdvancedSettingsScreen { ...props } headerActions={ headerActions }>
			{ confirmDialog }
			<WizardsTab isFetching={ inFlight }>
				<Stack direction="column" gap="2xl">
					{ saveError && (
						<Notice status="error" isDismissible={ false }>
							{ saveError.message }
						</Notice>
					) }
					<GroupLabels
						labels={ values }
						singularDefault={ settings.label_singular_default }
						pluralDefault={ settings.label_plural_default }
						onChange={ ( key, value ) => setValues( current => ( { ...current, [ key ]: value } ) ) }
						disabled={ isLoading || inFlight }
					/>
					<Grid columns={ 2 } gutter={ 32 } noMargin>
						<SectionHeader
							heading={ 2 }
							title={ __( 'Group Names', 'newspack-plugin' ) }
							description={ __( 'Choose how a group bought at checkout is named.', 'newspack-plugin' ) }
							noMargin
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( "Name new groups after the buyer's company or name", 'newspack-plugin' ) }
							help={ sprintf(
								/* translators: %s: example group name built from a buyer's name and the singular label, e.g. "Jane Doe's Group". */
								__(
									'Uses the billing company, or a name like "%s" when there is none. Groups that already have a name keep it. When off, new groups show the product name.',
									'newspack-plugin'
								),
								sprintf(
									/* translators: 1: buyer's full name, 2: group label, e.g. "Group". */
									__( "%1$s's %2$s", 'newspack-plugin' ),
									'Jane Doe',
									values.label_singular.trim() || settings.label_singular_default || __( 'Group', 'newspack-plugin' )
								)
							) }
							checked={ values.name_from_billing }
							onChange={ value => setValues( current => ( { ...current, name_from_billing: value } ) ) }
							disabled={ isLoading || inFlight }
						/>
					</Grid>
				</Stack>
			</WizardsTab>
			{ savedCount > 0 &&
				createPortal(
					<div className="newspack-wizard__snackbar-list">
						<Snackbar key={ savedCount } onRemove={ () => setSavedCount( 0 ) }>
							{ __( 'Settings saved.', 'newspack-plugin' ) }
						</Snackbar>
					</div>,
					document.body
				) }
		</AdvancedSettingsScreen>
	);
}
