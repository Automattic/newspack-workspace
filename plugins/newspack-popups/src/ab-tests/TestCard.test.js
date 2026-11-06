/**
 * The card's own decisions: when results load on open, when Refresh is offered,
 * and how the ineligible-placement, duplicate and split notices read. The stats route is mocked, and
 * `@wordpress/ui` is stubbed so the tests read the card's logic, not its layout.
 */

/**
 * External dependencies
 */
import { render, screen, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import TestCard from './TestCard';

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

	it( 'loads results on open for a started test with a stats source, and offers Refresh', async () => {
		render( <TestCard test={ test } data={ data } /> );
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledWith( { path: '/newspack-popups/v1/ab-tests/hero/stats' } ) );
		expect( await screen.findByRole( 'button', { name: 'Refresh results: hero' } ) ).toBeTruthy();
	} );

	it( 'does not load, and hides Refresh, when no stats source is connected', () => {
		render( <TestCard test={ test } data={ { ...data, provider: { available: false } } } /> );
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( screen.queryByRole( 'button', { name: 'Refresh results: hero' } ) ).toBeNull();
	} );

	it( 'does not load, and hides Refresh, for a test that has not started', () => {
		render( <TestCard test={ { ...test, started_at: null } } data={ data } /> );
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( screen.queryByRole( 'button', { name: 'Refresh results: hero' } ) ).toBeNull();
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

	it( 'says readers see both duplicates only when the extra prompt is published', () => {
		const quiet = { ...data, provider: { available: false } };
		const duplicate = { id: 3, title: 'Extra', letter: 'b', status: 'draft', status_label: 'Draft', eligible: true };
		const { unmount } = render( <TestCard test={ { ...test, duplicates: [ duplicate ] } } data={ quiet } /> );
		expect( screen.getByText( /readers don't see it yet/ ) ).toBeTruthy();
		expect( screen.queryByText( /Readers still see both/ ) ).toBeNull();
		unmount();

		render( <TestCard test={ { ...test, duplicates: [ { ...duplicate, status: 'publish', status_label: 'Published' } ] } } data={ quiet } /> );
		expect( screen.getByText( /Readers still see both/ ) ).toBeTruthy();
	} );

	it( 'labels the split as planned until every version is published', () => {
		const quiet = { ...data, provider: { available: false } };
		const { unmount } = render(
			<TestCard
				test={ { ...test, variants: { ...test.variants, b: { ...test.variants.b, status: 'draft', status_label: 'Draft' } } } }
				data={ quiet }
			/>
		);
		expect( screen.getByRole( 'img', { name: /^Planned split once every version is published/ } ) ).toBeTruthy();
		unmount();

		render( <TestCard test={ test } data={ quiet } /> );
		expect( screen.getByRole( 'img', { name: /^Readers who see each version/ } ) ).toBeTruthy();
	} );
} );
