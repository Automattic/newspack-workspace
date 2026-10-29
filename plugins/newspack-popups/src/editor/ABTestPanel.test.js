/**
 * The A/B Test panel's decisions: which fields show, what a Test ID edit writes,
 * when the placement blocks a test, what a read-only user sees, and how the
 * other prompts in the test are listed. The editor's component library is
 * stubbed down to native inputs that forward value, change, disabled and help.
 */

/**
 * WordPress dependencies.
 */
import { render, screen, fireEvent } from '@testing-library/react';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/data', () => ( { useSelect: jest.fn() } ) );
jest.mock( '@wordpress/compose', () => ( { useDebounce: fn => fn } ) );
jest.mock( '@wordpress/api-fetch' );
// Stubbed so the tests read the panel's logic, not its layout.
jest.mock( '@wordpress/ui', () => ( { Stack: ( { children } ) => <div>{ children }</div> } ), { virtual: true } );

jest.mock( '@wordpress/components', () => ( {
	TextControl: ( { label, value, onChange, onBlur, disabled, help } ) => (
		<div>
			<input aria-label={ label } value={ value } onChange={ e => onChange( e.target.value ) } onBlur={ onBlur } disabled={ disabled } />
			{ help && <p>{ help }</p> }
		</div>
	),
	SelectControl: ( { label, value, onChange, disabled, options, help } ) => (
		<div>
			<select aria-label={ label } value={ value } onChange={ e => onChange( e.target.value ) } disabled={ disabled }>
				{ options.map( option => (
					<option key={ option.value } value={ option.value } disabled={ option.disabled }>
						{ option.label }
					</option>
				) ) }
			</select>
			{ help && <p>{ help }</p> }
		</div>
	),
	RangeControl: ( { label, value, onChange, disabled, help } ) => (
		<div>
			<input type="range" aria-label={ label } value={ value } onChange={ e => onChange( Number( e.target.value ) ) } disabled={ disabled } />
			{ help && <p>{ help }</p> }
		</div>
	),
	Notice: ( { status, children, spokenMessage } ) => (
		<div role="note" data-status={ status } data-spoken={ spokenMessage }>
			{ children }
		</div>
	),
	ExternalLink: ( { href, children } ) => <a href={ href }>{ children }</a>,
	BaseControl: { VisualLabel: ( { id, children } ) => <span id={ id }>{ children }</span> },
} ) );

import ABTestPanel from './ABTestPanel';

const AB_TESTING = {
	enabled: true,
	can_manage_tests: true,
	goals: {
		form_submission: 'Form submission',
		link_click: 'Link or button click',
		donation: 'Donation',
		subscription: 'Subscription',
	},
	variants: { a: 'A (control)', b: 'B (challenger)' },
	ineligible_placements: [ 'manual', 'custom1' ],
};

const INELIGIBLE = 'A/B tests only work on prompts that Newspack places for you.';
const MISSING_BLOCK = "Readers can't donate from this prompt. Add a Donate block, or choose another goal.";
const MISSING_BLOCK_FROM_CONTROL = "Readers can't donate from this prompt. Add a Donate block, or change the goal on version A.";

let onMetaFieldChange;

const panelElement = props => (
	<ABTestPanel
		newspack_popups_ab_test_id=""
		newspack_popups_ab_variant=""
		newspack_popups_ab_test_goal=""
		newspack_popups_ab_control_share={ 50 }
		placement="center"
		postId={ 2 }
		onMetaFieldChange={ onMetaFieldChange }
		{ ...props }
	/>
);
const renderPanel = props => render( panelElement( props ) );

const allInputs = () => [ ...screen.getAllByRole( 'textbox' ), ...screen.getAllByRole( 'combobox' ), ...screen.getAllByRole( 'slider' ) ];

beforeEach( () => {
	window.newspack_popups_data = { ab_testing: { ...AB_TESTING } };
	onMetaFieldChange = jest.fn();
	useSelect.mockReturnValue( [ 'core/paragraph' ] );
	apiFetch.mockResolvedValue( [] );
} );

afterEach( () => {
	delete window.newspack_popups_data;
} );

describe( 'ABTestPanel', () => {
	it( 'shows only the test name field until one is set', () => {
		renderPanel();

		expect( screen.getByLabelText( 'Test name' ) ).toBeTruthy();
		expect( screen.getByText( /Prompts with the same test name are compared with each other/ ) ).toBeTruthy();
		expect( screen.queryByLabelText( 'Version' ) ).toBeNull();
		expect( screen.queryByLabelText( 'Goal' ) ).toBeNull();
	} );

	it( 'keeps what was typed in the field until focus leaves it, and writes the slug', () => {
		const { rerender } = renderPanel();
		const field = () => screen.getByLabelText( 'Test name' );

		fireEvent.change( field(), { target: { value: 'spring-' } } );
		expect( field().value ).toBe( 'spring-' );
		expect( onMetaFieldChange ).toHaveBeenLastCalledWith( { newspack_popups_ab_test_id: 'spring', newspack_popups_ab_variant: 'a' } );

		rerender( panelElement( { newspack_popups_ab_test_id: 'spring', newspack_popups_ab_variant: 'a' } ) );
		expect( field().value ).toBe( 'spring-' );
		fireEvent.blur( field() );
		expect( field().value ).toBe( 'spring' );
	} );

	it( 'slugs a typed test name and makes the prompt the control', () => {
		renderPanel();

		fireEvent.change( screen.getByLabelText( 'Test name' ), { target: { value: 'Spring Drive' } } );

		expect( onMetaFieldChange ).toHaveBeenCalledWith( {
			newspack_popups_ab_test_id: 'spring-drive',
			newspack_popups_ab_variant: 'a',
		} );
	} );

	it( 'clearing the test name resets the version and the goal too', () => {
		renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'b', newspack_popups_ab_test_goal: 'donation' } );

		fireEvent.change( screen.getByLabelText( 'Test name' ), { target: { value: '' } } );

		expect( onMetaFieldChange ).toHaveBeenCalledWith( {
			newspack_popups_ab_test_id: '',
			newspack_popups_ab_variant: '',
			newspack_popups_ab_test_goal: '',
		} );
	} );

	it( 'offers the control share on the control only, with the split spelled out', () => {
		const { unmount } = renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'a', newspack_popups_ab_control_share: 60 } );
		expect( screen.getByLabelText( 'Readers who see A' ) ).toBeTruthy();
		expect( screen.getByText( '60% see A, 40% see B' ) ).toBeTruthy();
		unmount();

		renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'b' } );
		expect( screen.queryByLabelText( 'Readers who see A' ) ).toBeNull();
	} );

	it( 'shows a stored version the editor does not offer, and leaves it alone', () => {
		renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'c' } );

		const option = screen.getByRole( 'option', { name: 'Unsupported version (C)' } );
		expect( option.disabled ).toBe( true );
		expect( screen.getByLabelText( 'Version' ).value ).toBe( 'c' );
		expect( onMetaFieldChange ).not.toHaveBeenCalled();
	} );

	it( 'blocks a test on an ineligible placement, and escalates once a test name is set', () => {
		const { unmount } = renderPanel( { placement: 'manual' } );
		expect( screen.getByRole( 'note' ).textContent ).toBe( INELIGIBLE );
		expect( screen.getByRole( 'note' ).dataset.status ).toBe( 'warning' );
		// Standing state, not the result of an action: nothing to announce on mount.
		expect( screen.getByRole( 'note' ).dataset.spoken ).toBe( '' );
		expect( screen.queryByLabelText( 'Test name' ) ).toBeNull();
		unmount();

		renderPanel( { placement: 'manual', newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'a' } );
		const note = screen.getAllByRole( 'note' )[ 0 ];
		expect( note.dataset.status ).toBe( 'error' );
		expect( note.textContent ).toContain( 'Clear the test name or choose another placement to save.' );
		// An error asks the author to act, so it is the one notice the panel speaks.
		expect( note.dataset.spoken ).toBe( note.textContent );
		expect( screen.getByLabelText( 'Test name' ).disabled ).toBe( false );
	} );

	it( 'renders read-only for a user the site excludes from managing tests', () => {
		window.newspack_popups_data.ab_testing.can_manage_tests = false;

		renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'a', newspack_popups_ab_test_goal: 'donation' } );

		expect( screen.getByText( "You can see this test's settings but can't change them." ) ).toBeTruthy();
		expect( allInputs().length ).toBeGreaterThan( 2 );
		allInputs().forEach( input => expect( input.disabled ).toBe( true ) );
	} );

	it( 'warns when the prompt has no block that records the chosen goal', async () => {
		const { unmount } = renderPanel( {
			newspack_popups_ab_test_id: 'x',
			newspack_popups_ab_variant: 'b',
			newspack_popups_ab_test_goal: 'donation',
		} );
		expect( await screen.findByText( MISSING_BLOCK ) ).toBeTruthy();
		expect( screen.getByRole( 'note' ).dataset.spoken ).toBe( '' );
		unmount();

		useSelect.mockReturnValue( [ 'core/paragraph', 'newspack-blocks/donate' ] );
		const second = renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'b', newspack_popups_ab_test_goal: 'donation' } );
		expect( screen.queryByText( MISSING_BLOCK ) ).toBeNull();
		second.unmount();

		useSelect.mockReturnValue( [ 'core/paragraph' ] );
		renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'b', newspack_popups_ab_test_goal: 'link_click' } );
		expect( screen.queryByText( MISSING_BLOCK ) ).toBeNull();
	} );

	it( 'sets the goal and split on version A only, so a challenger sees a read-only summary', async () => {
		apiFetch.mockResolvedValue( [
			{
				id: 1,
				variant: 'a',
				title: 'Control',
				goal: 'donation',
				control_share: 60,
				status: 'publish',
				status_label: 'Published',
				edit_link: null,
			},
		] );

		renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'b' } );

		expect( await screen.findByText( 'Goal: Donation. 60% see A, 40% see B. Set on version A.' ) ).toBeTruthy();
		expect( screen.queryByLabelText( 'Goal' ) ).toBeNull();
		expect( screen.queryByLabelText( 'Readers who see A' ) ).toBeNull();
		expect( screen.getByText( MISSING_BLOCK_FROM_CONTROL ) ).toBeTruthy();
	} );

	it( 'reads the summary from the published control when a drafted old A is still in the test', async () => {
		apiFetch.mockResolvedValue( [
			{ id: 11, variant: 'a', title: 'Old A', goal: 'donation', control_share: 70, status: 'draft', status_label: 'Draft', edit_link: null },
			{ id: 12, variant: 'a', title: 'New A', goal: '', control_share: 60, status: 'publish', status_label: 'Published', edit_link: null },
		] );

		renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'b' } );

		expect( await screen.findByText( 'No goal set on version A. 60% see A, 40% see B.' ) ).toBeTruthy();
	} );

	it( 'holds the challenger Goal row until the lookup for the current test name resolves', async () => {
		let resolve;
		apiFetch.mockReturnValue( new Promise( r => ( resolve = r ) ) );

		renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'b', newspack_popups_ab_test_goal: 'donation' } );

		expect( screen.queryByLabelText( 'Goal' ) ).toBeNull();
		expect( screen.queryByText( MISSING_BLOCK ) ).toBeNull();
		resolve( [] );
		expect( await screen.findByLabelText( 'Goal' ) ).toBeTruthy();
	} );

	it( 'releases the held Goal row when the lookup fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'offline' ) );

		renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'b' } );

		expect( await screen.findByLabelText( 'Goal' ) ).toBeTruthy();
	} );

	it( 'says the split starts once version B is added, until a challenger exists', async () => {
		const { unmount } = renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'a', newspack_popups_ab_control_share: 60 } );
		expect( await screen.findByText( '60% will see A once version B is added.' ) ).toBeTruthy();
		unmount();

		apiFetch.mockResolvedValue( [
			{ id: 3, variant: 'b', title: 'Other', goal: '', control_share: 50, status: 'draft', status_label: 'Draft', edit_link: null },
		] );
		renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'a', newspack_popups_ab_control_share: 60 } );
		expect( await screen.findByText( '60% see A, 40% see B' ) ).toBeTruthy();
	} );

	it( 'keeps the Goal select on a challenger while the test has no control', async () => {
		apiFetch.mockResolvedValue( [
			{ id: 3, variant: 'b', title: 'Other', goal: '', control_share: 50, status: 'draft', status_label: 'Draft', edit_link: null },
		] );

		renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'b' } );

		expect( await screen.findByText( 'B (challenger) · Draft' ) ).toBeTruthy();
		expect( screen.getByLabelText( 'Goal' ) ).toBeTruthy();
	} );

	it( 'keeps the Goal select on the control', async () => {
		apiFetch.mockResolvedValue( [
			{ id: 1, variant: 'b', title: 'Other', goal: '', control_share: 50, status: 'draft', status_label: 'Draft', edit_link: null },
		] );

		renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'a' } );

		expect( await screen.findByText( 'B (challenger) · Draft' ) ).toBeTruthy();
		expect( screen.getByLabelText( 'Goal' ) ).toBeTruthy();
	} );

	it( 'does not show a stored test with no version as the control', async () => {
		apiFetch.mockResolvedValue( [
			{ id: 1, variant: '', title: 'Other', status: 'draft', status_label: 'Draft', edit_link: 'http://example.test/post.php?post=1' },
		] );

		renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: '' } );

		const option = screen.getByRole( 'option', { name: 'Select a version' } );
		expect( option.disabled ).toBe( true );
		expect( screen.getByLabelText( 'Version' ).value ).toBe( '' );
		expect( await screen.findByText( 'No version · Draft' ) ).toBeTruthy();
		expect( onMetaFieldChange ).not.toHaveBeenCalled();
	} );

	it( 'lists the other prompts in the test and warns about a duplicate version', async () => {
		apiFetch.mockResolvedValue( [
			{ id: 1, variant: 'a', title: 'Control', status: 'publish', status_label: 'Published', edit_link: 'http://example.test/post.php?post=1' },
			{ id: 2, variant: 'a', title: 'Me', status: 'draft', status_label: 'Draft', edit_link: 'http://example.test/post.php?post=2' },
		] );

		renderPanel( { newspack_popups_ab_test_id: 'x', newspack_popups_ab_variant: 'a' } );

		const link = await screen.findByText( 'Control' );
		expect( link.getAttribute( 'href' ) ).toBe( 'http://example.test/post.php?post=1' );
		expect( screen.getByText( 'A (control) · Published' ) ).toBeTruthy();
		expect( screen.queryByText( 'Me' ) ).toBeNull();
		expect( screen.getByRole( 'list', { name: 'Other versions in this test' } ) ).toBeTruthy();
		expect(
			screen.getByText(
				'Another prompt in this test is already version A. Clear the test name on one of them, or give one a different version.'
			)
		).toBeTruthy();
		expect( screen.getByText( /already version A/ ).dataset.status ).toBe( 'error' );
		expect( screen.getByText( /already version A/ ).dataset.spoken ).toBe(
			'Another prompt in this test is already version A. Clear the test name on one of them, or give one a different version.'
		);
		expect( apiFetch ).toHaveBeenCalledWith( { path: '/newspack-popups/v1/ab-tests/x' } );
	} );
} );
