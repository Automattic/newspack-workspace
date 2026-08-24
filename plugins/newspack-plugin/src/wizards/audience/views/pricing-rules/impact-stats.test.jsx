/**
 * The impact preview's headline numbers, as a grid of tiles.
 */

/**
 * External dependencies
 */
import { render, screen, within, fireEvent } from '@testing-library/react';

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

const stats = props => <ImpactStats productsDescription="Rules currently price these products." { ...props } />;

// Keyed to the label, so a value stays bound to its own tile however the grid is ordered.
const tileFor = label => screen.getByText( label ).closest( '.newspack-stat-card' );

describe( 'ImpactStats', () => {
	afterEach( () => {
		document.documentElement.lang = '';
	} );

	// Pinned, or the separator follows whatever locale the suite happens to run under.
	it( 'groups digits on a four-figure count', () => {
		document.documentElement.lang = 'en-US';
		render( stats( { totalMatching: 12480, countLimited: false } ) );
		expect( screen.getByText( '12,480' ) ).toBeInTheDocument();
	} );

	it( 'groups digits for the site language, not the browser', () => {
		document.documentElement.lang = 'de-DE';
		render( stats( { totalMatching: 12480, countLimited: false } ) );
		expect( screen.getByText( '12.480' ) ).toBeInTheDocument();
	} );

	it( 'marks a capped product count as a lower bound', () => {
		render( stats( { totalMatching: 500, countLimited: true } ) );
		expect( screen.getByText( '500+' ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByText( 'At least 500' ) ).toHaveAttribute( 'data-visually-hidden' );
	} );

	// Grid ships no `columns-1` rule; one tile goes full width off the base `1fr`.
	it( 'renders one tile and passes the count through as the column count', () => {
		const { container } = render( stats( { totalMatching: 36, countLimited: false } ) );

		expect( screen.getByText( 'Products affected' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Subscribers in scope' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Eligible at renewal' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Protected' ) ).not.toBeInTheDocument();
		expect( container.querySelectorAll( '.newspack-stat-card' ) ).toHaveLength( 1 );
		expect( container.querySelector( '.newspack-pricing-rules__stats' ) ).toHaveClass( 'newspack-grid__columns-1' );
	} );

	it( 'renders four tiles when the audience arrives', () => {
		const { container } = render( stats( { totalMatching: 36, countLimited: false, audience: audience() } ) );

		labels().forEach( label => expect( screen.getByText( label ) ).toBeInTheDocument() );
		expect( within( tileFor( 'Products affected' ) ).getByText( '36' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Subscribers in scope' ) ).getByText( '12' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Eligible at renewal' ) ).getByText( '8' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Protected' ) ).getByText( '4' ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-pricing-rules__stats' ) ).toHaveClass( 'newspack-grid__columns-4' );
	} );

	// catalog-impact.tsx carries a screen-reader-only h2 purely so this level is not skipped.
	it( 'renders each label as a level-three heading', () => {
		render( stats( { totalMatching: 36, countLimited: false, audience: audience() } ) );

		labels().forEach( label => expect( screen.getByRole( 'heading', { name: label, level: 3 } ) ).toBeInTheDocument() );
	} );

	it( 'hides the em-dash and speaks its meaning instead', () => {
		render( stats( { totalMatching: 36, countLimited: false, audience: audience( { application: 'locked' } ) } ) );

		const tile = within( tileFor( 'Protected' ) );
		expect( tile.getByText( '—' ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( tile.getByText( 'Not applicable' ) ).toHaveAttribute( 'data-visually-hidden' );
	} );

	it( 'runs the products action from its button', () => {
		const onViewProducts = jest.fn();
		render( stats( { totalMatching: 36, countLimited: false, onViewProducts } ) );

		fireEvent.click( screen.getByRole( 'button', { name: 'View Affected Products' } ) );
		expect( onViewProducts ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'omits the reason line unless the rule is locked', () => {
		render( stats( { totalMatching: 36, countLimited: false, audience: audience() } ) );

		expect( screen.queryByText( 'Applies to new sign-ups only.' ) ).not.toBeInTheDocument();
	} );

	// The reason is a quiet line beside the description, not part of the headline.
	it( 'puts the locked reason in the footer at the description scale', () => {
		render( stats( { totalMatching: 36, countLimited: false, audience: audience( { application: 'locked' } ) } ) );

		const tile = tileFor( 'Protected' );
		const note = within( tile ).getByText( 'Applies to new sign-ups only.' );
		expect( note ).toHaveClass( 'newspack-stat-card__description' );
		expect( note.closest( '.newspack-stat-card__footer' ) ).toBeInTheDocument();
		expect( tile.querySelector( '.newspack-stat-card__secondary' ) ).not.toBeInTheDocument();
	} );

	it( 'hangs the products action off its own tile and no other', () => {
		render( stats( { totalMatching: 36, countLimited: false, audience: audience(), onViewProducts: jest.fn() } ) );

		const trigger = screen.getByRole( 'button', { name: 'View Affected Products' } );
		expect( tileFor( 'Products affected' ).contains( trigger ) ).toBe( true );
		[ 'Subscribers in scope', 'Eligible at renewal', 'Protected' ].forEach( label =>
			expect( within( tileFor( label ) ).queryByRole( 'button' ) ).not.toBeInTheDocument()
		);
	} );

	it( 'shows no products action when the list gives it nothing to open', () => {
		render( stats( { totalMatching: 36, countLimited: false, audience: audience() } ) );

		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
	} );

	it( 'renders a capped total as a ceiling when the route reports one', () => {
		render( <ImpactStats totalMatching={ 500 } countLimited countBound="upper" audience={ audience( { count_limited: true } ) } /> );
		expect( screen.getByText( 'Up to 500' ) ).toBeInTheDocument();
		// The audience walk only ever omits, so its bound stays a floor.
		expect( screen.getByText( '12+' ) ).toBeInTheDocument();
	} );

	it( 'ignores an unsupported audience', () => {
		render( stats( { totalMatching: 36, countLimited: false, audience: audience( { supported: false } ) } ) );
		expect( screen.queryByText( 'Subscribers in scope' ) ).not.toBeInTheDocument();
	} );

	// The two cap flags are separate: only this case can tell them apart.
	it( 'leaves the product count exact when only the audience is capped', () => {
		render( stats( { totalMatching: 500, countLimited: false, audience: audience( { count_limited: true } ) } ) );

		expect( within( tileFor( 'Products affected' ) ).getByText( '500' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Subscribers in scope' ) ).getByText( '12+' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Eligible at renewal' ) ).getByText( '8+' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Protected' ) ).getByText( '4+' ) ).toBeInTheDocument();
	} );

	it( 'bounds all three subscriber counts when the audience is capped', () => {
		render( stats( { totalMatching: 500, countLimited: true, audience: audience( { count_limited: true } ) } ) );

		expect( within( tileFor( 'Products affected' ) ).getByText( '500+' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Subscribers in scope' ) ).getByText( '12+' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Eligible at renewal' ) ).getByText( '8+' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Protected' ) ).getByText( '4+' ) ).toBeInTheDocument();
	} );

	it( 'stands the renewal tiles down for a locked rule', () => {
		render( stats( { totalMatching: 36, countLimited: false, audience: audience( { application: 'locked' } ) } ) );

		labels().forEach( label => expect( screen.getByText( label ) ).toBeInTheDocument() );
		expect( screen.getAllByText( 'Not applicable' ) ).toHaveLength( 2 );
		expect( screen.getAllByText( 'Applies to new sign-ups only.' ) ).toHaveLength( 2 );
		expect( screen.queryByText( '8' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the scope count bounded under a locked rule the engine capped', () => {
		render( stats( { totalMatching: 36, countLimited: false, audience: audience( { application: 'locked', count_limited: true } ) } ) );

		expect( within( tileFor( 'Subscribers in scope' ) ).getByText( '12+' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Eligible at renewal' ) ).getByText( '—' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Protected' ) ).getByText( '—' ) ).toBeInTheDocument();
	} );

	// The engine never claims 'locked' for a set whose rules disagree.
	it( 'keeps the numbers for a mixed rule set', () => {
		render( stats( { totalMatching: 36, countLimited: false, audience: audience( { application: 'mixed' } ) } ) );

		expect( within( tileFor( 'Eligible at renewal' ) ).getByText( '8' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Not applicable' ) ).not.toBeInTheDocument();
	} );

	it( 'falls back to the em-dash when a count is missing', () => {
		render( stats( { totalMatching: 36, countLimited: false, audience: audience( { caught: undefined } ) } ) );

		expect( within( tileFor( 'Eligible at renewal' ) ).getByText( '—' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'NaN' ) ).not.toBeInTheDocument();
		expect( within( tileFor( 'Subscribers in scope' ) ).getByText( '12' ) ).toBeInTheDocument();
	} );

	it( 'announces a missing count differently from a locked one', () => {
		render( stats( { totalMatching: 36, countLimited: false, audience: audience( { caught: null } ) } ) );

		expect( within( tileFor( 'Eligible at renewal' ) ).getByText( 'Unavailable' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Not applicable' ) ).not.toBeInTheDocument();
	} );

	// Zero is a real answer, so it must survive the missing-count guard.
	it( 'renders a zero count as a number, not as the em-dash', () => {
		render( stats( { totalMatching: 0, countLimited: false, audience: audience( { caught: 0 } ) } ) );

		expect( within( tileFor( 'Products affected' ) ).getByText( '0' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Eligible at renewal' ) ).getByText( '0' ) ).toBeInTheDocument();
		expect( screen.queryByText( '—' ) ).not.toBeInTheDocument();
	} );

	it( 'refuses a count that is not a number or a numeric string', () => {
		render( stats( { totalMatching: 36, countLimited: false, audience: audience( { caught: false, protected: [] } ) } ) );

		expect( within( tileFor( 'Eligible at renewal' ) ).getByText( '—' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Protected' ) ).getByText( '—' ) ).toBeInTheDocument();
		expect( screen.queryByText( '0' ) ).not.toBeInTheDocument();
	} );

	it( 'formats a count the engine sent as a string', () => {
		render( stats( { totalMatching: '500', countLimited: false, audience: audience( { total: '12', caught: '8', protected: '4' } ) } ) );

		expect( within( tileFor( 'Products affected' ) ).getByText( '500' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Subscribers in scope' ) ).getByText( '12' ) ).toBeInTheDocument();
		expect( within( tileFor( 'Eligible at renewal' ) ).getByText( '8' ) ).toBeInTheDocument();
		expect( screen.queryByText( '—' ) ).not.toBeInTheDocument();
	} );
} );
