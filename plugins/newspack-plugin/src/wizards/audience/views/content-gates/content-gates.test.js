/**
 * The Content Gates tab's page header: its actions and subtitle.
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
// component packages cannot be loaded in this jsdom env (their data-store
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

jest.mock( '@wordpress/ui', () => {
	const React = require( 'react' );
	const Passthrough = ( { children } ) => React.createElement( 'div', null, children );
	return failLoudlyMock( '@wordpress/ui', { Stack: Passthrough } );
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

const moreActionLabels = headerData => headerData.actions.filter( action => action.type === 'more' ).map( action => action.label );

describe( 'Content Gates page header', () => {
	beforeEach( () => {
		mockSetHeaderData.mockReset();
	} );

	it.each( [
		{ gateCount: 1, expectedMenu: [ 'Advanced Settings' ] },
		{ gateCount: 2, expectedMenu: [ 'Gate Priority', 'Advanced Settings' ] },
	] )( 'lists Gate Priority only with more than one gate ($gateCount)', ( { gateCount, expectedMenu } ) => {
		mockWizardData = { gates: gatesOfLength( gateCount ), config: { has_institutions: true } };
		render( <ContentGates updateGatesData={ () => {} } /> );

		const headerData = lastHeaderData();
		expect( moreActionLabels( headerData ) ).toEqual( expectedMenu );
		expect( headerData.sectionMenu ).toBeUndefined();
		expect( headerData.sectionTitle ).toBeUndefined();
	} );

	it( 'describes the screen in the page subtitle', () => {
		mockWizardData = { gates: gatesOfLength( 1 ), config: {} };
		render( <ContentGates updateGatesData={ () => {} } /> );

		expect( lastHeaderData().subTitle ).toBe( 'Choose which content to restrict and how readers get access to it.' );
	} );
} );
