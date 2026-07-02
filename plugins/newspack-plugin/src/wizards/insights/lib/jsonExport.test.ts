/**
 * Tests for the JSON export helpers (NEWS-2587).
 */

/**
 * Internal dependencies
 */
import { buildJsonFilename, downloadJson } from './jsonExport';

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

describe( 'downloadJson', () => {
	let createObjectURL: jest.Mock;
	let revokeObjectURL: jest.Mock;
	let clickSpy: jest.SpyInstance;
	let capturedBlob: Blob | null;

	// jsdom does not implement URL.createObjectURL / URL.revokeObjectURL, so
	// jest.spyOn would throw (can't spy on undefined). We assign them directly
	// and save/restore around each test to prevent cross-suite pollution.
	let origCreateObjectURL: typeof URL.createObjectURL;
	let origRevokeObjectURL: typeof URL.revokeObjectURL;

	beforeEach( () => {
		capturedBlob = null;
		origCreateObjectURL = URL.createObjectURL;
		origRevokeObjectURL = URL.revokeObjectURL;

		createObjectURL = jest.fn( ( blob: Blob ) => {
			capturedBlob = blob;
			return 'blob:mock-url';
		} );
		revokeObjectURL = jest.fn( () => undefined );
		const urlMock = global.URL as unknown as { createObjectURL: typeof URL.createObjectURL; revokeObjectURL: typeof URL.revokeObjectURL };
		urlMock.createObjectURL = createObjectURL;
		urlMock.revokeObjectURL = revokeObjectURL;
		clickSpy = jest.spyOn( HTMLAnchorElement.prototype, 'click' ).mockImplementation( () => undefined );
	} );

	afterEach( () => {
		clickSpy.mockRestore();
		// Restore URL globals so later suites in the same worker are not polluted.
		const urlMock = global.URL as unknown as { createObjectURL: typeof URL.createObjectURL; revokeObjectURL: typeof URL.revokeObjectURL };
		urlMock.createObjectURL = origCreateObjectURL;
		urlMock.revokeObjectURL = origRevokeObjectURL;
	} );

	it( 'triggers a download with the given filename', () => {
		let downloadName = '';
		clickSpy.mockImplementation( function ( this: HTMLAnchorElement ) {
			downloadName = this.download;
		} );

		downloadJson( 'audience-export.json', { a: 1 } );

		expect( clickSpy ).toHaveBeenCalledTimes( 1 );
		expect( downloadName ).toBe( 'audience-export.json' );
	} );

	it( 'writes pretty-printed JSON of the data into an application/json blob', async () => {
		const data = { current: { views: 10 }, previous: null };
		downloadJson( 'x.json', data );

		expect( capturedBlob ).not.toBeNull();
		expect( capturedBlob!.type ).toBe( 'application/json' );

		// jsdom does not implement Blob.prototype.text(), so read via FileReader.
		const text = await new Promise< string >( ( resolve, reject ) => {
			const reader = new FileReader();
			reader.onload = () => resolve( reader.result as string );
			reader.onerror = () => reject( reader.error );
			reader.readAsText( capturedBlob! );
		} );
		expect( text ).toBe( JSON.stringify( data, null, 2 ) );
	} );

	it( 'revokes the object URL it created', () => {
		downloadJson( 'x.json', { a: 1 } );
		expect( createObjectURL ).toHaveBeenCalledTimes( 1 );
		expect( revokeObjectURL ).toHaveBeenCalledWith( 'blob:mock-url' );
	} );
} );
