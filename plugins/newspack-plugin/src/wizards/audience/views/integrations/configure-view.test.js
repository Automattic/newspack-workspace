/**
 * External dependencies
 */
import { act, fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { ConfigureView, operatorOptionsForField, reconcileOperators, toggleField } from './configure-view';
import { useUnsavedChangesDialog } from '../../../../../packages/components/src';

const mockSetHeaderData = jest.fn();

jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( { setHeaderData: mockSetHeaderData } ),
} ) );
// Stub the components barrel: with @wordpress/data mocked, the real barrel eagerly loads @wordpress/rich-text, whose module-load combineReducers() call throws.
// Cover everything SettingsField imports so a future select/oauth/textarea fixture renders a stub, not `undefined`.
jest.mock( '@wordpress/components', () => ( {
	// Real inputs (not null stubs) so the per-direction section tests can assert
	// on picker visibility and drive the enable toggles.
	CheckboxControl: ( { label, checked, onChange } ) => (
		<input
			type="checkbox"
			aria-label={ typeof label === 'string' ? label : undefined }
			checked={ !! checked }
			onChange={ e => onChange( e.target.checked ) }
		/>
	),
	ToggleControl: ( { label, checked, onChange } ) => (
		<input
			type="checkbox"
			aria-label={ typeof label === 'string' ? label : undefined }
			checked={ !! checked }
			onChange={ e => onChange( e.target.checked ) }
		/>
	),
	ExternalLink: ( { children } ) => children,
	TextareaControl: ( { label, value, onChange } ) => (
		<textarea aria-label={ label } value={ value || '' } onChange={ e => onChange( e.target.value ) } />
	),
	// The inbound operator selector renders once a field is enabled; without a stub
	// here any test that renders an enabled incoming field hits an undefined element.
	SelectControl: ( { label, value, onChange } ) => (
		<input aria-label={ label } value={ value || '' } onChange={ e => onChange( e.target.value ) } />
	),
} ) );
jest.mock( '../../../../../packages/components/src', () => ( {
	Accordion: ( { children } ) => children,
	AccordionPanel: ( { children } ) => children,
	Button: ( { children } ) => children,
	// Section dividers pass alignment="full-width"; the divider under a section
	// toggle does not, so the stub tags them apart for the tests that assert on
	// whether a toggle divider has anything to divide.
	Divider: ( { alignment } ) => <hr data-testid={ 'full-width' === alignment ? 'section-divider' : 'toggle-divider' } />,
	Grid: ( { children } ) => children,
	SectionHeader: () => null,
	SelectControl: ( { label, value, onChange } ) => (
		<input aria-label={ label } value={ value || '' } onChange={ e => onChange( e.target.value ) } />
	),
	// Minimal controlled input so tests can drive the local draft by typing.
	TextControl: ( { label, value, onChange } ) => <input aria-label={ label } value={ value ?? '' } onChange={ e => onChange( e.target.value ) } />,
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
	onDiscardChanges = jest.fn(),
	integrationId = 'esp',
} = {} ) => (
	<ConfigureView
		integrations={ integrations }
		loading={ false }
		inFlightChanges={ inFlightChanges }
		saving={ saving }
		onSave={ onSave }
		onDiscardChanges={ onDiscardChanges }
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

	it( 'disarms the guard when a field is edited back to its saved value', () => {
		renderConfigureView();
		const input = screen.getByLabelText( 'Audience ID' );
		fireEvent.change( input, { target: { value: 'abc123' } } );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: true } );
		fireEvent.change( input, { target: { value: '' } } );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );

	// Reverting a seeded failed-save edit must clear the parent's retry buffer.
	it( 'discards the retry buffer when a seeded draft is reverted to its saved value', () => {
		const onDiscardChanges = jest.fn();
		const seeded = {
			esp: { ...INTEGRATION, settings: [ { key: 'mailchimp_audience_id', type: 'text', label: 'Audience ID', value: 'saved' } ] },
		};
		render(
			buildConfigureView( { integrations: seeded, inFlightChanges: { esp: { mailchimp_audience_id: 'failed-edit' } }, onDiscardChanges } )
		);
		expect( onDiscardChanges ).not.toHaveBeenCalled();
		fireEvent.change( screen.getByLabelText( 'Audience ID' ), { target: { value: 'saved' } } );
		expect( onDiscardChanges ).toHaveBeenCalledWith( 'esp' );
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

	// Inbound fields store a { key => operator } map. Reference equality would read a
	// net-zero edit (toggle a field on then off, or change an operator and change it
	// back) as pending, keeping Save and the unsaved-changes guard armed.
	const withInbound = value => ( {
		esp: { ...INTEGRATION, settings: [ { key: 'incoming_metadata_fields', type: 'metadata', label: 'Inbound', value } ] },
	} );

	it( 'treats an equivalent inbound operator map as unchanged', () => {
		renderConfigureView( {
			integrations: withInbound( { AMOUNT: 'range' } ),
			inFlightChanges: { esp: { incoming_metadata_fields: { AMOUNT: 'range' } } },
		} );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );

	it( 'still flags a genuinely changed inbound operator map as pending', () => {
		renderConfigureView( {
			integrations: withInbound( { AMOUNT: 'default' } ),
			inFlightChanges: { esp: { incoming_metadata_fields: { AMOUNT: 'range' } } },
		} );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: true } );
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

	// Cleared prefix submits '' but the backend forces 'NP_', so the saved value
	// never equals the submitted one — value-equality would leave it stuck dirty.
	it( 'reconciles a field the server normalizes away from the submitted value', () => {
		const withPrefix = value => ( {
			esp: { ...INTEGRATION, settings: [ { key: 'metadata_prefix', type: 'text', label: 'Prefix', value } ] },
		} );
		const { rerender } = renderConfigureView( {
			integrations: withPrefix( 'OLD_' ),
			inFlightChanges: { esp: { metadata_prefix: '' } },
		} );
		expect( screen.getByLabelText( 'Prefix' ).value ).toBe( '' );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: true } );
		rerender( buildConfigureView( { integrations: withPrefix( 'NP_' ) } ) );
		expect( screen.getByLabelText( 'Prefix' ).value ).toBe( 'NP_' );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );

	// The control emits '5' but the sanitizer stores 5, so the saved value never
	// strict-equals the submitted one.
	it( 'reconciles a number field the server coerces to a numeric type', () => {
		const withNumber = value => ( {
			esp: { ...INTEGRATION, settings: [ { key: 'batch_size', type: 'number', label: 'Batch size', value } ] },
		} );
		const { rerender } = renderConfigureView( {
			integrations: withNumber( 1 ),
			inFlightChanges: { esp: { batch_size: '5' } },
		} );
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: true } );
		rerender( buildConfigureView( { integrations: withNumber( 5 ) } ) );
		expect( screen.getByLabelText( 'Batch size' ).value ).toBe( '5' );
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

describe( 'incoming-field operators', () => {
	it( 'offers text/number for plain fields and single/multi for enumerated', () => {
		expect( operatorOptionsForField( { has_options: false } ).map( o => o.value ) ).toEqual( [ 'default', 'range' ] );
		expect( operatorOptionsForField( { has_options: true } ).map( o => o.value ) ).toEqual( [ 'default', 'list__in' ] );
	} );

	it( 'constrains operator options by value_type', () => {
		expect( operatorOptionsForField( { value_type: 'number' } ).map( o => o.value ) ).toEqual( [ 'range' ] );
		expect( operatorOptionsForField( { value_type: 'date' } ).map( o => o.value ) ).toEqual( [ 'default' ] );
		expect( operatorOptionsForField( { value_type: 'datetime' } ).map( o => o.value ) ).toEqual( [ 'default' ] );
		expect( operatorOptionsForField( { value_type: 'boolean' } ).map( o => o.value ) ).toEqual( [ 'default' ] );
		expect( operatorOptionsForField( { value_type: 'multiselect' } ).map( o => o.value ) ).toEqual( [ 'list__in' ] );
		expect( operatorOptionsForField( { value_type: 'select' } ).map( o => o.value ) ).toEqual( [ 'default', 'list__in' ] );
		expect( operatorOptionsForField( { value_type: 'string', has_options: false } ).map( o => o.value ) ).toEqual( [ 'default', 'range' ] );
		expect( operatorOptionsForField( { value_type: 'string', has_options: true } ).map( o => o.value ) ).toEqual( [ 'default', 'list__in' ] );
	} );

	it( 'toggles a field in/out of the operator map using the field default', () => {
		const option = { value: 'AMOUNT', has_options: false, matching_function: 'default' };
		expect( toggleField( {}, option, true ) ).toEqual( { AMOUNT: 'default' } );
		expect( toggleField( { AMOUNT: 'range' }, option, false ) ).toEqual( {} );
	} );

	it( 'reconciles a stored operator that is invalid for the declared value_type', () => {
		const options = [ { value: 'AMOUNT', value_type: 'number', matching_function: 'range', has_options: false } ];
		expect( reconcileOperators( { AMOUNT: 'default' }, options ) ).toEqual( { AMOUNT: 'range' } );
	} );

	it( 'returns the same map when every stored operator is already valid', () => {
		const options = [ { value: 'AMOUNT', value_type: 'number', has_options: false } ];
		const map = { AMOUNT: 'range' };
		// Identity is the signal the save path uses to skip an unnecessary write.
		expect( reconcileOperators( map, options ) ).toBe( map );
	} );

	it( 'never enables a field that is absent from the map', () => {
		const options = [ { value: 'AMOUNT', value_type: 'number', has_options: false } ];
		expect( reconcileOperators( {}, options ) ).toEqual( {} );
	} );

	it( 'preserves sibling fields on toggle-off and propagates the field default operator', () => {
		expect(
			toggleField(
				{
					AMOUNT: 'range',
					NAME: 'default',
				},
				{ value: 'AMOUNT', has_options: false, matching_function: 'range' },
				false
			)
		).toEqual( { NAME: 'default' } );
		expect( toggleField( {}, { value: 'FAVS', has_options: true, matching_function: 'list__in' }, true ) ).toEqual( { FAVS: 'list__in' } );
	} );
} );

describe( 'incoming-field operator reconciliation on save', () => {
	beforeEach( () => {
		mockSetHeaderData.mockClear();
		useUnsavedChangesDialog.mockClear();
		useUnsavedChangesDialog.mockReturnValue( { confirmDialog: null, requestConfirm: jest.fn() } );
	} );

	const withInboundOptions = storedOperator => ( {
		esp: {
			...INTEGRATION,
			settings: [
				{ key: 'mailchimp_audience_id', type: 'text', label: 'Audience ID', value: '' },
				{
					key: 'incoming_metadata_fields',
					type: 'metadata',
					label: 'Inbound',
					value: { AMOUNT: storedOperator },
					options: [ { value: 'AMOUNT', label: 'Amount', value_type: 'number', matching_function: 'range', has_options: false } ],
				},
			],
		},
	} );

	// The row displays the only valid option ('Number'), but a single-option select
	// can never fire onChange to persist it. Folding the repair into a save the user
	// already asked for keeps the stored operator in step with the label.
	it( 'folds a reconciled operator map into the save payload', async () => {
		const onSave = jest.fn( () => Promise.resolve() );
		renderConfigureView( { integrations: withInboundOptions( 'default' ), onSave } );
		fireEvent.change( screen.getByLabelText( 'Audience ID' ), { target: { value: 'abc123' } } );
		await act( async () => {
			getLatestSaveAction()();
		} );
		expect( onSave ).toHaveBeenCalledWith( 'esp', {
			mailchimp_audience_id: 'abc123',
			incoming_metadata_fields: { AMOUNT: 'range' },
		} );
	} );

	it( 'leaves the payload alone when the stored operator is already valid', async () => {
		const onSave = jest.fn( () => Promise.resolve() );
		renderConfigureView( { integrations: withInboundOptions( 'range' ), onSave } );
		fireEvent.change( screen.getByLabelText( 'Audience ID' ), { target: { value: 'abc123' } } );
		await act( async () => {
			getLatestSaveAction()();
		} );
		expect( onSave ).toHaveBeenCalledWith( 'esp', { mailchimp_audience_id: 'abc123' } );
	} );
} );

describe( 'ConfigureView per-direction sections', () => {
	beforeEach( () => {
		mockSetHeaderData.mockClear();
		useUnsavedChangesDialog.mockClear();
		useUnsavedChangesDialog.mockReturnValue( { confirmDialog: null, requestConfirm: jest.fn() } );
	} );

	// A bidirectional integration as the capability-aware backend declares it:
	// each direction carries its enable toggle alongside its metadata field.
	const bidirectionalIntegration = () => ( {
		esp: {
			...INTEGRATION,
			settings: [
				{ key: 'mailchimp_audience_id', type: 'text', label: 'Audience ID', value: '' },
				{ key: 'outgoing_sync_enabled', type: 'checkbox', label: 'Enable outbound sync', value: true },
				{
					key: 'outgoing_metadata_fields',
					type: 'metadata',
					label: 'Outgoing metadata fields',
					value: [ 'Full Name' ],
					grouped_options: [ { section: 'Basic', fields: [ 'Full Name', 'Signup Date' ] } ],
				},
				{ key: 'incoming_sync_enabled', type: 'checkbox', label: 'Enable inbound sync', value: true },
				{
					key: 'incoming_metadata_fields',
					type: 'metadata',
					label: 'Incoming metadata fields',
					value: { vip: 'default' },
					options: [ { value: 'vip', label: 'VIP', matching_function: 'default', has_options: false } ],
				},
			],
		},
	} );

	// The backend omits a direction's fields when the integration lacks the
	// capability, so the view must simply not render that section.
	it( 'renders no direction sections for an integration that declares neither', () => {
		renderConfigureView();
		expect( screen.queryByLabelText( 'Enable outbound sync' ) ).toBeNull();
		expect( screen.queryByLabelText( 'Enable inbound sync' ) ).toBeNull();
		expect( screen.queryByLabelText( 'Full Name' ) ).toBeNull();
		expect( screen.queryByLabelText( 'VIP' ) ).toBeNull();
	} );

	it( 'renders both sections with their toggles and pickers when enabled', () => {
		renderConfigureView( { integrations: bidirectionalIntegration() } );
		expect( screen.getByLabelText( 'Enable outbound sync' ).checked ).toBe( true );
		expect( screen.getByLabelText( 'Enable inbound sync' ).checked ).toBe( true );
		expect( screen.getByLabelText( 'Full Name' ).checked ).toBe( true );
		expect( screen.getByLabelText( 'Signup Date' ).checked ).toBe( false );
		expect( screen.getByLabelText( 'VIP' ).checked ).toBe( true );
	} );

	it( 'hides only the outbound picker when outbound sync is toggled off and submits just the toggle', async () => {
		const onSave = jest.fn( () => Promise.resolve() );
		renderConfigureView( { integrations: bidirectionalIntegration(), onSave } );

		fireEvent.click( screen.getByLabelText( 'Enable outbound sync' ) );
		expect( screen.queryByLabelText( 'Full Name' ) ).toBeNull();
		expect( screen.getByLabelText( 'VIP' ) ).toBeInTheDocument();

		await act( async () => {
			getLatestSaveAction()();
		} );
		// The field selection is not part of the payload: pausing must not
		// rewrite (or clear) the stored outgoing_metadata_fields option.
		expect( onSave ).toHaveBeenCalledWith( 'esp', { outgoing_sync_enabled: false } );
	} );

	it( 'restores the inbound selection after toggling the direction off and back on', () => {
		renderConfigureView( { integrations: bidirectionalIntegration() } );

		const toggle = () => screen.getByLabelText( 'Enable inbound sync' );
		fireEvent.click( toggle() );
		expect( screen.queryByLabelText( 'VIP' ) ).toBeNull();
		expect( screen.getByLabelText( 'Full Name' ) ).toBeInTheDocument();
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: true } );

		fireEvent.click( toggle() );
		expect( screen.getByLabelText( 'VIP' ).checked ).toBe( true );
		// A net-zero toggle leaves nothing pending.
		expect( useUnsavedChangesDialog ).toHaveBeenLastCalledWith( { when: false } );
	} );

	// Payloads predating the toggles (or an integration that opted out of them)
	// must keep rendering the pickers unconditionally.
	it( 'renders pickers without toggles for a payload lacking the toggle fields', () => {
		const integrations = bidirectionalIntegration();
		integrations.esp.settings = integrations.esp.settings.filter( f => ! f.key.endsWith( '_sync_enabled' ) );
		renderConfigureView( { integrations } );
		expect( screen.queryByLabelText( 'Enable outbound sync' ) ).toBeNull();
		expect( screen.queryByLabelText( 'Enable inbound sync' ) ).toBeNull();
		expect( screen.getByLabelText( 'Full Name' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'VIP' ) ).toBeInTheDocument();
	} );

	// The push-pipeline settings (metadata prefix, account-deletion sync) render
	// inside the Outbound section and follow the outbound pause the same way the
	// outgoing picker does.
	it( 'renders push settings in the Outbound section and hides them while paused', () => {
		const integrations = bidirectionalIntegration();
		integrations.esp.settings = [
			{ key: 'sync_account_deletion', type: 'checkbox', label: 'Sync account deletion', value: true },
			{
				key: 'account_deletion_handling',
				type: 'select',
				label: 'Deletion handling',
				value: 'flag',
				options: [
					{ value: 'flag', label: 'Flag' },
					{ value: 'delete', label: 'Delete' },
				],
				condition: { field: 'sync_account_deletion', equals: true },
			},
			{ key: 'metadata_prefix', type: 'text', label: 'Metadata field prefix', value: 'NP_' },
			...integrations.esp.settings,
		];
		renderConfigureView( { integrations } );
		expect( screen.getByLabelText( 'Sync account deletion' ).checked ).toBe( true );
		expect( screen.getByLabelText( 'Deletion handling' ) ).toBeInTheDocument();

		// Document order pins the placement: own settings first, then the Inbound
		// picker, then the push settings inside the Outbound section.
		const order = screen
			.getAllByLabelText( /^(Audience ID|VIP|Sync account deletion|Metadata field prefix)$/ )
			.map( el => el.getAttribute( 'aria-label' ) );
		expect( order ).toEqual( [ 'Audience ID', 'VIP', 'Sync account deletion', 'Metadata field prefix' ] );

		fireEvent.click( screen.getByLabelText( 'Enable outbound sync' ) );
		expect( screen.queryByLabelText( 'Sync account deletion' ) ).toBeNull();
		expect( screen.queryByLabelText( 'Deletion handling' ) ).toBeNull();
		expect( screen.queryByLabelText( 'Metadata field prefix' ) ).toBeNull();
		// The integration's own settings stay put.
		expect( screen.getByLabelText( 'Audience ID' ) ).toBeInTheDocument();

		fireEvent.click( screen.getByLabelText( 'Enable outbound sync' ) );
		expect( screen.getByLabelText( 'Sync account deletion' ).checked ).toBe( true );
		expect( screen.getByLabelText( 'Metadata field prefix' ).value ).toBe( 'NP_' );
	} );

	// With push-group settings but no picker (a partial third-party override),
	// the Outbound section still renders its toggle and settings.
	it( 'renders the Outbound section for push settings without a picker', () => {
		const integrations = bidirectionalIntegration();
		integrations.esp.settings = [
			{ key: 'metadata_prefix', type: 'text', label: 'Metadata field prefix', value: 'NP_' },
			...integrations.esp.settings.filter( f => f.key !== 'outgoing_metadata_fields' ),
		];
		renderConfigureView( { integrations } );
		expect( screen.getByLabelText( 'Metadata field prefix' ) ).toBeInTheDocument();

		fireEvent.click( screen.getByLabelText( 'Enable outbound sync' ) );
		expect( screen.queryByLabelText( 'Metadata field prefix' ) ).toBeNull();
	} );

	// A toggle whose paired metadata field is missing (a third-party
	// get_settings_fields() override) must stay visible and editable rather
	// than shipping a field the view can neither display nor save.
	it( 'falls an orphaned direction toggle through to the generic Settings group', async () => {
		const onSave = jest.fn( () => Promise.resolve() );
		const integrations = bidirectionalIntegration();
		integrations.esp.settings = integrations.esp.settings.filter( f => f.key !== 'outgoing_metadata_fields' );
		renderConfigureView( { integrations, onSave } );

		// No Outbound section without the picker field, but the toggle survives.
		expect( screen.queryByLabelText( 'Full Name' ) ).toBeNull();
		const toggle = screen.getByLabelText( 'Enable outbound sync' );
		expect( toggle.checked ).toBe( true );

		fireEvent.click( toggle );
		await act( async () => {
			getLatestSaveAction()();
		} );
		expect( onSave ).toHaveBeenCalledWith( 'esp', { outgoing_sync_enabled: false } );
	} );

	// The divider under a section toggle only separates it from the content
	// below, so a direction with nothing to show must not render one.
	it( 'renders a toggle divider per enabled section, and none once both are paused', () => {
		renderConfigureView( { integrations: bidirectionalIntegration() } );
		expect( screen.queryAllByTestId( 'toggle-divider' ) ).toHaveLength( 2 );

		fireEvent.click( screen.getByLabelText( 'Enable outbound sync' ) );
		fireEvent.click( screen.getByLabelText( 'Enable inbound sync' ) );
		expect( screen.queryAllByTestId( 'toggle-divider' ) ).toHaveLength( 0 );
	} );

	// An enabled Outbound section that declares a settings field but has no
	// picker, and hides that field behind an unsatisfied condition, still
	// renders the section — with nothing under the toggle to divide.
	it( 'renders no outbound toggle divider when every outbound settings field is hidden', () => {
		renderConfigureView( {
			integrations: {
				esp: {
					...INTEGRATION,
					settings: [
						{ key: 'mailchimp_audience_id', type: 'text', label: 'Audience ID', value: '' },
						{ key: 'outgoing_sync_enabled', type: 'checkbox', label: 'Enable outbound sync', value: true },
						{
							key: 'account_deletion_handling',
							type: 'text',
							label: 'How to sync deletion',
							value: 'flag',
							condition: { field: 'mailchimp_audience_id', equals: 'never-matches' },
						},
					],
				},
			},
		} );
		expect( screen.getByLabelText( 'Enable outbound sync' ).checked ).toBe( true );
		expect( screen.queryByLabelText( 'How to sync deletion' ) ).toBeNull();
		expect( screen.queryAllByTestId( 'toggle-divider' ) ).toHaveLength( 0 );
	} );
} );
