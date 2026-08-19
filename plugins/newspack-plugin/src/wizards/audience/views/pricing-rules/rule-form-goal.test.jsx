/**
 * Choosing a rule's goal. The picker is the form's first section, not a route or a
 * dialog, so the form never unmounts and the new goal re-seeds only what it owns.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, act, within } from '@testing-library/react';

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

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( {} ) ) );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( { WIZARD_STORE_NAMESPACE: 'test/goal' } ) );

// The Save action lives in the wizard header, so tests reach submit() through the
// last header data the form published.
let headerData = {};
let notices = [];

register(
	createReduxStore( 'test/goal', {
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
	const { useState: useStateMock } = require( '@wordpress/element' );
	const passthrough = ( { children } ) => <div>{ children }</div>;
	const Card = ( { __experimentalCoreProps: p } ) => (
		<button
			type="button"
			onClick={ p.onClick }
			role={ p.role }
			aria-checked={ p[ 'aria-checked' ] }
			aria-disabled={ p[ 'aria-disabled' ] }
			tabIndex={ p.tabIndex }
		>
			{ p.header }
		</button>
	);
	const AutocompleteTokenField = ( { label, onChange } ) => <button type="button" onClick={ () => onChange( [ 1 ] ) }>{ `Set ${ label }` }</button>;
	const history = { push: jest.fn(), replace: jest.fn() };
	// Mirrors the real hook: `when === false` skips the prompt and runs straight away.
	const useConfirmDialog = ( { when, message, title, confirmButtonText } ) => {
		const [ pending, setPending ] = useStateMock( null );
		return {
			confirmDialog: pending ? (
				<div role="dialog" aria-label={ title }>
					{ message }
					<button
						type="button"
						onClick={ () => {
							pending();
							setPending( null );
						} }
					>
						{ confirmButtonText }
					</button>
					<button type="button" onClick={ () => setPending( null ) }>
						Cancel
					</button>
				</div>
			) : null,
			requestConfirm: callback => {
				if ( when === false ) {
					callback();
				} else {
					setPending( () => callback );
				}
			},
			cancelConfirm: () => setPending( null ),
		};
	};
	return {
		Card,
		AutocompleteTokenField,
		Grid: passthrough,
		SectionHeader: () => null,
		Divider: () => null,
		useConfirmDialog,
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

async function renderSavedRule( rule ) {
	await act( async () => {
		render( createElement( RuleForm, { isNew: false, initialPath: null, rule, vocab: VOCAB, onDone: jest.fn() } ) );
	} );
}

const field = label => screen.getByLabelText( label );
// Scoped: the status and pricing-details toggles are radios too.
const goals = () => within( screen.getByRole( 'radiogroup', { name: 'Rule goal' } ) );
const goal = label => goals().getByRole( 'radio', { name: new RegExp( label ) } );
const appliesTo = () => [ ...document.querySelectorAll( 'select' ) ].find( s => [ ...s.options ].some( o => o.value === 'all_subscriptions' ) );
const startsToggle = () => screen.getByRole( 'button', { name: /^Starts:/ } );

/** Choose the first day the schedule popover offers, and return what the toggle then reads. */
function pickStartDate() {
	const before = startsToggle().textContent;
	fireEvent.click( startsToggle() );
	fireEvent.click( screen.getAllByRole( 'button', { name: /^[A-Z][a-z]+ \d{1,2}, \d{4}$/ } )[ 0 ] );
	fireEvent.click( startsToggle() );
	const picked = startsToggle().textContent;
	expect( picked ).not.toBe( before );
	return picked;
}

/** Pick a goal. Confirms the warning when one is raised. */
async function changeGoalTo( label ) {
	await act( async () => {
		fireEvent.click( goal( label ) );
	} );
	const confirm = screen.queryByRole( 'button', { name: 'Change Goal' } );
	if ( confirm ) {
		await act( async () => {
			fireEvent.click( confirm );
		} );
	}
}

/** Fire the header's Save action and return the body it posted. */
async function save() {
	await act( async () => {
		headerData.actions[ 0 ].action();
	} );
	return apiFetch.mock.calls[ apiFetch.mock.calls.length - 1 ][ 0 ].data;
}

describe( 'choosing the goal from the form', () => {
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
		fireEvent.change( field( /^Value/ ), { target: { value: '12.5' } } );
		const starts = pickStartDate();
		expect( appliesTo().value ).toBe( 'all_products' );

		await changeGoalTo( 'Retention' );

		expect( field( 'Name' ) ).toHaveValue( 'Loyalty deal' );
		expect( field( /^Value/ ) ).toHaveValue( 12.5 );
		expect( startsToggle().textContent ).toBe( starts );
		expect( appliesTo().value ).toBe( 'all_subscriptions' );
	} );

	it( 'lets the name follow the new goal while it is still automatic', async () => {
		await renderForm( 'retention' );
		expect( field( 'Name' ) ).toHaveValue( 'Retention' );

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

	it( 'checks one goal at a time', async () => {
		await renderForm( 'custom' );
		expect( goal( 'Custom' ) ).toBeChecked();

		await changeGoalTo( 'Win-Back' );
		expect( goal( 'Win-Back' ) ).toBeChecked();
		expect( goal( 'Custom' ) ).not.toBeChecked();
	} );

	it( 'does nothing when the goal already on the form is picked again', async () => {
		await renderForm( 'retention' );
		await act( async () => {
			fireEvent.click( goal( 'Retention' ) );
		} );

		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( routerHistory().replace ).not.toHaveBeenCalled();
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
		fireEvent.change( field( /^Value/ ), { target: { value: '5' } } );

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
		fireEvent.change( field( /^Value/ ), { target: { value: '5' } } );

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
		fireEvent.change( field( /^Value/ ), { target: { value: '5' } } );

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
		fireEvent.change( field( /^Value/ ), { target: { value: '5' } } );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Set Reader segment' } ) );
		} );

		await changeGoalTo( 'Win-Back' );

		expect( ( await save() ).conditions ).toEqual( { reader_segment: [ 1 ], lapsed_subscriber: true } );
	} );

	it( 'renders the picker with nothing chosen on a new rule', async () => {
		await renderForm( null );
		expect( goals().getAllByRole( 'radio' ) ).toHaveLength( 5 );
		expect( goals().queryByRole( 'radio', { checked: true } ) ).toBeNull();
	} );

	it( 'refuses to save a rule with no goal', async () => {
		await renderForm( null );
		fireEvent.change( field( 'Name' ), { target: { value: 'Loyalty deal' } } );
		fireEvent.change( field( /^Value/ ), { target: { value: '5' } } );

		await act( async () => {
			headerData.actions[ 0 ].action();
		} );

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( notices ).toContainEqual( expect.objectContaining( { id: 'pricing-rule-path', type: 'error' } ) );
	} );

	describe( 'warning before Custom settings are discarded', () => {
		it( 'switches straight away when Custom is holding nothing that would be lost', async () => {
			await renderForm( 'custom' );
			fireEvent.change( field( /^Value/ ), { target: { value: '5' } } );

			await act( async () => {
				fireEvent.click( goal( 'Win-Back' ) );
			} );

			expect( screen.queryByRole( 'dialog' ) ).toBeNull();
			expect( goal( 'Win-Back' ) ).toBeChecked();
		} );

		it( 'never warns when leaving a named goal, which owns none of those fields', async () => {
			await renderForm( 'retention' );

			await act( async () => {
				fireEvent.click( goal( 'Win-Back' ) );
			} );

			expect( screen.queryByRole( 'dialog' ) ).toBeNull();
			expect( goal( 'Win-Back' ) ).toBeChecked();
		} );

		it( 'names the Custom fields a named goal would drop', async () => {
			await renderForm( 'custom' );
			fireEvent.change( field( 'Priority' ), { target: { value: '5' } } );
			fireEvent.change( field( 'When multiple rules match' ), { target: { value: 'priority_exclusive' } } );

			await act( async () => {
				fireEvent.click( goal( 'Win-Back' ) );
			} );

			const dialog = screen.getByRole( 'dialog' );
			expect( dialog ).toHaveTextContent( 'Priority' );
			expect( dialog ).toHaveTextContent( 'When multiple rules match' );
		} );

		it( 'names the cohort date once the publisher picks one, since a named goal never shows it', async () => {
			await renderForm( 'custom' );
			await act( async () => {
				fireEvent.change( field( 'Subscriptions started on or after' ), { target: { value: 'custom' } } );
			} );

			await act( async () => {
				fireEvent.click( goal( 'Win-Back' ) );
			} );

			expect( screen.getByRole( 'dialog' ) ).toHaveTextContent( 'Subscriptions started on or after' );
		} );

		// A new rule auto-applies the publish date; warning about a default nobody
		// chose would put a dialog on every switch out of Custom.
		it( 'stays quiet about a cohort date left on the publish-date default', async () => {
			await renderForm( 'custom' );
			expect( field( 'Subscriptions started on or after' ) ).toHaveValue( 'publish' );

			await act( async () => {
				fireEvent.click( goal( 'Win-Back' ) );
			} );

			expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		} );

		it( 'keeps the Custom settings and the goal when the warning is cancelled', async () => {
			await renderForm( 'custom' );
			fireEvent.change( field( 'Priority' ), { target: { value: '5' } } );

			await act( async () => {
				fireEvent.click( goal( 'Win-Back' ) );
			} );
			await act( async () => {
				fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
			} );

			expect( goal( 'Custom' ) ).toBeChecked();
			expect( field( 'Priority' ) ).toHaveValue( 5 );
		} );

		it( 'resets the Custom-only priority and compose mode once confirmed', async () => {
			await renderForm( 'custom' );
			fireEvent.change( field( 'Name' ), { target: { value: 'Loyalty deal' } } );
			fireEvent.change( field( /^Value/ ), { target: { value: '5' } } );
			fireEvent.change( field( 'Priority' ), { target: { value: '5' } } );
			fireEvent.change( field( 'When multiple rules match' ), { target: { value: 'priority_exclusive' } } );

			await changeGoalTo( 'Win-Back' );

			const body = await save();
			expect( body.priority ).toBe( 100 );
			expect( body.compose_mode ).toBe( 'min' );
		} );
	} );

	describe( 'on a saved rule', () => {
		const savedRule = intent => ( {
			id: 3,
			title: 'Saved',
			intent,
			status: 'publish',
			simple: { calc_type: 'fixed_price', value: 4, cycles_limit: 0, label: '' },
		} );

		it( 'shows the whole set with the chosen goal checked and every card disabled', async () => {
			await renderSavedRule( savedRule( 'retention' ) );

			expect( goals().getAllByRole( 'radio' ) ).toHaveLength( 5 );
			expect( goals().getByRole( 'radio', { checked: true } ) ).toHaveAccessibleName( /^Retention/ );
			goals()
				.getAllByRole( 'radio' )
				.forEach( radio => {
					expect( radio ).toHaveAttribute( 'aria-disabled', 'true' );
				} );
		} );

		it( 'refuses to change the goal', async () => {
			await renderSavedRule( savedRule( 'retention' ) );

			await act( async () => {
				fireEvent.click( goals().getByRole( 'radio', { name: /Win-Back/ } ) );
			} );

			expect( screen.queryByRole( 'dialog' ) ).toBeNull();
			expect( goals().getByRole( 'radio', { checked: true } ) ).toHaveAccessibleName( /^Retention/ );
		} );

		it( 'falls back to Custom when the rule has no goal', async () => {
			const rule = savedRule( undefined );
			rule.id = 4;
			await renderSavedRule( rule );

			expect( goals().getByRole( 'radio', { checked: true } ) ).toHaveAccessibleName( /^Custom/ );
			expect( field( 'Priority' ) ).toBeInTheDocument();
		} );

		it( 'falls back to Custom when the rule’s goal is blank', async () => {
			const rule = savedRule( '' );
			rule.id = 5;
			await renderSavedRule( rule );

			expect( goals().getByRole( 'radio', { checked: true } ) ).toHaveAccessibleName( /^Custom/ );
			expect( field( 'Priority' ) ).toBeInTheDocument();
			expect( ( await save() ).intent ).toBe( 'custom' );
		} );
	} );
} );
