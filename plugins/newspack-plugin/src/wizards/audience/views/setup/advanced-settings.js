/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { createPortal, useEffect, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { Notice, Snackbar } from '@wordpress/components';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Button, withWizardScreen, useUnsavedChangesDialog } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import WizardsTab from '../../../wizards-tab';
import GroupLabels from '../../components/group-labels';

const DATA_STORE_KEY = 'newspack-audience/group-labels';

const AdvancedSettingsScreen = withWizardScreen( ( { children } ) => <>{ children }</> );

const toLabels = settings => ( {
	label_singular: settings.label_singular ?? '',
	label_plural: settings.label_plural ?? '',
} );

export default function AdvancedSettings( props ) {
	const settings = useWizardData( DATA_STORE_KEY );
	const isLoading = useSelect( select => select( WIZARD_STORE_NAMESPACE ).isLoading(), [] );
	const { wizardApiFetch, setAPIDataForWizard } = useDispatch( WIZARD_STORE_NAMESPACE );

	const [ labels, setLabels ] = useState( toLabels( settings ) );
	const [ inFlight, setInFlight ] = useState( false );
	const [ saveError, setSaveError ] = useState( null );
	// The legacy audience wizard has no store snackbar outlet.
	const [ savedCount, setSavedCount ] = useState( 0 );

	useEffect( () => {
		setLabels( toLabels( settings ) );
	}, [ settings.label_singular, settings.label_plural ] );

	const saved = toLabels( settings );
	// The server trims labels and deletes an override saved as empty, so untouched fields must not be sent.
	const changes = Object.fromEntries( Object.entries( labels ).filter( ( [ key, value ] ) => value.trim() !== saved[ key ] ) );
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
				setLabels( toLabels( data ) );
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
						labels={ labels }
						singularDefault={ settings.label_singular_default }
						pluralDefault={ settings.label_plural_default }
						onChange={ ( key, value ) => setLabels( current => ( { ...current, [ key ]: value } ) ) }
						disabled={ isLoading || inFlight }
					/>
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
