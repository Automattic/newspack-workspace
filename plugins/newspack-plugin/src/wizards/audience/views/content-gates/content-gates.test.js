/**
 * The Content Gates tab's page header: its actions and subtitle.
 */

/**
 * External dependencies
 */
import { act, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ContentGates from './content-gates';

// mock-prefixed so Jest's hoisted jest.mock factories may close over them.
const mockSetHeaderData = jest.fn();
let mockDataViewsProps;
const mockSpeak = jest.fn();
let mockWizardData = {};

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

jest.mock( '@wordpress/a11y', () => ( { speak: ( ...args ) => mockSpeak( ...args ) } ) );

jest.mock( '@wordpress/dataviews', () => ( { DataViews: { Search: () => null, Filters: () => null } } ) );

jest.mock( '../../../../../packages/components/src', () => ( {
	DataViews: props => {
		mockDataViewsProps = props;
		return null;
	},
} ) );

jest.mock( '../../../../../packages/components/src/wizard/store/utils', () => ( {
	useWizardData: () => mockWizardData,
} ) );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

// Child views are irrelevant to the header contract under test.
jest.mock( './content-gates-onboarding', () => () => null );
jest.mock( './content-gates-priority', () => () => null );
jest.mock( './content-gate-settings', () => ( { gate } ) => <div>{ gate.title }</div> );
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
		{ gateCount: 1, expectedSecondary: [] },
		{ gateCount: 2, expectedSecondary: [ 'Gate Priority' ] },
	] )( 'shows Gate Priority as a button only with more than one gate ($gateCount)', ( { gateCount, expectedSecondary } ) => {
		mockWizardData = { gates: gatesOfLength( gateCount ), config: {} };
		render( <ContentGates updateGatesData={ () => {} } /> );

		const headerData = lastHeaderData();
		expect( headerData.actions.filter( action => action.type === 'secondary' ).map( action => action.label ) ).toEqual( expectedSecondary );
		expect( moreActionLabels( headerData ) ).toEqual( [ 'Advanced Settings' ] );
		expect( headerData.sectionMenu ).toBeUndefined();
		expect( headerData.sectionTitle ).toBeUndefined();
	} );

	// The empty state once dropped the subtitle, which made the header jump between tabs.
	it.each( [
		{ state: 'the empty state', wizardData: { config: {} } },
		{ state: 'the gates list', wizardData: { gates: gatesOfLength( 1 ), config: {} } },
	] )( 'describes the screen in the page subtitle on $state', ( { wizardData } ) => {
		mockWizardData = wizardData;
		render( <ContentGates updateGatesData={ () => {} } /> );

		const headerData = lastHeaderData();
		expect( headerData.subTitle ).toEqual( expect.any( String ) );
		expect( headerData.subTitle ).not.toBe( '' );
		expect( headerData.sectionDescription ).toBeUndefined();
	} );
} );

describe( 'Content Gates toolbar', () => {
	const active = { id: 1, title: 'Alpha', status: 'publish', priority: 0 };
	const inactive = { id: 2, title: 'Beta', status: 'draft', priority: 1 };
	const renderGates = gates => {
		mockWizardData = { gates, config: {} };
		mockDataViewsProps = undefined;
		return render( <ContentGates updateGatesData={ () => {} } /> );
	};
	const changeView = next => act( () => mockDataViewsProps.onChangeView( { ...mockDataViewsProps.view, ...next } ) );
	const filterIds = () => mockDataViewsProps.fields.filter( field => field.filterBy ).map( field => field.id );
	const inactiveFilter = { field: 'status', operator: 'is', value: 'inactive' };
	const allStatuses = { ...inactiveFilter, value: 'all' };

	it( 'opens on every gate, inactive ones included, and hides Reset', () => {
		renderGates( [ active, inactive ] );

		expect( screen.getByText( 'Alpha' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Beta' ) ).toBeInTheDocument();
		expect( mockDataViewsProps.view.filters ).toEqual( [ allStatuses ] );
		expect( mockDataViewsProps.className ).toContain( 'is-default-view' );
	} );

	it( 'narrows the cards by filter and offers Reset', () => {
		renderGates( [ active, inactive ] );

		changeView( { filters: [ inactiveFilter ] } );

		expect( screen.queryByText( 'Alpha' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Beta' ) ).toBeInTheDocument();
		expect( mockDataViewsProps.className ).not.toContain( 'is-default-view' );
	} );

	it( 'offers All, which clears the filter', () => {
		renderGates( [ active, inactive ] );
		changeView( { filters: [ inactiveFilter ] } );

		const statusField = mockDataViewsProps.fields.find( field => 'status' === field.id );
		expect( statusField.elements[ 0 ] ).toEqual( { value: 'all', label: 'All' } );

		changeView( { filters: [ { ...inactiveFilter, value: 'all' } ] } );

		expect( screen.getByText( 'Alpha' ) ).toBeInTheDocument();
		expect( mockDataViewsProps.view.filters ).toEqual( [ allStatuses ] );
		expect( mockDataViewsProps.className ).toContain( 'is-default-view' );
	} );

	it( 'says so when nothing matches, and announces the count', () => {
		mockSpeak.mockReset();
		renderGates( [ active, inactive ] );
		expect( mockSpeak ).not.toHaveBeenCalled();

		changeView( { search: 'gamma' } );

		expect( screen.getByText( 'No content gates match your search.' ) ).toBeInTheDocument();
		expect( mockSpeak ).toHaveBeenLastCalledWith( '0 content gates found.' );
	} );

	it( 'hides the Status chip when every gate has the same status', () => {
		renderGates( [ active, { ...active, id: 3, title: 'Gamma' } ] );

		expect( filterIds() ).not.toContain( 'status' );
		expect( mockDataViewsProps.view.filters ).toEqual( [] );
	} );

	it( 'hides the toolbar for a single gate', () => {
		renderGates( [ active ] );

		expect( mockDataViewsProps ).toBeUndefined();
	} );

	it( 'clears the filter when its last match goes away, and does not bring it back', () => {
		const { rerender } = renderGates( [ active, inactive ] );
		changeView( { filters: [ inactiveFilter ] } );

		mockWizardData = { gates: [ active, { ...inactive, status: 'publish' } ], config: {} };
		rerender( <ContentGates updateGatesData={ () => {} } /> );

		expect( screen.getByText( 'Alpha' ) ).toBeInTheDocument();
		expect( filterIds() ).not.toContain( 'status' );

		mockWizardData = { gates: [ active, inactive ], config: {} };
		rerender( <ContentGates updateGatesData={ () => {} } /> );

		expect( filterIds() ).toContain( 'status' );
		expect( mockDataViewsProps.view.filters ).toEqual( [ allStatuses ] );
	} );
} );
