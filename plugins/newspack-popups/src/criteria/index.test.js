import { registerCriteria, getCriteria, setMatchingAttribute, setMatchingFunction } from './utils';

const criteriaId = 'test_criteria';

describe( 'criteria registration', () => {
	beforeEach( () => {
		window.newspackPopupsCriteria = { criteria: {} };
	} );
	it( 'should register a criteria with default properties', () => {
		registerCriteria( criteriaId );
		const criteria = getCriteria( criteriaId );
		expect( criteria ).toBeDefined();
		expect( criteria.id ).toEqual( criteriaId );
		expect( criteria.matchingFunction ).toEqual( 'default' );
	} );
	it( 'should register a criteria with custom properties', () => {
		const id = criteriaId;
		const config = {
			matchingAttribute: 'my-custom-attribute',
			matchingFunction: () => {
				return true;
			},
		};
		registerCriteria( id, config );
		const criteria = getCriteria( id );
		expect( criteria ).toBeDefined();
		expect( criteria.id ).toEqual( id );
		expect( criteria.matchingAttribute ).toEqual( config.matchingAttribute );
		expect( criteria.matchingFunction ).toEqual( config.matchingFunction );
	} );
	it( 'should set a matching attribute', () => {
		const matchingAttribute = () => {
			return 'foo';
		};
		registerCriteria( criteriaId );
		setMatchingAttribute( criteriaId, matchingAttribute );
		const criteria = getCriteria( criteriaId );
		expect( criteria.matchingAttribute ).toEqual( matchingAttribute );
	} );
	it( 'should set a matching function', () => {
		const matchingFunction = () => {
			return true;
		};
		registerCriteria( criteriaId );
		setMatchingFunction( criteriaId, matchingFunction );
		const criteria = getCriteria( criteriaId );
		expect( criteria.matchingFunction ).toEqual( matchingFunction );
	} );
	it( 'should return undefined if criteria is not registered', () => {
		expect( getCriteria( 'missing' ) ).toBeUndefined();
	} );
	it( 'should return all criteria if no id is passed', () => {
		registerCriteria( criteriaId );
		registerCriteria( 'my-other-criteria' );
		const criteria = getCriteria();
		expect( criteria ).toBeDefined();
		expect( Object.keys( criteria ).length ).toEqual( 2 );
	} );
} );

describe( 'criteria matching', () => {
	beforeEach( () => {
		// Ignore console.warn calls due to missing RAS library on these tests.
		jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		window.newspackPopupsCriteria = { criteria: {} };
		registerCriteria( criteriaId );
	} );
	it( 'should match "default" matching function', () => {
		setMatchingAttribute( criteriaId, () => 'foo' );
		setMatchingFunction( criteriaId, 'default' );
		const criteria = getCriteria( criteriaId );
		expect( criteria.matches( { value: 'foo' } ) ).toEqual( true );
		expect( criteria.matches( { value: 'bar' } ) ).toEqual( false );
	} );
	it( 'should match "range" matching function', () => {
		setMatchingAttribute( criteriaId, () => 5 );
		setMatchingFunction( criteriaId, 'range' );
		const criteria = getCriteria( criteriaId );
		expect( criteria.matches( { value: { min: 1, max: 10 } } ) ).toEqual( true );
		expect( criteria.matches( { value: { min: 10 } } ) ).toEqual( false );
		expect( criteria.matches( { value: {} } ) ).toEqual( true );
	} );
	it( 'enforces a "range" bound of 0 instead of treating it as unbounded', () => {
		// A fresh criteria per value — `criteria.value` is snapshotted on first match.
		// min: 0 must exclude a negative value (0 was previously skipped as falsy).
		registerCriteria( 'range_min_zero', { matchingFunction: 'range', matchingAttribute: () => -5 } );
		expect( getCriteria( 'range_min_zero' ).matches( { value: { min: 0 } } ) ).toEqual( false );
		// max: 0 must exclude a positive value.
		registerCriteria( 'range_max_zero', { matchingFunction: 'range', matchingAttribute: () => 5 } );
		expect( getCriteria( 'range_max_zero' ).matches( { value: { max: 0 } } ) ).toEqual( false );
		// A value of exactly 0 sits within [ 0, 0 ].
		registerCriteria( 'range_exact_zero', { matchingFunction: 'range', matchingAttribute: () => 0 } );
		expect( getCriteria( 'range_exact_zero' ).matches( { value: { min: 0, max: 0 } } ) ).toEqual( true );
	} );
	it( 'should match "list__in" matching function', () => {
		setMatchingAttribute( criteriaId, () => 'bar' );
		setMatchingFunction( criteriaId, 'list__in' );
		const criteria = getCriteria( criteriaId );
		expect( criteria.matches( { value: [ 'foo', 'bar' ] } ) ).toEqual( true );
		expect( criteria.matches( { value: 'foo, bar' } ) ).toEqual( true );
		expect( criteria.matches( { value: 'bar' } ) ).toEqual( true );
		expect( criteria.matches( { value: 'foo' } ) ).toEqual( false );
		expect( criteria.matches( { value: [ 'foo', 'baz' ] } ) ).toEqual( false );
		expect( criteria.matches( { value: '' } ) ).toEqual( false );
		expect( criteria.matches( { value: [] } ) ).toEqual( false );
	} );
	it( 'should match "list__in" matching function with array value', () => {
		setMatchingAttribute( criteriaId, () => [ 'foo', 'bar' ] );
		setMatchingFunction( criteriaId, 'list__in' );
		const criteria = getCriteria( criteriaId );
		expect( criteria.matches( { value: [ 'foo', 'bar' ] } ) ).toEqual( true );
		expect( criteria.matches( { value: 'foo, bar' } ) ).toEqual( true );
		expect( criteria.matches( { value: 'bar' } ) ).toEqual( true );
		expect( criteria.matches( { value: [ 'foo', 'baz' ] } ) ).toEqual( true );
		expect( criteria.matches( { value: [ 'baz' ] } ) ).toEqual( false );
		expect( criteria.matches( { value: '' } ) ).toEqual( false );
		expect( criteria.matches( { value: [] } ) ).toEqual( false );
	} );
	it( 'should match "list__in" matching function with loose type comparison', () => {
		setMatchingAttribute( criteriaId, () => 2 );
		setMatchingFunction( criteriaId, 'list__in' );
		const criteria = getCriteria( criteriaId );
		expect( criteria.matches( { value: [ '1', '2' ] } ) ).toEqual( true );
		expect( criteria.matches( { value: [ 1, 2 ] } ) ).toEqual( true );
		expect( criteria.matches( { value: '1, 2' } ) ).toEqual( true );
		expect( criteria.matches( { value: '2' } ) ).toEqual( true );
		expect( criteria.matches( { value: [ 1 ] } ) ).toEqual( false );
		expect( criteria.matches( { value: '1' } ) ).toEqual( false );
		expect( criteria.matches( { value: 1 } ) ).toEqual( false );
	} );
	it( 'parses ActiveCampaign pipe-delimited reader values for list__in', () => {
		setMatchingAttribute( criteriaId, () => '||foo||bar||' );
		setMatchingFunction( criteriaId, 'list__in' );
		const criteria = getCriteria( criteriaId );
		expect( criteria.matches( { value: [ 'bar' ] } ) ).toEqual( true );
		expect( criteria.matches( { value: [ 'baz' ] } ) ).toEqual( false );
	} );
	it( 'should match "list__not_in" matching function', () => {
		setMatchingAttribute( criteriaId, () => 'bar' );
		setMatchingFunction( criteriaId, 'list__not_in' );
		const criteria = getCriteria( criteriaId );
		expect( criteria.matches( { value: [ 'foo', 'bar' ] } ) ).toEqual( false );
		expect( criteria.matches( { value: 'foo, bar' } ) ).toEqual( false );
		expect( criteria.matches( { value: 'bar' } ) ).toEqual( false );
		expect( criteria.matches( { value: 'foo' } ) ).toEqual( true );
		expect( criteria.matches( { value: [ 'foo', 'baz' ] } ) ).toEqual( true );
		expect( criteria.matches( { value: '' } ) ).toEqual( true );
		expect( criteria.matches( { value: [] } ) ).toEqual( true );
	} );
	it( 'should match "list__not_in" matching function with array value', () => {
		setMatchingAttribute( criteriaId, () => [ 'foo', 'bar' ] );
		setMatchingFunction( criteriaId, 'list__not_in' );
		const criteria = getCriteria( criteriaId );
		expect( criteria.matches( { value: [ 'foo', 'bar' ] } ) ).toEqual( false );
		expect( criteria.matches( { value: 'foo, bar' } ) ).toEqual( false );
		expect( criteria.matches( { value: 'bar' } ) ).toEqual( false );
		expect( criteria.matches( { value: 'baz' } ) ).toEqual( true );
		expect( criteria.matches( { value: [ 'fuu', 'baz' ] } ) ).toEqual( true );
		expect( criteria.matches( { value: '' } ) ).toEqual( true );
		expect( criteria.matches( { value: [] } ) ).toEqual( true );
	} );
	it( 'should match "list__not_in" matching function with loose type comparison', () => {
		setMatchingAttribute( criteriaId, () => 2 );
		setMatchingFunction( criteriaId, 'list__not_in' );
		const criteria = getCriteria( criteriaId );
		expect( criteria.matches( { value: [ '1', '2' ] } ) ).toEqual( false );
		expect( criteria.matches( { value: [ 1, 2 ] } ) ).toEqual( false );
		expect( criteria.matches( { value: '2' } ) ).toEqual( false );
		expect( criteria.matches( { value: [ 1 ] } ) ).toEqual( true );
		expect( criteria.matches( { value: '1' } ) ).toEqual( true );
		expect( criteria.matches( { value: 1 } ) ).toEqual( true );
	} );
	it( 'should match custom matching function', () => {
		setMatchingFunction( criteriaId, segmentConfig => {
			return segmentConfig.value === 'foo';
		} );
		const criteria = getCriteria( criteriaId );
		expect( criteria.matches( { value: 'foo' } ) ).toEqual( true );
		expect( criteria.matches( { value: 'bar' } ) ).toEqual( false );
	} );
	it( 'should cache matching function results', () => {
		const matchingFunction = jest.fn( () => true );
		setMatchingFunction( criteriaId, matchingFunction );
		const criteria = getCriteria( criteriaId );
		expect( criteria.matches( { value: 'foo' } ) ).toEqual( true );
		expect( criteria.matches( { value: 'foo' } ) ).toEqual( true );
		expect( matchingFunction ).toHaveBeenCalledTimes( 1 );
		expect( criteria.matches( { value: 'bar' } ) ).toEqual( true );
		expect( matchingFunction ).toHaveBeenCalledTimes( 2 );
	} );
	it( 'should pass option params to matching function', () => {
		registerCriteria( criteriaId, {
			matchingAttribute: 'my-custom-attribute',
			matchingFunction: ( config, ras, { optionParams } ) => {
				expect( optionParams ).toBeDefined();
				expect( optionParams[ config.value ] ).toBeDefined();
				return true;
			},
			optionParams: {
				1: { foo: 'bar' },
				2: { foo: 'baz' },
			},
		} );
		// Trigger the matching function for assertions.
		getCriteria( criteriaId ).matches( { value: '1' } );
	} );
} );
