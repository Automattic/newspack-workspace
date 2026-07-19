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

const OTHER_INTEGRATION = {
	id: 'other',
	name: 'Other ESP',
	description: 'Syncs reader data with another ESP.',
	settings: [],
};

// `saving` accepts either the raw { [integrationId]: boolean } map, or (for the
// single-integration tests below) a bare boolean shorthand that gets wrapped as
// { esp: saving }.
const buildConfigureView = ( {
	integrations = { esp: INTEGRATION },
	pendingChanges = {},
	saving = false,
	onDiscardChanges = jest.fn(),
	integrationId = 'esp',
} = {} ) => (
	<ConfigureView
		integrations={ integrations }
		loading={ false }
		pendingChanges={ pendingChanges }
		saving={ typeof saving === 'object' ? saving : { esp: saving } }
		onFieldChange={ jest.fn() }
		onDiscardChanges={ onDiscardChanges }
		onSave={ jest.fn() }
		match={ { params: { integrationId } } }
	/>
);

const renderConfigureView = props => render( buildConfigureView( props ) );

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

	// Navigating between integrations (e.g. #/settings/esp -> #/settings/other)
	// reuses the same ConfigureView instance instead of unmounting it, since both
	// routes match the same <Route path="/settings/:integrationId">. The cleanup
	// from the 'esp' render must still see 'esp' was saving, not whatever
	// integration is current by the time the cleanup runs.
	it( 'does not call onDiscardChanges when the integration id changes mid-save', () => {
		const onDiscardChanges = jest.fn();
		const integrations = { esp: INTEGRATION, other: OTHER_INTEGRATION };
		const pendingChanges = { esp: { mailchimp_audience_id: 'abc123' } };
		const saving = { esp: true };
		const { rerender } = renderConfigureView( {
			integrations,
			integrationId: 'esp',
			pendingChanges,
			saving,
			onDiscardChanges,
		} );
		rerender( buildConfigureView( { integrations, integrationId: 'other', pendingChanges, saving, onDiscardChanges } ) );
		expect( onDiscardChanges ).not.toHaveBeenCalled();
	} );
} );
