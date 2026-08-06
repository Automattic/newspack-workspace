/**
 * Changing a rule's goal from the form. The picker is a modal, not a route, so the
 * form never unmounts and the new goal re-seeds only what it owns.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, act } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import RuleForm from './rule-form';
import { pathDescription } from './recipes';

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( {} ) ) );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( { WIZARD_STORE_NAMESPACE: 'test/goal-modal' } ) );

// The Save action lives in the wizard header, so tests reach submit() through the
// last header data the form published.
let headerData = {};
let notices = [];

register(
	createReduxStore( 'test/goal-modal', {
		reducer: ( state = {} ) => state,
		actions: {
			setHeaderData: data => {
				headerData = data;
				return { type: 'NOOP' };
			},
			addNotice: notice => {
				notices.push( notice );
				return { type: 'NOOP' };
			},
		},
	} )
);

jest.mock( '../../../../../packages/components/src', () => {
	const passthrough = ( { children } ) => <div>{ children }</div>;
	const Card = ( { __experimentalCoreProps: p } ) => (
		<button type="button" onClick={ p.onClick } role={ p.role } aria-checked={ p[ 'aria-checked' ] } tabIndex={ p.tabIndex }>
			{ p.header }
		</button>
	);
	const AutocompleteTokenField = ( { label, onChange } ) => <button type="button" onClick={ () => onChange( [ 1 ] ) }>{ `Set ${ label }` }</button>;
	const history = { push: jest.fn(), replace: jest.fn() };
	return {
		Card,
		AutocompleteTokenField,
		Grid: passthrough,
		SectionHeader: () => null,
		Divider: () => null,
		Router: { useHistory: () => history, useLocation: () => ( { pathname: '/new' } ) },
	};
} );

jest.mock( './scope-targets', () => () => null );
jest.mock( './rule-preview', () => () => null );

const VOCAB = {
	strategies: [ { id: 'simple_price', label: 'Simple' } ],
	scopes: [
		{ id: 'all_products', label: 'All products' },
		{ id: 'all_subscriptions', label: 'All subscriptions' },
	],
	calc_types: [ { value: 'fixed_price', label: 'Fixed' } ],
	currency: { code: 'USD', symbol: '$', decimals: 2 },
	conditions: [
		{ id: 'reader_segment', label: 'Reader segment', field_type: 'select', options: [ { value: 1, label: 'At risk' } ] },
		{ id: 'cohort_start', label: 'Subscriptions started on or after', field_type: 'datetime' },
		{ id: 'first_time_only', label: 'First-time buyers only', field_type: 'boolean' },
		{ id: 'lapsed_subscriber', label: 'Lapsed subscribers only', field_type: 'boolean' },
		{ id: 'pending_cancellation', label: 'Cancelling subscribers only', field_type: 'boolean' },
	],
};

const routerHistory = () => require( '../../../../../packages/components/src' ).Router.useHistory();

async function renderForm( initialPath ) {
	let result;
	await act( async () => {
		result = render( createElement( RuleForm, { isNew: true, initialPath, rule: null, vocab: VOCAB, onDone: jest.fn() } ) );
	} );
	return result;
}

const field = label => screen.getByLabelText( label );
const appliesTo = () => [ ...document.querySelectorAll( 'select' ) ].find( s => [ ...s.options ].some( o => o.value === 'all_subscriptions' ) );

async function changeGoalTo( label ) {
	await act( async () => {
		fireEvent.click( screen.getByRole( 'button', { name: 'Change goal' } ) );
	} );
	await act( async () => {
		fireEvent.click( screen.getByRole( 'radio', { name: new RegExp( label ) } ) );
	} );
	await act( async () => {
		fireEvent.click( screen.getByRole( 'button', { name: 'Update Goal' } ) );
	} );
}

// WP's Modal closes on a press that starts and ends on the overlay, and reads
// `button` off it, which jsdom's PointerEvent stand-in does not carry.
const pressBackdrop = overlay => {
	[ 'pointerdown', 'pointerup' ].forEach( type => {
		overlay.dispatchEvent( new window.MouseEvent( type, { bubbles: true, cancelable: true, button: 0 } ) );
	} );
};

/** Fire the header's Save action and return the body it posted. */
async function save() {
	await act( async () => {
		headerData.actions[ 0 ].action();
	} );
	return apiFetch.mock.calls[ apiFetch.mock.calls.length - 1 ][ 0 ].data;
}

describe( 'changing the goal from the form', () => {
	beforeEach( () => {
		apiFetch.mockClear();
		routerHistory().replace.mockClear();
		routerHistory().push.mockClear();
		headerData = {};
		notices = [];
	} );

	it( 'keeps everything typed and re-seeds only what the goal owns', async () => {
		await renderForm( 'custom' );
		fireEvent.change( field( 'Name' ), { target: { value: 'Loyalty deal' } } );
		fireEvent.change( field( 'Value' ), { target: { value: '12.5' } } );
		fireEvent.change( field( 'Starts' ), { target: { value: '2026-09-01T10:00' } } );
		expect( appliesTo().value ).toBe( 'all_products' );

		await changeGoalTo( 'Subscription Retention' );

		expect( field( 'Name' ) ).toHaveValue( 'Loyalty deal' );
		expect( field( 'Value' ) ).toHaveValue( 12.5 );
		expect( field( 'Starts' ) ).toHaveValue( '2026-09-01T10:00' );
		expect( appliesTo().value ).toBe( 'all_subscriptions' );
	} );

	it( 'lets the name follow the new goal while it is still automatic', async () => {
		await renderForm( 'retention' );
		expect( field( 'Name' ) ).toHaveValue( 'Subscription Retention' );

		await changeGoalTo( 'Win-Back' );
		expect( field( 'Name' ) ).toHaveValue( 'Win-Back' );
	} );

	it( 'lets the name follow the goal again once the publisher clears it', async () => {
		await renderForm( 'retention' );
		fireEvent.change( field( 'Name' ), { target: { value: 'My own name' } } );

		await changeGoalTo( 'Win-Back' );
		expect( field( 'Name' ) ).toHaveValue( 'My own name' );

		fireEvent.change( field( 'Name' ), { target: { value: '' } } );
		await changeGoalTo( 'Save' );
		expect( field( 'Name' ) ).toHaveValue( 'Save' );
	} );

	it( 'does not apply anything until Update Goal is pressed', async () => {
		await renderForm( 'custom' );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Change goal' } ) );
		} );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: /Subscription Retention/ } ) );
		} );
		expect( appliesTo().value ).toBe( 'all_products' );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		} );
		expect( appliesTo().value ).toBe( 'all_products' );
	} );

	it( 'checks one goal at a time in the picker', async () => {
		await renderForm( 'custom' );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Change goal' } ) );
		} );
		expect( screen.getByRole( 'radio', { name: /Custom/ } ) ).toBeChecked();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: /Win-Back/ } ) );
		} );
		expect( screen.getByRole( 'radio', { name: /Win-Back/ } ) ).toBeChecked();
		expect( screen.getByRole( 'radio', { name: /Custom/ } ) ).not.toBeChecked();
	} );

	it( 'keeps the URL on the goal the form is showing', async () => {
		await renderForm( 'custom' );
		await changeGoalTo( 'Win-Back' );
		expect( routerHistory().replace ).toHaveBeenLastCalledWith( '/new/winback' );
	} );

	// A reload, bookmark or shared #/new/<goal> mounts the form with the goal already
	// set, so choosePath() never runs and the mount seeds carry the whole recipe.
	it.each( [
		[ 'new_subscriptions', { first_time_only: true }, 'all_subscriptions', 'locked', 'subscription_start' ],
		[ 'retention', {}, 'all_subscriptions', 'current', 'rule_application' ],
		[ 'save', { pending_cancellation: true }, 'all_subscriptions', 'locked', 'subscription_start' ],
		[ 'winback', { lapsed_subscriber: true }, 'all_subscriptions', 'locked', 'subscription_start' ],
	] )( 'applies the %s recipe on a cold load of its URL', async ( intent, conditions, scopeType, application, cycleAnchor ) => {
		await renderForm( intent );
		fireEvent.change( field( 'Value' ), { target: { value: '5' } } );

		const body = await save();
		expect( body.intent ).toBe( intent );
		expect( body.conditions ).toEqual( conditions );
		expect( body.scope_type ).toBe( scopeType );
		expect( body.application ).toBe( application );
		expect( body.cycle_anchor ).toBe( cycleAnchor );
	} );

	it( 'leaves the name empty on a cold load of the Custom URL', async () => {
		await renderForm( 'custom' );
		expect( field( 'Name' ) ).toHaveValue( '' );
	} );

	it( 'adopts a goal changed in the URL from outside the form', async () => {
		const { rerender } = await renderForm( 'retention' );
		fireEvent.change( field( 'Value' ), { target: { value: '5' } } );

		await act( async () => {
			rerender( createElement( RuleForm, { isNew: true, initialPath: 'save', rule: null, vocab: VOCAB, onDone: jest.fn() } ) );
		} );

		const body = await save();
		expect( body.intent ).toBe( 'save' );
		expect( body.conditions ).toEqual( { pending_cancellation: true } );
		expect( body.simple.value ).toBe( 5 );
	} );

	it( 'puts a goal-less URL back on the goal it is holding', async () => {
		const { rerender } = await renderForm( 'retention' );
		fireEvent.change( field( 'Value' ), { target: { value: '5' } } );

		await act( async () => {
			rerender( createElement( RuleForm, { isNew: true, initialPath: null, rule: null, vocab: VOCAB, onDone: jest.fn() } ) );
		} );

		expect( routerHistory().replace ).toHaveBeenLastCalledWith( '/new/retention' );
		const body = await save();
		expect( body.intent ).toBe( 'retention' );
		expect( body.simple.value ).toBe( 5 );
	} );

	it( 'leaves the URL alone at #/new before any goal is chosen', async () => {
		await renderForm( null );
		expect( routerHistory().replace ).not.toHaveBeenCalled();
	} );

	it( 'drops a condition the new goal cannot show, and keeps the one it can', async () => {
		await renderForm( 'custom' );
		fireEvent.change( field( 'Name' ), { target: { value: 'Loyalty deal' } } );
		fireEvent.change( field( 'Value' ), { target: { value: '5' } } );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Set Reader segment' } ) );
		} );

		await changeGoalTo( 'Win-Back' );

		expect( ( await save() ).conditions ).toEqual( { reader_segment: [ 1 ], lapsed_subscriber: true } );
	} );

	it( 'resets the Custom-only priority and compose mode under a named goal', async () => {
		await renderForm( 'custom' );
		fireEvent.change( field( 'Name' ), { target: { value: 'Loyalty deal' } } );
		fireEvent.change( field( 'Value' ), { target: { value: '5' } } );
		fireEvent.change( field( 'Priority' ), { target: { value: '5' } } );
		fireEvent.change( field( 'When multiple rules match' ), { target: { value: 'priority_exclusive' } } );

		await changeGoalTo( 'Win-Back' );

		const body = await save();
		expect( body.priority ).toBe( 100 );
		expect( body.compose_mode ).toBe( 'min' );
	} );

	it( 'opens on load with no goal, and cannot be dismissed', async () => {
		await renderForm( null );
		expect( screen.getByRole( 'button', { name: 'Select Goal' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Cancel' } ) ).toBeNull();
		expect( screen.getByRole( 'link', { name: 'Back' } ) ).toHaveAttribute( 'href', '#/' );
		expect( document.querySelector( '.components-modal__header button' ) ).toBeNull();

		const overlay = document.querySelector( '.components-modal__screen-overlay' );
		await act( async () => {
			pressBackdrop( overlay );
			await new Promise( resolve => setTimeout( resolve, 400 ) );
		} );
		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Select Goal' } ) ).toBeInTheDocument();
	} );

	it( 'keeps the first-run action reachable while no goal is pending', async () => {
		await renderForm( null );
		const confirm = screen.getByRole( 'button', { name: 'Select Goal' } );
		expect( confirm ).not.toBeDisabled();
		expect( confirm ).toHaveAttribute( 'aria-disabled', 'true' );
		act( () => {
			confirm.focus();
		} );
		expect( confirm ).toHaveFocus();
	} );

	it( 'ignores Escape before a goal is chosen', async () => {
		await renderForm( null );
		await act( async () => {
			fireEvent.keyDown( screen.getByRole( 'button', { name: 'Select Goal' } ), { key: 'Escape', code: 'Escape' } );
			await new Promise( resolve => setTimeout( resolve, 400 ) );
		} );
		expect( screen.getByRole( 'button', { name: 'Select Goal' } ) ).toBeInTheDocument();
	} );

	it( 'lands focus on the goal field once the first goal is chosen', async () => {
		await renderForm( null );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: /Win-Back/ } ) );
		} );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Select Goal' } ) );
		} );
		expect( screen.getByRole( 'button', { name: 'Change goal' } ) ).toHaveFocus();
	} );

	// The first run is consumed here, so the second dismissal must leave focus to WP's
	// own return, which lands on whatever was focused before the picker opened.
	it( 'does not re-run the first-run focus when a later visit to the picker is dismissed', async () => {
		await renderForm( null );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: /Win-Back/ } ) );
		} );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Select Goal' } ) );
		} );
		const changeGoal = screen.getByRole( 'button', { name: 'Change goal' } );
		expect( changeGoal ).toHaveFocus();

		act( () => {
			field( 'Name' ).focus();
		} );
		await act( async () => {
			fireEvent.click( changeGoal );
		} );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		} );

		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( changeGoal ).not.toHaveFocus();
	} );

	it( 'describes the goal field with the help text it renders', async () => {
		await renderForm( 'retention' );
		const help = document.getElementById( field( 'Goal' ).getAttribute( 'aria-describedby' ) );
		expect( help ).toHaveTextContent( pathDescription( 'retention' ) );
	} );

	it( 'describes the picker with the paragraph inside it', async () => {
		await renderForm( 'retention' );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Change goal' } ) );
		} );
		const dialog = screen.getByRole( 'dialog' );
		const description = document.getElementById( dialog.getAttribute( 'aria-describedby' ) );
		expect( dialog ).toContainElement( description );
		expect( description ).toHaveTextContent( /goal/i );
	} );

	it( 'says Update Goal once a goal is already set', async () => {
		await renderForm( 'retention' );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Change goal' } ) );
		} );
		expect( screen.getByRole( 'button', { name: 'Update Goal' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Cancel' } ) ).toBeInTheDocument();
	} );

	it( 'refuses to re-apply the goal already on the form', async () => {
		await renderForm( 'retention' );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Change goal' } ) );
		} );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: /Subscription Retention/ } ) );
		} );

		const confirm = screen.getByRole( 'button', { name: 'Update Goal' } );
		expect( confirm ).not.toBeDisabled();
		expect( confirm ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	it( 'offers no Change on a saved rule', async () => {
		const rule = {
			id: 3,
			title: 'Saved',
			intent: 'retention',
			status: 'publish',
			simple: { calc_type: 'fixed_price', value: 4, cycles_limit: 0, label: '' },
		};
		await act( async () => {
			render( createElement( RuleForm, { isNew: false, initialPath: null, rule, vocab: VOCAB, onDone: jest.fn() } ) );
		} );
		expect( screen.queryByRole( 'button', { name: 'Change goal' } ) ).toBeNull();
	} );

	it( 'falls back to Custom on a saved rule that has no goal', async () => {
		const rule = {
			id: 4,
			title: 'Legacy',
			status: 'publish',
			simple: { calc_type: 'fixed_price', value: 4, cycles_limit: 0, label: '' },
		};
		await act( async () => {
			render( createElement( RuleForm, { isNew: false, initialPath: null, rule, vocab: VOCAB, onDone: jest.fn() } ) );
		} );
		expect( field( 'Goal' ) ).toHaveValue( 'Custom' );
		expect( field( 'Priority' ) ).toBeInTheDocument();
	} );

	it( 'falls back to Custom on a saved rule whose goal is blank', async () => {
		const rule = {
			id: 5,
			title: 'Legacy',
			intent: '',
			status: 'publish',
			simple: { calc_type: 'fixed_price', value: 4, cycles_limit: 0, label: '' },
		};
		await act( async () => {
			render( createElement( RuleForm, { isNew: false, initialPath: null, rule, vocab: VOCAB, onDone: jest.fn() } ) );
		} );
		expect( field( 'Goal' ) ).toHaveValue( 'Custom' );
		expect( field( 'Priority' ) ).toBeInTheDocument();
		expect( ( await save() ).intent ).toBe( 'custom' );
	} );

	it( 'still opens the picker on a new rule with no goal at all', async () => {
		await renderForm( null );
		expect( field( 'Goal' ) ).toHaveValue( '' );
		expect( screen.getByRole( 'button', { name: 'Select Goal' } ) ).toBeInTheDocument();
	} );

	it( 'refuses to save a rule with no goal', async () => {
		await renderForm( null );
		fireEvent.change( field( 'Name' ), { target: { value: 'Loyalty deal' } } );
		fireEvent.change( field( 'Value' ), { target: { value: '5' } } );

		await act( async () => {
			headerData.actions[ 0 ].action();
		} );

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( notices ).toContainEqual( expect.objectContaining( { id: 'pricing-rule-path', type: 'error' } ) );
	} );
} );
