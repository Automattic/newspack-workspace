import { getDisplayValue } from './fields';

describe( 'fields utils', () => {
	describe( 'getDisplayValue', () => {
		it( 'should return null for missing values', () => {
			const field = { type: 'text', slug: 'title' };
			expect( getDisplayValue( field, null ) ).toBeNull();
			expect( getDisplayValue( field, undefined ) ).toBeNull();
			expect( getDisplayValue( field, '' ) ).toBeNull();
			expect( getDisplayValue( field, [] ) ).toBeNull();
		} );

		it( 'should format date values', () => {
			const field = { type: 'date', slug: 'published_date' };
			const originalDateFormat = Intl.DateTimeFormat;
			Intl.DateTimeFormat = jest.fn().mockImplementation( () => ( {
				format: () => '2025-06-05',
			} ) );

			const timestamp = 1717612800; // 2025-06-05 UTC
			const result = getDisplayValue( field, timestamp );

			Intl.DateTimeFormat = originalDateFormat;

			expect( result ).not.toBeNull();
			expect( typeof result ).toBe( 'string' );
		} );

		it( 'should format datetime values', () => {
			const field = { type: 'datetime', slug: 'published_datetime' };
			const originalDateFormat = Intl.DateTimeFormat;
			Intl.DateTimeFormat = jest.fn().mockImplementation( () => ( {
				format: () => '2025-06-05, 10:30 AM',
			} ) );

			const timestamp = 1717650000; // 2025-06-05, 10:30 AM UTC
			const result = getDisplayValue( field, timestamp );

			Intl.DateTimeFormat = originalDateFormat;

			expect( result ).not.toBeNull();
			expect( typeof result ).toBe( 'string' );
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
			expect( getDisplayValue( numField, 123 ) ).toBe( 123 );
		} );

		it( 'should handle text fields with falsy non-empty values', () => {
			const field = { type: 'text', slug: 'title' };
			expect( getDisplayValue( field, '0' ) ).toBe( '0' );
		} );
	} );
} );
