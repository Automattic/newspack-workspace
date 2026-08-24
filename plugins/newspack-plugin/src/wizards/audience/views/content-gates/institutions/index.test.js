/**
 * NPPD-1492 — the institutions list keeps the gates screen header in sync by
 * writing `config.has_institutions` into the wizard store after each fetch.
 * This is the half the "no reload" behaviour rests on: the gates config is
 * resolved once per page load, so without this write a publisher who creates
 * their first institution and navigates back sees a stale header.
 */

/**
 * External dependencies
 */
import { render, waitFor } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Institutions from './index';

// mock-prefixed so Jest's hoisted jest.mock factories may close over them.
const mockUpdateWizardSettings = jest.fn();
const mockApiFetch = jest.fn();

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: ( ...args ) => mockApiFetch( ...args ) } ) );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( {
		setHeaderData: jest.fn(),
		addNotice: jest.fn(),
		updateWizardSettings: ( ...args ) => mockUpdateWizardSettings( ...args ),
	} ),
} ) );

// The real @wordpress/components and @wordpress/dataviews cannot load in this
// jsdom env (data-store side effects throw at import); the store-sync contract
// under test does not touch either.
jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	const Passthrough = ( { children } ) => React.createElement( 'div', null, children );
	return { Button: Passthrough, Spinner: Passthrough };
} );

jest.mock( '@wordpress/dataviews', () => ( {
	filterSortAndPaginate: data => ( { data, paginationInfo: { totalItems: data.length, totalPages: 1 } } ),
} ) );

// DataViews and the router are irrelevant to the store-sync contract.
jest.mock( '../../../../../../packages/components/src', () => {
	const React = require( 'react' );
	return {
		DataViews: () => React.createElement( 'div', null, 'DataViews' ),
		Router: { useHistory: () => ( { push: jest.fn() } ) },
	};
} );

jest.mock( '../../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

jest.mock( '../consts', () => ( {
	AUDIENCE_CONTENT_GATES_WIZARD_SLUG: 'newspack-audience-access-control',
} ) );

jest.mock( './onboarding', () => () => null );

describe( 'Institutions list — gates header sync (NPPD-1492)', () => {
	beforeEach( () => {
		mockUpdateWizardSettings.mockReset();
		mockApiFetch.mockReset();
	} );

	it.each( [
		{ name: 'has institutions', institutions: [ { id: 1, title: { raw: 'Uni' }, meta: {} } ], expected: true },
		{ name: 'has none', institutions: [], expected: false },
	] )( 'writes has_institutions=$expected when the site $name', async ( { institutions, expected } ) => {
		mockApiFetch.mockResolvedValue( institutions );

		render( <Institutions /> );

		await waitFor( () => expect( mockUpdateWizardSettings ).toHaveBeenCalled() );
		expect( mockUpdateWizardSettings ).toHaveBeenCalledWith( {
			slug: 'newspack-audience-access-control',
			path: [ 'config', 'has_institutions' ],
			value: expected,
		} );
		// The initial fetch changes the value from unknown to its result, so
		// exactly one write happens; the per-instance ref guards later fetches
		// that leave the derived boolean unchanged.
		expect( mockUpdateWizardSettings ).toHaveBeenCalledTimes( 1 );
	} );
} );
