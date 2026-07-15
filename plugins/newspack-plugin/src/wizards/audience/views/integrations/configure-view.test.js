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

const renderConfigureView = ( { integrations = { esp: INTEGRATION }, pendingChanges = {}, saving = false, onDiscardChanges = jest.fn() } = {} ) =>
	render(
		<ConfigureView
			integrations={ integrations }
			loading={ false }
			pendingChanges={ pendingChanges }
			saving={ { esp: saving } }
			onFieldChange={ jest.fn() }
			onDiscardChanges={ onDiscardChanges }
			onSave={ jest.fn() }
			match={ { params: { integrationId: 'esp' } } }
		/>
	);

describe( 'ConfigureView unsaved-changes guard', () => {
	beforeEach( () => {
		useUnsavedChangesDialog.mockClear();
		useUnsavedChangesDialog.mockReturnValue( { confirmDialog: null, requestConfirm: jest.fn() } );
	} );

	it( 'does not arm the guard when there are no pending changes and no save in flight', () => {
		renderConfigureView( { pendingChanges: {}, saving: false } );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );

	it( 'arms the guard while there are pending changes and no save in flight', () => {
		renderConfigureView( { pendingChanges: { esp: { mailchimp_audience_id: 'abc123' } }, saving: false } );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: true } );
	} );

	it( 'does not arm the guard while a save is in flight, even with pending changes', () => {
		renderConfigureView( { pendingChanges: { esp: { mailchimp_audience_id: 'abc123' } }, saving: true } );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );

	// Pins Finding 2: the "integration not found" branch renders no dialog, so the
	// guard must never arm there even if pendingChanges still has a stale entry
	// (e.g. from an integration that disappeared from the payload on refetch).
	it( 'does not arm the guard when the integration is missing from the payload', () => {
		renderConfigureView( { integrations: {}, pendingChanges: { esp: { mailchimp_audience_id: 'abc123' } }, saving: false } );
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

	// Pins Finding 1 at the ConfigureView level: unmounting must call the discard
	// callback for the integration currently in view. The corresponding
	// index.test.js coverage confirms the parent's real state actually clears.
	it( 'calls onDiscardChanges for the current integration on unmount', () => {
		const onDiscardChanges = jest.fn();
		const { unmount } = renderConfigureView( {
			pendingChanges: { esp: { mailchimp_audience_id: 'abc123' } },
			saving: false,
			onDiscardChanges,
		} );
		expect( onDiscardChanges ).not.toHaveBeenCalled();
		unmount();
		expect( onDiscardChanges ).toHaveBeenCalledWith( 'esp' );
	} );

	// A save in flight owns the pending changes: handleSave clears them itself on
	// success, and on failure they are the user's only copy. Unmounting mid-save
	// (e.g. the user navigates away while `when` is disarmed) must not discard them.
	it( 'does not call onDiscardChanges on unmount while a save is in flight', () => {
		const onDiscardChanges = jest.fn();
		const { unmount } = renderConfigureView( {
			pendingChanges: { esp: { mailchimp_audience_id: 'abc123' } },
			saving: true,
			onDiscardChanges,
		} );
		unmount();
		expect( onDiscardChanges ).not.toHaveBeenCalled();
	} );
} );
