// `@wordpress/block-editor`, `@wordpress/blocks`, and
// `@wordpress/block-library` are pulled in transitively by the
// layouts-list screen — `<NewsletterPreview>` uses the first two and
// the screen calls `registerCoreBlocks` on mount. All three ship as
// ESM and aren't covered by the default jest transform ignore list,
// which would otherwise fail this test on import. The registry
// shape we assert here doesn't need any of them to do anything —
// empty mocks are enough.
jest.mock( '@wordpress/block-editor', () => ( {
	BlockPreview: () => null,
} ) );
jest.mock( '@wordpress/blocks', () => ( {
	parse: () => [],
	registerBlockType: () => {},
	getBlockType: () => null,
} ) );
jest.mock( '@wordpress/block-library', () => ( {
	registerCoreBlocks: () => {},
} ) );

import { resolveLabel, resolveScreen, screens } from './index';

describe( 'admin-shell screen registry', () => {
	it( 'registers the list, ads list, advertisers list, layouts list, and settings slugs', () => {
		expect( Object.keys( screens ) ).toEqual( [
			'newspack-newsletters-list',
			'newspack-newsletters-ads-list',
			'newspack-newsletters-advertisers-list',
			'newspack-newsletters-layouts-list',
			'newspack-newsletters-settings',
		] );
	} );

	it( 'each screen entry exposes a component and a label', () => {
		Object.values( screens ).forEach( entry => {
			expect( typeof entry.component ).toBe( 'function' );
			expect( typeof entry.label ).toBe( 'string' );
			expect( entry.label.length ).toBeGreaterThan( 0 );
		} );
	} );

	it( 'resolves a known slug to its registry entry', () => {
		expect( resolveScreen( 'newspack-newsletters-list' ) ).toBe( screens[ 'newspack-newsletters-list' ] );
		expect( resolveScreen( 'newspack-newsletters-settings' ) ).toBe( screens[ 'newspack-newsletters-settings' ] );
	} );

	it( 'returns null for an unknown slug', () => {
		expect( resolveScreen( 'not-a-real-page' ) ).toBeNull();
	} );

	it( 'returns null for an empty slug', () => {
		expect( resolveScreen( '' ) ).toBeNull();
	} );
} );

describe( 'resolveLabel', () => {
	const REGISTRY_LIST_LABEL = screens[ 'newspack-newsletters-list' ].label;
	const REGISTRY_SETTINGS_LABEL = screens[ 'newspack-newsletters-settings' ].label;

	it( 'prefers the PHP-localised label when present so the rendered heading matches the admin menu', () => {
		const phpScope = { newspackNewslettersAdmin: { label: 'Custom PHP Label' } };
		expect( resolveLabel( 'newspack-newsletters-list', phpScope ) ).toBe( 'Custom PHP Label' );
	} );

	it( 'falls back to the JS registry label when the PHP global is missing', () => {
		expect( resolveLabel( 'newspack-newsletters-list', {} ) ).toBe( REGISTRY_LIST_LABEL );
		expect( resolveLabel( 'newspack-newsletters-settings', {} ) ).toBe( REGISTRY_SETTINGS_LABEL );
	} );

	it( 'falls back to the registry when the PHP global has no label key', () => {
		const phpScope = { newspackNewslettersAdmin: {} };
		expect( resolveLabel( 'newspack-newsletters-list', phpScope ) ).toBe( REGISTRY_LIST_LABEL );
	} );

	it( 'treats an empty PHP label as missing and falls back to the registry', () => {
		const phpScope = { newspackNewslettersAdmin: { label: '' } };
		expect( resolveLabel( 'newspack-newsletters-list', phpScope ) ).toBe( REGISTRY_LIST_LABEL );
	} );

	it( 'returns an empty string when neither source has anything to offer', () => {
		expect( resolveLabel( 'not-a-real-page', {} ) ).toBe( '' );
		expect( resolveLabel( '', {} ) ).toBe( '' );
	} );
} );
