import {
	DURATION_MODAL,
	DURATION_SHEET,
	DURATION_TRAVEL_MAX,
	EASE_COLOR,
	ENTER_OFFSET,
	ENTER_SCALE,
	PRESS_SCALE,
	REVEAL_SCALE,
	REDUCED_MOTION_QUERY,
	TRAVEL_BASE_DISTANCE,
	TRAVEL_PX_PER_MS,
	prefersReducedMotion,
	travelDuration,
} from './index';

describe( 'travelDuration', () => {
	it( 'keeps the base duration up to the base distance', () => {
		expect( travelDuration( 0 ) ).toBe( DURATION_MODAL );
		expect( travelDuration( TRAVEL_BASE_DISTANCE ) ).toBe( DURATION_MODAL );
	} );

	it( 'adds 1ms for every TRAVEL_PX_PER_MS pixels past the base distance', () => {
		expect( travelDuration( TRAVEL_BASE_DISTANCE + 10 * TRAVEL_PX_PER_MS ) ).toBe( DURATION_MODAL + 10 );
		expect( travelDuration( 300 ) ).toBe( 265 );
		expect( travelDuration( 410 ) ).toBe( 293 );
		expect( travelDuration( 632 ) ).toBe( 348 );
		expect( travelDuration( 964 ) ).toBe( 431 );
	} );

	it( 'never runs past DURATION_TRAVEL_MAX', () => {
		expect( travelDuration( 1036 ) ).toBe( 449 );
		expect( travelDuration( 1040 ) ).toBe( DURATION_TRAVEL_MAX );
		expect( travelDuration( 1296 ) ).toBe( DURATION_TRAVEL_MAX );
		expect( travelDuration( 10000 ) ).toBe( DURATION_TRAVEL_MAX );
		expect( travelDuration( 640, DURATION_SHEET ) ).toBe( DURATION_TRAVEL_MAX );
	} );

	it( 'starts from the base it is given', () => {
		expect( travelDuration( 300, 200 ) ).toBe( 215 );
	} );
} );

describe( 'tokens', () => {
	it( 'exposes the color curve and the scale and offset values', () => {
		expect( EASE_COLOR ).toBe( 'ease' );
		expect( PRESS_SCALE ).toBe( 0.97 );
		expect( ENTER_SCALE ).toBe( 0.96 );
		expect( REVEAL_SCALE ).toBe( 0.9 );
		expect( ENTER_OFFSET ).toBe( 4 );
	} );
} );

describe( 'prefersReducedMotion', () => {
	const originalMatchMedia = window.matchMedia;

	afterEach( () => {
		window.matchMedia = originalMatchMedia;
	} );

	it( 'follows the reduced motion query', () => {
		window.matchMedia = jest.fn( () => ( { matches: true } ) );
		expect( prefersReducedMotion() ).toBe( true );
		expect( window.matchMedia ).toHaveBeenCalledWith( REDUCED_MOTION_QUERY );

		window.matchMedia = jest.fn( () => ( { matches: false } ) );
		expect( prefersReducedMotion() ).toBe( false );
	} );

	it( 'is false where matchMedia is missing', () => {
		window.matchMedia = undefined;
		expect( prefersReducedMotion() ).toBe( false );
	} );
} );
