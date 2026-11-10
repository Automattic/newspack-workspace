import { getSettings, setSettings } from '@wordpress/date';

import { getDataViewsType, getDisplayValue, getFilterByOperators, isEmptyValue } from './fields';

describe( 'fields utils', () => {
	describe( 'getDataViewsType', () => {
		it( 'uses the array type for multi-value fields so "is all" stays available', () => {
			expect( getDataViewsType( { type: 'number', is_multiple: true, options: [ { value: 1, label: 'Weekend Edition' } ] } ) ).toBe( 'array' );
		} );

		it( 'treats a numeric field with options as text so its label is not right-aligned', () => {
			expect( getDataViewsType( { type: 'number', options: [ { value: 1, label: 'Weekend Edition' } ] } ) ).toBe( 'text' );
		} );

		it( 'keeps the type of a plain numeric field', () => {
			expect( getDataViewsType( { type: 'number' } ) ).toBe( 'number' );
		} );
	} );

	describe( 'getFilterByOperators', () => {
		it( 'leaves out "is not all", which DataViews labels the same as "is none of"', () => {
			expect( getFilterByOperators( { is_multiple: true } ) ).not.toContain( 'isNotAll' );
		} );
	} );

	describe( 'isEmptyValue', () => {
		it( 'treats only null, undefined and an empty string as empty', () => {
			expect( isEmptyValue( null ) ).toBe( true );
			expect( isEmptyValue( undefined ) ).toBe( true );
			expect( isEmptyValue( '' ) ).toBe( true );
			expect( isEmptyValue( 0 ) ).toBe( false );
			expect( isEmptyValue( false ) ).toBe( false );
			expect( isEmptyValue( 'draft' ) ).toBe( false );
		} );
	} );

	describe( 'getDisplayValue', () => {
		it( 'should return null for missing values', () => {
			const field = { type: 'text', slug: 'title' };
			expect( getDisplayValue( field, null ) ).toBeNull();
			expect( getDisplayValue( field, undefined ) ).toBeNull();
			expect( getDisplayValue( field, '' ) ).toBeNull();
			expect( getDisplayValue( field, [] ) ).toBeNull();
		} );

		describe( 'on a site with its own formats and timezone', () => {
			const defaults = getSettings();

			beforeEach( () =>
				setSettings( {
					...defaults,
					formats: { ...defaults.formats, date: 'd/m/Y', time: 'g:i a' },
					timezone: { ...defaults.timezone, string: 'Pacific/Auckland', offset: 13 },
				} )
			);
			afterEach( () => setSettings( defaults ) );

			// 2025-06-05 22:30 UTC is 10:30 am the next day in Auckland.
			const timestamp = Date.UTC( 2025, 5, 5, 22, 30 ) / 1000;

			it( 'formats a date in the site date format and timezone', () => {
				expect( getDisplayValue( { type: 'date', slug: 'publish_date' }, timestamp ) ).toBe( '06/06/2025' );
			} );

			it( 'formats a datetime in the site date and time formats, keeping am and pm', () => {
				expect( getDisplayValue( { type: 'datetime', slug: 'last_modified' }, timestamp ) ).toBe( '06/06/2025 10:30 am' );
			} );
		} );

		it( 'should format boolean values', () => {
			const field = { type: 'boolean', slug: 'is_featured' };
			expect( getDisplayValue( field, true ) ).toBe( 'Yes' );
			expect( getDisplayValue( field, false ) ).toBe( 'No' );
		} );

		it( 'should join array values with commas', () => {
			const field = { type: 'text', slug: 'categories' };
			expect( getDisplayValue( field, [ 'news', 'politics' ] ) ).toBe( 'news, politics' );
		} );

		it( 'should handle options mapping', () => {
			const field = {
				type: 'text',
				slug: 'status',
				options: [
					{ value: 'draft', label: 'Draft' },
					{ value: 'published', label: 'Published' },
				],
			};
			expect( getDisplayValue( field, 'draft' ) ).toBe( 'Draft' );
			expect( getDisplayValue( field, 'published' ) ).toBe( 'Published' );
		} );

		it( 'should handle array options mapping', () => {
			const field = {
				type: 'text',
				slug: 'categories',
				options: [
					{ value: 'news', label: 'News' },
					{ value: 'sports', label: 'Sports' },
					{ value: 'politics', label: 'Politics' },
				],
			};
			expect( getDisplayValue( field, [ 'news', 'politics' ] ) ).toEqual( 'News, Politics' );
		} );

		it( 'should return the original value when no transformation is needed', () => {
			const field = { type: 'text', slug: 'title' };
			expect( getDisplayValue( field, 'Hello World' ) ).toBe( 'Hello World' );

			const numField = { type: 'number', slug: 'priority' };
			expect( getDisplayValue( numField, 2026 ) ).toBe( 2026 );
		} );

		it( 'should group a number for the locale', () => {
			expect( getDisplayValue( { type: 'number', slug: 'word_count' }, 2418 ) ).toBe( ( 2418 ).toLocaleString() );
			expect( getDisplayValue( { type: 'number', slug: 'image_count' }, 1200 ) ).toBe( ( 1200 ).toLocaleString() );
			expect( getDisplayValue( { type: 'number', slug: 'word_count' }, 0 ) ).toBe( '0' );
			expect( getDisplayValue( { type: 'number', slug: 'word_count' }, null ) ).toBeNull();
		} );

		it( 'should handle text fields with falsy non-empty values', () => {
			const field = { type: 'text', slug: 'title' };
			expect( getDisplayValue( field, '0' ) ).toBe( '0' );
		} );
	} );
} );
