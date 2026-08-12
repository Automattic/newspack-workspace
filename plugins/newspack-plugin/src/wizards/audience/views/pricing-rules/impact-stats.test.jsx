/**
 * The impact preview's headline numbers, as a grid of tiles.
 */

/**
 * External dependencies
 */
import { render, screen, within } from '@testing-library/react';

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

const labels = () => [ 'Products affected', 'Subscribers in scope', 'Eligible at renewal', 'Protected' ];

// Keyed to the label, so a value stays bound to its own tile however the grid is ordered.
const tileFor = label => screen.getByText( label ).closest( '.newspack-pricing-rules__tile' );

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

	it( 'marks a capped product count as a lower bound', () => {
		render( <ImpactStats totalMatching={ 500 } countLimited /> );
		expect( screen.getByText( '500+' ) ).toBeInTheDocument();
	} );

	it( 'renders one tile when there is no audience data', () => {
		render( <ImpactStats totalMatching={ 36 } countLimited={ false } /> );

		expect( screen.getByText( 'Products affected' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Subscribers in scope' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Eligible at renewal' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Protected' ) ).not.toBeInTheDocument();
	} );

	it( 'renders four tiles when the audience arrives', () => {
		render( <ImpactStats totalMatching={ 36 } countLimited={ false } audience={ audience() } /> );

		labels().forEach( label => expect( screen.getByText( label ) ).toBeInTheDocument() );
		expect( within( tileFor( 'Products affected' ) ).getByText( '36' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Subscribers in scope' ) ).getByText( '12' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Eligible at renewal' ) ).getByText( '8' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Protected' ) ).getByText( '4' ) ).toBeInTheDocument();
	} );

	it( 'hangs the products action off its own tile and no other', () => {
		render( <ImpactStats totalMatching={ 36 } countLimited={ false } audience={ audience() } onViewProducts={ jest.fn() } /> );

		const trigger = screen.getByRole( 'button', { name: 'View Affected Products' } );
		expect( tileFor( 'Products affected' ).contains( trigger ) ).toBe( true );
		[ 'Subscribers in scope', 'Eligible at renewal', 'Protected' ].forEach( label =>
			expect( within( tileFor( label ) ).queryByRole( 'button' ) ).not.toBeInTheDocument()
		);
	} );

	it( 'shows no products action when the list gives it nothing to open', () => {
		render( <ImpactStats totalMatching={ 36 } countLimited={ false } audience={ audience() } /> );

		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
	} );

	it( 'ignores an unsupported audience', () => {
		render( <ImpactStats totalMatching={ 36 } countLimited={ false } audience={ audience( { supported: false } ) } /> );
		expect( screen.queryByText( 'Subscribers in scope' ) ).not.toBeInTheDocument();
	} );

	it( 'leaves the product count exact when only the audience is capped', () => {
		render( <ImpactStats totalMatching={ 500 } countLimited={ false } audience={ audience( { count_limited: true } ) } /> );
		expect( screen.getByText( '500' ) ).toBeInTheDocument();
	} );

	// The engine truncates oldest-first and the oldest are the ones a cohort gate
	// protects, so a capped split under-reports who is repriced.
	it( 'bounds all three subscriber counts when the audience is capped', () => {
		render( <ImpactStats totalMatching={ 500 } countLimited audience={ audience( { count_limited: true } ) } /> );

		expect( within( tileFor( 'Products affected' ) ).getByText( '500+' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Subscribers in scope' ) ).getByText( '12+' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Eligible at renewal' ) ).getByText( '8+' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Protected' ) ).getByText( '4+' ) ).toBeInTheDocument();
	} );

	it( 'stands the renewal tiles down for a locked rule', () => {
		render( <ImpactStats totalMatching={ 36 } countLimited={ false } audience={ audience( { application: 'locked' } ) } /> );

		labels().forEach( label => expect( screen.getByText( label ) ).toBeInTheDocument() );
		expect( screen.getAllByRole( 'img', { name: 'Not applicable' } ) ).toHaveLength( 2 );
		expect( screen.getAllByText( 'Applies to new sign-ups only' ) ).toHaveLength( 2 );
		expect( screen.queryByText( '8' ) ).not.toBeInTheDocument();
	} );

	// The engine never claims 'locked' for a set whose rules disagree.
	it( 'keeps the numbers for a mixed rule set', () => {
		render( <ImpactStats totalMatching={ 36 } countLimited={ false } audience={ audience( { application: 'mixed' } ) } /> );

		expect( screen.getByText( '8' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'img', { name: 'Not applicable' } ) ).not.toBeInTheDocument();
	} );
} );
