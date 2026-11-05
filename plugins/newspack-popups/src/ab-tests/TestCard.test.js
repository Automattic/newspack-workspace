/**
 * The card's own decisions: when results load on open, when Refresh is offered,
 * and how the ineligible-placement notice reads. The stats route is mocked, and
 * `@wordpress/ui` is stubbed so the tests read the card's logic, not its layout.
 */

/**
 * External dependencies
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import TestCard from './TestCard';

let mockModalProps = null;
jest.mock( './components/StartTestModal', () => props => {
	mockModalProps = props;
	return <div role="dialog" />;
} );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/ui', () => ( {
	Badge: ( { children } ) => <span>{ children }</span>,
	Stack: ( { children } ) => <div>{ children }</div>,
} ) );

const data = {
	rest_base: 'newspack-popups/v1',
	provider: { available: true },
	labels: { collecting: 'Collecting', directional: 'Directional', reliable: 'Reliable' },
	copy: { young_test: 'Young test copy.', high_anonymous: 'Anonymous copy.' },
};

const test = {
	test_id: 'hero',
	goal: 'link_click',
	goal_label: 'Link click',
	control_share: 50,
	started_at: '2026-10-01T00:00:00Z',
	variants: {
		a: { id: 1, title: 'Control', status: 'publish', status_label: 'Published', eligible: true },
		b: { id: 2, title: 'Challenger', status: 'publish', status_label: 'Published', eligible: true },
	},
};

const stats = {
	source: { state: 'ok', provider: 'stub', reason: null, fetched_at: null, cached: false, unit: 'readers' },
	variants: {
		a: { readers: 10, conversions: 1, rate: 10, lift: null },
		b: { readers: 10, conversions: 2, rate: 20, lift: 100 },
	},
	comparisons: { b: { confidence: null, label: 'collecting' } },
	flags: { young_test: false, high_anonymous: false },
};

describe( 'TestCard', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( stats );
	} );

	it( 'offers Create Version B on an Incomplete test that still has its A, starting from its goal and split', () => {
		const incomplete = { ...test, name: 'Hero', control_share: 70, started_at: null, variants: { a: test.variants.a } };
		render( <TestCard test={ incomplete } data={ { ...data, goals: { link_click: 'Link click' } } } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Create Version B' } ) );

		expect( screen.getByRole( 'dialog' ) ).toBeTruthy();
		expect( mockModalProps ).toMatchObject( { promptId: 1, promptTitle: 'Hero', initialGoal: 'link_click', initialShare: 70 } );
	} );

	it( 'offers no Create Version B on an Incomplete test with no A to copy', () => {
		render( <TestCard test={ { ...test, started_at: null, variants: { b: test.variants.b } } } data={ data } /> );
		expect( screen.queryByRole( 'button', { name: 'Create Version B' } ) ).toBeNull();
		expect( screen.getByText( 'This test has no version A, so it can’t run.' ) ).toBeTruthy();
	} );

	it( 'loads results on open for a started test with a stats source, and offers Refresh', async () => {
		render( <TestCard test={ test } data={ data } /> );
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledWith( { path: '/newspack-popups/v1/ab-tests/hero/stats' } ) );
		expect( await screen.findByRole( 'button', { name: 'Refresh' } ) ).toBeTruthy();
	} );

	it( 'heads the card with the test name, and still loads results by its ID', async () => {
		render( <TestCard test={ { ...test, name: 'Homepage hero copy' } } data={ data } /> );
		expect( screen.getByRole( 'heading', { name: 'Homepage hero copy' } ) ).toBeTruthy();
		expect( screen.getByRole( 'region', { name: 'Results for Homepage hero copy' } ) ).toBeTruthy();
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledWith( { path: '/newspack-popups/v1/ab-tests/hero/stats' } ) );
	} );

	it( 'does not load, and hides Refresh, when no stats source is connected', () => {
		render( <TestCard test={ test } data={ { ...data, provider: { available: false } } } /> );
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( screen.queryByRole( 'button', { name: 'Refresh' } ) ).toBeNull();
	} );

	it( 'does not load, and hides Refresh, for a test that has not started', () => {
		render( <TestCard test={ { ...test, started_at: null } } data={ data } /> );
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( screen.queryByRole( 'button', { name: 'Refresh' } ) ).toBeNull();
	} );

	it( 'names each version on an unsupported placement, singular and plural', () => {
		// No source connected, so the card does not also start a load here.
		const quiet = { ...data, provider: { available: false } };
		const { unmount } = render(
			<TestCard test={ { ...test, variants: { ...test.variants, b: { ...test.variants.b, eligible: false } } } } data={ quiet } />
		);
		expect( screen.getByText( /^Version B uses a manual or custom placement/ ) ).toBeTruthy();
		unmount();

		render(
			<TestCard
				test={ { ...test, variants: { a: { ...test.variants.a, eligible: false }, b: { ...test.variants.b, eligible: false } } } }
				data={ quiet }
			/>
		);
		expect( screen.getByText( /^Versions A and B use a manual or custom placement/ ) ).toBeTruthy();
	} );
} );
