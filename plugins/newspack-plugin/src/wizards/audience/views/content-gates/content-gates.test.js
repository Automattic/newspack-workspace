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

jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	// forwardRef because the component attaches a ref to VStack.
	const Passthrough = React.forwardRef( ( { children }, ref ) => React.createElement( 'div', { ref }, children ) );
	return {
		__experimentalVStack: Passthrough,
	};
} );

jest.mock( '../../../../../packages/components/src', () => {
	const React = require( 'react' );
	const Passthrough = ( { children } ) => React.createElement( 'div', null, children );
	return {
		Divider: Passthrough,
		Grid: Passthrough,
	};
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

const ContentGates = require( './content-gates' ).default;

const singleGate = [ { id: 1, title: 'Gate A', status: 'publish', priority: 0 } ];

const lastHeaderData = () => mockSetHeaderData.mock.calls[ mockSetHeaderData.mock.calls.length - 1 ][ 0 ];

describe( 'Content Gates header — Institutions entry point (NPPD-1492)', () => {
	beforeEach( () => {
		mockSetHeaderData.mockReset();
	} );

	it( 'keeps Institutions in the kebab menu when the site has no institutions', () => {
		mockWizardData = { gates: singleGate, config: { has_institutions: false } };
		render( <ContentGates updateGatesData={ () => {} } /> );

		const headerData = lastHeaderData();
		expect( headerData.sectionMenu.map( item => item.label ) ).toContain( 'Institutions' );
		expect( headerData.sectionSecondaryAction ).toBeUndefined();
	} );

	it( 'promotes Institutions to a visible header action when the site has institutions', () => {
		mockWizardData = { gates: singleGate, config: { has_institutions: true } };
		render( <ContentGates updateGatesData={ () => {} } /> );

		const headerData = lastHeaderData();
		expect( headerData.sectionSecondaryAction ).toEqual( expect.objectContaining( { label: 'Institutions', href: '#/institutions' } ) );
		expect( headerData.sectionMenu.map( item => item.label ) ).not.toContain( 'Institutions' );
	} );
} );
