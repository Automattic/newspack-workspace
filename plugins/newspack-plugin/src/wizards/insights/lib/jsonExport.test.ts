/**
 * Tests for the JSON export helpers (NEWS-2587).
 */

/**
 * Internal dependencies
 */
import { buildJsonFilename } from './jsonExport';

describe( 'buildJsonFilename', () => {
	const computedAt = '2026-07-01T09:30:00Z';

	it( 'builds <computedAt date>-<tab>-<presetSlug>.json for a last-N preset', () => {
		expect( buildJsonFilename( 'engagement', 'last-7', computedAt ) ).toBe( '2026-07-01-engagement-last-7-days.json' );
	} );

	it( 'uses "custom-dates" for the custom preset', () => {
		expect( buildJsonFilename( 'engagement', 'custom', computedAt ) ).toBe( '2026-07-01-engagement-custom-dates.json' );
	} );

	it( 'maps every preset to its filename slug', () => {
		const cases: Array< [ Parameters< typeof buildJsonFilename >[ 1 ], string ] > = [
			[ 'last-7', 'last-7-days' ],
			[ 'last-30', 'last-30-days' ],
			[ 'last-90', 'last-90-days' ],
			[ 'this-month', 'this-month' ],
			[ 'last-month', 'last-month' ],
			[ 'custom', 'custom-dates' ],
		];
		cases.forEach( ( [ preset, slug ] ) => {
			expect( buildJsonFilename( 'audience', preset, computedAt ) ).toBe( `2026-07-01-audience-${ slug }.json` );
		} );
	} );

	it( 'renders the date in site time (UTC in tests) from the computedAt timestamp', () => {
		expect( buildJsonFilename( 'donors', 'last-30', '2026-12-31T23:59:59Z' ) ).toBe( '2026-12-31-donors-last-30-days.json' );
	} );
} );
