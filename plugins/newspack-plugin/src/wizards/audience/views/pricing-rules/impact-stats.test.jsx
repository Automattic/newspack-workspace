/**
 * The impact preview's headline numbers.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ImpactStats from './impact-stats';

const audience = ( over = {} ) => ( {
	supported: true,
	total: 12,
	caught: 8,
	protected: 4,
	count_limited: false,
	application: 'current',
	...over,
} );

describe( 'ImpactStats', () => {
	afterEach( () => {
		document.documentElement.lang = '';
	} );

	// Pinned, or the separator follows whatever locale the suite happens to run under.
	it( 'groups digits on a four-figure count', () => {
		document.documentElement.lang = 'en-US';
		render( <ImpactStats totalMatching={ 12480 } countLimited={ false } /> );
		expect( screen.getByText( '12,480' ) ).toBeInTheDocument();
	} );

	it( 'groups digits for the site language, not the browser', () => {
		document.documentElement.lang = 'de-DE';
		render( <ImpactStats totalMatching={ 12480 } countLimited={ false } /> );
		expect( screen.getByText( '12.480' ) ).toBeInTheDocument();
	} );

	it( 'marks a capped count as a lower bound', () => {
		render( <ImpactStats totalMatching={ 500 } countLimited /> );
		expect( screen.getByText( '500+' ) ).toBeInTheDocument();
	} );

	it( 'counts products on their own when there is no audience data', () => {
		render( <ImpactStats totalMatching={ 36 } countLimited={ false } /> );
		expect( screen.getByText( '36' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Products affected' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Subscribers in scope' ) ).not.toBeInTheDocument();
	} );

	it( 'shows who is repriced at renewal', () => {
		render( <ImpactStats totalMatching={ 36 } countLimited={ false } audience={ audience() } /> );
		expect( screen.getByText( 'Subscribers in scope' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Eligible at renewal' ) ).toBeInTheDocument();
		expect( screen.getByText( '8' ) ).toBeInTheDocument();
		expect( screen.getByText( '4 protected' ) ).toBeInTheDocument();
	} );

	it( 'says nobody is repriced under a locked rule', () => {
		render( <ImpactStats totalMatching={ 36 } countLimited={ false } audience={ audience( { application: 'locked' } ) } /> );
		expect( screen.queryByText( 'Eligible at renewal' ) ).not.toBeInTheDocument();
		expect( screen.getByText( /new sign-ups only/ ) ).toBeInTheDocument();
	} );

	it( 'marks a capped count as a lower bound, and only that count', () => {
		render( <ImpactStats totalMatching={ 500 } countLimited={ false } audience={ audience( { count_limited: true } ) } /> );
		expect( screen.getByText( '500' ) ).toBeInTheDocument();
		expect( screen.getByText( '12+' ) ).toBeInTheDocument();
	} );

	it( 'marks both counts as lower bounds when both are capped', () => {
		render( <ImpactStats totalMatching={ 500 } countLimited audience={ audience( { count_limited: true } ) } /> );
		expect( screen.getByText( '500+' ) ).toBeInTheDocument();
		expect( screen.getByText( '12+' ) ).toBeInTheDocument();
	} );

	it( 'renders a capped total as a ceiling when the route reports one', () => {
		render( <ImpactStats totalMatching={ 500 } countLimited countBound="upper" audience={ audience( { count_limited: true } ) } /> );
		expect( screen.getByText( 'Up to 500' ) ).toBeInTheDocument();
		// The audience walk only ever omits, so its bound stays a floor.
		expect( screen.getByText( '12+' ) ).toBeInTheDocument();
	} );

	it( 'ignores an unsupported audience', () => {
		render( <ImpactStats totalMatching={ 36 } countLimited={ false } audience={ audience( { supported: false } ) } /> );
		expect( screen.queryByText( 'Subscribers in scope' ) ).not.toBeInTheDocument();
	} );
} );
