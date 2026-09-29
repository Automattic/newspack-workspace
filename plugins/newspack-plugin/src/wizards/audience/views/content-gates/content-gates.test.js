/**
 * NPPD-1492 — the "Institutions" entry point on the Access Control screen must
 * be plainly visible (header secondary action) when the site has at least one
 * institution, and may stay tucked in the kebab menu only while the publisher
 * is not using institutions.
 */

/**
 * External dependencies
 */
import { render } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ContentGates from './content-gates';

// mock-prefixed so Jest's hoisted jest.mock factories may close over them.
const mockSetHeaderData = jest.fn();
let mockWizardData = {};

jest.mock( '../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: jest.fn(),
		isFetching: false,
		errorMessage: null,
		resetError: jest.fn(),
	} ),
} ) );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( {
		addNotice: jest.fn(),
		resetNotices: jest.fn(),
		resetHeaderData: jest.fn(),
		setHeaderData: ( ...args ) => mockSetHeaderData( ...args ),
		updateWizardSettings: jest.fn(),
	} ),
	useSelect: () => ( {} ),
} ) );

// Passthrough only the components the view actually uses. The real
// @wordpress/components cannot be loaded in this jsdom env (its data-store
// side effects throw at import), so instead of a plain object — where a newly
// imported component reads back as undefined and fails deep in React with an
// opaque "Element type is invalid" — wrap the mock in a Proxy that throws for
// any export the test has not provided, naming the missing one.
const failLoudlyMock = ( moduleName, exports ) =>
	new Proxy( exports, {
		get( target, prop ) {
			if ( prop in target || typeof prop === 'symbol' || prop === '__esModule' ) {
				return target[ prop ];
			}
			throw new Error(
				`content-gates.test mock of '${ moduleName }' has no '${ String( prop ) }'. ` + 'Add it to the mock in content-gates.test.js.'
			);
		},
	} );

jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	// forwardRef because the component attaches a ref to VStack.
	const Passthrough = React.forwardRef( ( { children }, ref ) => React.createElement( 'div', { ref }, children ) );
	return failLoudlyMock( '@wordpress/components', { __experimentalVStack: Passthrough } );
} );

jest.mock( '../../../../../packages/components/src', () => {
	const React = require( 'react' );
	const Passthrough = ( { children } ) => React.createElement( 'div', null, children );
	return failLoudlyMock( 'packages/components/src', { Divider: Passthrough, Grid: Passthrough } );
} );

jest.mock( '../../../../../packages/components/src/wizard/store/utils', () => ( {
	useWizardData: () => mockWizardData,
} ) );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

// Child views are irrelevant to the header contract under test.
jest.mock( './content-gates-onboarding', () => () => null );
jest.mock( './content-gates-priority', () => () => null );
jest.mock( './content-gate-settings', () => () => null );
jest.mock( './advanced-settings', () => () => null );
jest.mock( './settings-card', () => () => null );

const gatesOfLength = length =>
	Array.from( { length }, ( _unused, index ) => ( {
		id: index + 1,
		title: `Gate ${ index + 1 }`,
		status: 'publish',
		priority: index,
	} ) );

const lastHeaderData = () => {
	const { calls } = mockSetHeaderData.mock;
	if ( calls.length === 0 ) {
		throw new Error( 'setHeaderData was never called; the header effect did not run.' );
	}
	return calls[ calls.length - 1 ][ 0 ];
};

const menuLabels = headerData => headerData.sectionMenu.map( item => item.label );

describe( 'Content Gates header — Institutions entry point (NPPD-1492)', () => {
	beforeEach( () => {
		mockSetHeaderData.mockReset();
	} );

	// The full gate-count × institutions matrix: Gate Priority appears only
	// with more than one gate, and Institutions moves between the kebab menu
	// and the promoted secondary action depending on whether any exist.
	it.each( [
		{
			name: 'one gate, no institutions',
			gateCount: 1,
			hasInstitutions: false,
			expectedMenu: [ 'Institutions', 'Advanced Settings' ],
			expectSecondary: false,
		},
		{
			name: 'two gates, no institutions',
			gateCount: 2,
			hasInstitutions: false,
			expectedMenu: [ 'Gate Priority', 'Institutions', 'Advanced Settings' ],
			expectSecondary: false,
		},
		{
			name: 'one gate, institutions present',
			gateCount: 1,
			hasInstitutions: true,
			expectedMenu: [ 'Advanced Settings' ],
			expectSecondary: true,
		},
		{
			name: 'two gates, institutions present',
			gateCount: 2,
			hasInstitutions: true,
			expectedMenu: [ 'Gate Priority', 'Advanced Settings' ],
			expectSecondary: true,
		},
	] )( 'builds the header menu for $name', ( { gateCount, hasInstitutions, expectedMenu, expectSecondary } ) => {
		mockWizardData = { gates: gatesOfLength( gateCount ), config: { has_institutions: hasInstitutions } };
		render( <ContentGates updateGatesData={ () => {} } /> );

		const headerData = lastHeaderData();
		// Assert the whole ordered menu, so the chained conditional order is pinned.
		expect( menuLabels( headerData ) ).toEqual( expectedMenu );

		if ( expectSecondary ) {
			// Pin the href, not just the label: the promotion changed this
			// entry from an action callback to an href, and a label-only
			// assertion would still pass on a dead link.
			expect( headerData.sectionSecondaryAction ).toEqual( expect.objectContaining( { label: 'Institutions', href: '#/institutions' } ) );
		} else {
			expect( headerData.sectionSecondaryAction ).toBeUndefined();
			// The kebab entry must also still navigate, not just carry a label.
			const institutionsItem = headerData.sectionMenu.find( item => item.label === 'Institutions' );
			expect( institutionsItem ).toEqual( expect.objectContaining( { href: '#/institutions' } ) );
		}
	} );
} );
