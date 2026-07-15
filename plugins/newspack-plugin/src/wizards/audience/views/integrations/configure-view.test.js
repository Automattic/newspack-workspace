/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { ConfigureView } from './configure-view';
import { useUnsavedChangesDialog } from '../../../../../packages/components/src';
import registerWizardStore from '../../../../../packages/components/src/wizard/store';

jest.mock( '../../../../../packages/components/src', () => ( {
	Accordion: ( { children } ) => children,
	Divider: () => null,
	Grid: ( { children } ) => children,
	SectionHeader: () => null,
	useUnsavedChangesDialog: jest.fn( () => ( { confirmDialog: null, requestConfirm: jest.fn() } ) ),
} ) );
jest.mock(
	'../../../wizards-tab',
	() =>
		( { children } ) =>
			children
);

// Mocking the components barrel above bypasses the Wizard module's module-load
// side effect that registers the `newspack/wizards` @wordpress/data store, so
// ConfigureView's useDispatch( WIZARD_STORE_NAMESPACE ) needs it registered here.
registerWizardStore();

const INTEGRATION = {
	id: 'esp',
	name: 'Newsletter ESP',
	description: 'Syncs reader data with your ESP.',
	settings: [],
};

const renderConfigureView = ( { pendingChanges = {}, saving = false } = {} ) => {
	render(
		<ConfigureView
			integrations={ { esp: INTEGRATION } }
			loading={ false }
			pendingChanges={ pendingChanges }
			saving={ { esp: saving } }
			onFieldChange={ jest.fn() }
			onSave={ jest.fn() }
			match={ { params: { integrationId: 'esp' } } }
		/>
	);
};

describe( 'ConfigureView unsaved-changes guard', () => {
	beforeEach( () => {
		useUnsavedChangesDialog.mockClear();
		useUnsavedChangesDialog.mockReturnValue( { confirmDialog: null, requestConfirm: jest.fn() } );
	} );

	it( 'arms the unsaved-changes guard only while there are pending changes and no save in flight', () => {
		renderConfigureView( { pendingChanges: {}, saving: false } );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );

		renderConfigureView( { pendingChanges: { esp: { mailchimp_audience_id: 'abc123' } }, saving: false } );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: true } );

		renderConfigureView( { pendingChanges: { esp: { mailchimp_audience_id: 'abc123' } }, saving: true } );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );

	it( 'renders the guard dialog element instead of dropping it', () => {
		useUnsavedChangesDialog.mockReturnValue( {
			confirmDialog: <div data-testid="guard-dialog" />,
			requestConfirm: jest.fn(),
		} );
		renderConfigureView( { pendingChanges: { esp: { mailchimp_audience_id: 'abc123' } }, saving: false } );
		expect( screen.getByTestId( 'guard-dialog' ) ).toBeInTheDocument();
	} );
} );
