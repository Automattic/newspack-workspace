/**
 * Internal dependencies
 */
import { gateMatchesPost } from './gate-matching';
import { postMatchesPublishedAge } from '../utils/published-age';

const taxonomyMap = { category: 'categories' };

describe( 'gateMatchesPost', () => {
	const rules = [
		{ slug: 'category', value: [ 5 ] },
		{ slug: 'newsletters', value: [ 999 ] },
	];
	const termsByTax = { categories: [ 5 ] };

	it( 'AND: does not match when only one rule matches', () => {
		expect( gateMatchesPost( rules, 'post', termsByTax, 1, 'all', taxonomyMap ) ).toBe( false );
	} );

	it( 'ANY: matches when any one rule matches', () => {
		expect( gateMatchesPost( rules, 'post', termsByTax, 1, 'any', taxonomyMap ) ).toBe( true );
	} );

	it( 'specific_posts override matches regardless of mode', () => {
		const r = [
			{ slug: 'specific_posts', value: [ 1 ] },
			{ slug: 'category', value: [ 5 ] },
		];
		expect( gateMatchesPost( r, 'post', {}, 1, 'all', taxonomyMap ) ).toBe( true );
	} );

	it( 'defaults to AND when mode omitted', () => {
		expect( gateMatchesPost( rules, 'post', termsByTax, 1, undefined, taxonomyMap ) ).toBe( false );
	} );

	it( 'exclusion rule carves a post out even when an inclusion rule matches (ANY and ALL)', () => {
		const r = [
			{ slug: 'post_types', value: [ 'post' ] },
			{ slug: 'category', value: [ 5 ], exclusion: true },
		];
		// Post is in the excluded category 5: matches the post_types inclusion but is carved out.
		expect( gateMatchesPost( r, 'post', { categories: [ 5 ] }, 1, 'any', taxonomyMap ) ).toBe( false );
		// Post not in the excluded category: gated via the inclusion rule.
		expect( gateMatchesPost( r, 'post', { categories: [] }, 1, 'any', taxonomyMap ) ).toBe( true );
		// "Match all" is unchanged: the carve-out still applies, the non-excluded post is still gated.
		expect( gateMatchesPost( r, 'post', { categories: [ 5 ] }, 1, 'all', taxonomyMap ) ).toBe( false );
		expect( gateMatchesPost( r, 'post', { categories: [] }, 1, 'all', taxonomyMap ) ).toBe( true );
	} );

	it( 'exclusion-only gate applies to all content that is not carved out', () => {
		const r = [ { slug: 'category', value: [ 5 ], exclusion: true } ];
		// No inclusion rule: everything not in the excluded category is gated.
		expect( gateMatchesPost( r, 'post', { categories: [] }, 1, 'any', taxonomyMap ) ).toBe( true );
		expect( gateMatchesPost( r, 'post', { categories: [ 5 ] }, 1, 'any', taxonomyMap ) ).toBe( false );
	} );

	describe( 'published age', () => {
		// "Free for 7 days, then paid".
		const archiveRules = [
			{ slug: 'post_types', value: [ 'post' ] },
			{ slug: 'published_age', value: { comparison: 'more_than', duration_value: 7, duration_unit: 'days' } },
		];
		const daysAgo = days => new Date( Date.now() - days * 24 * 60 * 60 * 1000 );
		const matches = ( published, ageOverride = [] ) => gateMatchesPost( archiveRules, 'post', {}, 1, 'all', taxonomyMap, published, ageOverride );

		it( 'applies once the post is past the line, and not before', () => {
			expect( matches( daysAgo( 10 ) ) ).toBe( true );
			expect( matches( daysAgo( 3 ) ) ).toBe( false );
		} );

		it( "uses the post's own time window over the gate's", () => {
			expect( matches( daysAgo( 10 ), { duration_value: 30, duration_unit: 'days' } ) ).toBe( false );
		} );

		it( 'treats a post not yet published as new', () => {
			expect( matches( null ) ).toBe( false );
		} );

		it( 'counts months on the calendar, as PHP does: one month after Jan 31 is Mar 3', () => {
			const oneMonth = { comparison: 'more_than', duration_value: 1, duration_unit: 'months' };
			const published = new Date( '2026-01-31T12:00:00Z' );
			expect( postMatchesPublishedAge( oneMonth, published, null, new Date( '2026-03-02T12:00:00Z' ) ) ).toBe( false );
			expect( postMatchesPublishedAge( oneMonth, published, null, new Date( '2026-03-03T12:00:00Z' ) ) ).toBe( true );
		} );
	} );
} );
