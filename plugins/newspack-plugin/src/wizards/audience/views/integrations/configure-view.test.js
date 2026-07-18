/**
 * External dependencies
 */
import { act, fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { ConfigureView } from './configure-view';
import { useUnsavedChangesDialog } from '../../../../../packages/components/src';

const mockSetHeaderData = jest.fn();

jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( { setHeaderData: mockSetHeaderData } ),
} ) );
// Stub the components barrel: with @wordpress/data mocked, the real barrel eagerly loads @wordpress/rich-text, whose module-load combineReducers() call throws.
jest.mock( '@wordpress/components', () => ( {
	CheckboxControl: () => null,
} ) );
jest.mock( '../../../../../packages/components/src', () => ( {
	Accordion: ( { children } ) => children,
	AccordionPanel: ( { children } ) => children,
	Divider: () => null,
	Grid: ( { children } ) => children,
	SectionHeader: () => null,
	// Minimal controlled input so tests can drive the local draft by typing.
	TextControl: ( { label, value, onChange } ) => <input aria-label={ label } value={ value || '' } onChange={ e => onChange( e.target.value ) } />,
	useUnsavedChangesDialog: jest.fn( () => ( { confirmDialog: null, requestConfirm: jest.fn() } ) ),
} ) );
jest.mock(
	'../../../wizards-tab',
	() =>
		( { children } ) =>
			children
);
jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

const INTEGRATION = {
	id: 'esp',
	name: 'Newsletter ESP',
	description: 'Syncs reader data with your ESP.',
	settings: [ { key: 'mailchimp_audience_id', type: 'text', label: 'Audience ID', value: '' } ],
};

const OTHER_INTEGRATION = {
	id: 'other',
	name: 'Other ESP',
	description: 'Syncs reader data with another ESP.',
	settings: [ { key: 'other_id', type: 'text', label: 'Other ID', value: '' } ],
};

const buildConfigureView = ( {
	integrations = { esp: INTEGRATION },
	inFlightChanges = {},
	saving = {},
	onSave = jest.fn( () => Promise.resolve() ),
	integrationId = 'esp',
} = {} ) => (
	<ConfigureView
		integrations={ integrations }
		loading={ false }
		inFlightChanges={ inFlightChanges }
		saving={ saving }
		onSave={ onSave }
		match={ { params: { integrationId } } }
	/>
);

const renderConfigureView = props => render( buildConfigureView( props ) );

// The Save button lives in the wizard header, registered via setHeaderData.
// Pull the latest registered Save action closure so tests can invoke it.
const getLatestSaveAction = () => {
	const calls = mockSetHeaderData.mock.calls.filter( ( [ data ] ) => data.actions );
	return calls[ calls.length - 1 ][ 0 ].actions[ 0 ].action;
};

describe( 'ConfigureView unsaved-changes guard', () => {
	beforeEach( () => {
		mockSetHeaderData.mockClear();
		useUnsavedChangesDialog.mockClear();
		useUnsavedChangesDialog.mockReturnValue( { confirmDialog: null, requestConfirm: jest.fn() } );
	} );

	it( 'does not arm the guard with no draft and no save in flight', () => {
		renderConfigureView();
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );

	it( 'arms the guard once the user edits a field', () => {
		renderConfigureView();
		fireEvent.change( screen.getByLabelText( 'Audience ID' ), { target: { value: 'abc123' } } );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: true } );
	} );

	it( 'does not arm the guard while a save is in flight, even with a draft', () => {
		renderConfigureView( { saving: { esp: true } } );
		fireEvent.change( screen.getByLabelText( 'Audience ID' ), { target: { value: 'abc123' } } );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );

	// The "integration not found" branch renders no dialog, so the guard must
	// never arm there even if the retry buffer still has a stale entry.
	it( 'does not arm the guard when the integration is missing from the payload', () => {
		renderConfigureView( { integrations: {}, inFlightChanges: { esp: { mailchimp_audience_id: 'abc123' } } } );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );

	it( 'renders the guard dialog element instead of dropping it', () => {
		useUnsavedChangesDialog.mockReturnValue( {
			confirmDialog: <div data-testid="guard-dialog" />,
			requestConfirm: jest.fn(),
		} );
		renderConfigureView( { inFlightChanges: { esp: { mailchimp_audience_id: 'abc123' } } } );
		expect( screen.getByTestId( 'guard-dialog' ) ).toBeInTheDocument();
	} );
} );

describe( 'ConfigureView draft seeding', () => {
	beforeEach( () => {
		mockSetHeaderData.mockClear();
		useUnsavedChangesDialog.mockClear();
		useUnsavedChangesDialog.mockReturnValue( { confirmDialog: null, requestConfirm: jest.fn() } );
	} );

	// Returning to an integration whose last save failed re-shows the edit with
	// the guard armed, so the user can retry.
	it( 'seeds the draft from the retry buffer', () => {
		renderConfigureView( { inFlightChanges: { esp: { mailchimp_audience_id: 'abc123' } } } );
		expect( screen.getByLabelText( 'Audience ID' ).value ).toBe( 'abc123' );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: true } );
	} );

	it( 'starts with a clean draft when the retry buffer is empty', () => {
		renderConfigureView();
		expect( screen.getByLabelText( 'Audience ID' ).value ).toBe( '' );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );
} );

describe( 'ConfigureView save wiring', () => {
	beforeEach( () => {
		mockSetHeaderData.mockClear();
		useUnsavedChangesDialog.mockClear();
		useUnsavedChangesDialog.mockReturnValue( { confirmDialog: null, requestConfirm: jest.fn() } );
	} );

	// Server value for the field once a save of `value` has landed.
	const savedIntegrations = value => ( {
		esp: { ...INTEGRATION, settings: [ { key: 'mailchimp_audience_id', type: 'text', label: 'Audience ID', value } ] },
	} );

	it( 'reconciles the draft once the parent reflects the saved value', async () => {
		const onSave = jest.fn( () => Promise.resolve() );
		const { rerender } = renderConfigureView( { onSave } );
		fireEvent.change( screen.getByLabelText( 'Audience ID' ), { target: { value: 'abc123' } } );
		await act( async () => {
			getLatestSaveAction()();
		} );
		expect( onSave ).toHaveBeenCalledWith( 'esp', { mailchimp_audience_id: 'abc123' } );
		rerender( buildConfigureView( { integrations: savedIntegrations( 'abc123' ), onSave } ) );
		expect( screen.getByLabelText( 'Audience ID' ).value ).toBe( 'abc123' );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );

	it( 'keeps the draft when the save fails', async () => {
		const onSave = jest.fn( () => Promise.reject( new Error( 'nope' ) ) );
		renderConfigureView( { onSave } );
		fireEvent.change( screen.getByLabelText( 'Audience ID' ), { target: { value: 'abc123' } } );
		await act( async () => {
			getLatestSaveAction()();
		} );
		expect( onSave ).toHaveBeenCalledWith( 'esp', { mailchimp_audience_id: 'abc123' } );
		expect( screen.getByLabelText( 'Audience ID' ).value ).toBe( 'abc123' );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: true } );
	} );

	// Guards the draftRef: the header Save action is only re-registered when
	// hasPending transitions, so a second edit made while already dirty does not
	// re-run that effect. Reading draftRef.current (not a captured draft) is what
	// makes Save submit the latest edit.
	it( 'saves the latest draft after multiple edits', async () => {
		const onSave = jest.fn( () => Promise.resolve() );
		renderConfigureView( { onSave } );
		fireEvent.change( screen.getByLabelText( 'Audience ID' ), { target: { value: 'abc' } } );
		fireEvent.change( screen.getByLabelText( 'Audience ID' ), { target: { value: 'abcd' } } );
		await act( async () => {
			getLatestSaveAction()();
		} );
		expect( onSave ).toHaveBeenCalledWith( 'esp', { mailchimp_audience_id: 'abcd' } );
	} );

	// Fields stay editable during an in-flight save; a successful save must clear
	// only the submitted values, not edits typed while the request was pending.
	it( 'preserves edits typed while a save is in flight', async () => {
		let resolveSave;
		const onSave = jest.fn(
			() =>
				new Promise( resolve => {
					resolveSave = resolve;
				} )
		);
		const { rerender } = renderConfigureView( { onSave } );
		fireEvent.change( screen.getByLabelText( 'Audience ID' ), { target: { value: 'abc123' } } );
		act( () => {
			getLatestSaveAction()();
		} );
		fireEvent.change( screen.getByLabelText( 'Audience ID' ), { target: { value: 'abc123-more' } } );
		await act( async () => {
			resolveSave();
		} );
		// Parent reflects only the submitted 'abc123'; the later edit must survive.
		rerender( buildConfigureView( { integrations: savedIntegrations( 'abc123' ), onSave } ) );
		expect( screen.getByLabelText( 'Audience ID' ).value ).toBe( 'abc123-more' );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: true } );
	} );

	// After a save completes the mounted view must clear its draft even if the
	// instance that clicked Save has since remounted (the seeded instance owns no
	// success closure) — otherwise it shows a phantom "unsaved changes" state.
	it( 'clears a re-seeded draft once the server reflects the save', () => {
		const { rerender } = renderConfigureView( {
			inFlightChanges: { esp: { mailchimp_audience_id: 'abc123' } },
			saving: { esp: true },
		} );
		expect( screen.getByLabelText( 'Audience ID' ).value ).toBe( 'abc123' );
		rerender( buildConfigureView( { integrations: savedIntegrations( 'abc123' ), inFlightChanges: {}, saving: {} } ) );
		expect( screen.getByLabelText( 'Audience ID' ).value ).toBe( 'abc123' );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );

	// Metadata lists round-trip in canonical (not click) order, so the reconcile
	// must set-compare them or a saved field would stay stuck dirty.
	it( 'reconciles a metadata array even when the server reorders it', () => {
		const withOutbound = value => ( {
			esp: { ...INTEGRATION, settings: [ { key: 'outgoing_metadata_fields', type: 'metadata', label: 'Outbound', value } ] },
		} );
		const { rerender } = renderConfigureView( {
			integrations: withOutbound( [] ),
			inFlightChanges: { esp: { outgoing_metadata_fields: [ 'B', 'A' ] } },
		} );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: true } );
		rerender( buildConfigureView( { integrations: withOutbound( [ 'A', 'B' ] ) } ) );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );

	// A boolean checkbox draft round-trips from WP options as the string '1', so
	// the reconcile must coerce or the field would stay stuck dirty.
	it( 'reconciles a boolean checkbox against a string-typed server value', () => {
		const withCheckbox = value => ( {
			esp: { ...INTEGRATION, settings: [ { key: 'sync_delete', type: 'checkbox', label: 'Sync delete', value } ] },
		} );
		const { rerender } = renderConfigureView( {
			integrations: withCheckbox( false ),
			inFlightChanges: { esp: { sync_delete: true } },
		} );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: true } );
		rerender( buildConfigureView( { integrations: withCheckbox( '1' ) } ) );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );
} );

describe( 'ConfigureView per-id remount', () => {
	beforeEach( () => {
		mockSetHeaderData.mockClear();
		useUnsavedChangesDialog.mockClear();
		useUnsavedChangesDialog.mockReturnValue( { confirmDialog: null, requestConfirm: jest.fn() } );
	} );

	// Both #/settings/esp and #/settings/other match one Route, so React reuses
	// the instance across an id change. Keying the inner view by id remounts it,
	// resetting the draft — esp's edit must not bleed into other.
	it( 'resets the draft when the integration id changes', () => {
		const integrations = { esp: INTEGRATION, other: OTHER_INTEGRATION };
		const { rerender } = renderConfigureView( { integrations, integrationId: 'esp' } );
		fireEvent.change( screen.getByLabelText( 'Audience ID' ), { target: { value: 'abc123' } } );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: true } );

		rerender( buildConfigureView( { integrations, integrationId: 'other' } ) );
		expect( screen.getByLabelText( 'Other ID' ).value ).toBe( '' );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );
} );
