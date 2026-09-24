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
	// The legacy audience wizard has no store snackbar outlet, so success feedback is a local Snackbar.
	const [ snackbar, setSnackbar ] = useState( null );

	useEffect( () => {
		setLabels( toLabels( settings ) );
	}, [ settings.label_singular, settings.label_plural ] );

	const saved = toLabels( settings );
	const isDirty = labels.label_singular !== saved.label_singular || labels.label_plural !== saved.label_plural;

	const save = () => {
		setSaveError( null );
		setInFlight( true );
		wizardApiFetch( {
			path: `/newspack/v1/wizard/${ DATA_STORE_KEY }`,
			method: 'POST',
			data: labels,
			isLocalError: true,
			isQuietFetch: true,
		} )
			.then( data => {
				setAPIDataForWizard( { slug: DATA_STORE_KEY, data } );
				setSnackbar( __( 'Settings saved.', 'newspack-plugin' ) );
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
						defaults={ settings }
						onChange={ ( key, value ) => setLabels( current => ( { ...current, [ key ]: value } ) ) }
						disabled={ isLoading || inFlight }
					/>
				</Stack>
			</WizardsTab>
			{ snackbar &&
				createPortal(
					<div className="newspack-wizard__snackbar-list">
						<Snackbar onRemove={ () => setSnackbar( null ) }>{ snackbar }</Snackbar>
					</div>,
					document.body
				) }
		</AdvancedSettingsScreen>
	);
}
