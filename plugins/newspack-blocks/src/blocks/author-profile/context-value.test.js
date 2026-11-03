import { getAuthorContextValue } from './context-value';

describe( 'getAuthorContextValue', () => {
	it( 'adds avatarHideDefault to the author when default avatars are hidden', () => {
		expect( getAuthorContextValue( { id: 5, name: 'Jane Doe' }, true ) ).toEqual( { id: 5, name: 'Jane Doe', avatarHideDefault: true } );
	} );

	it( 'sets avatarHideDefault to false when default avatars are shown', () => {
		expect( getAuthorContextValue( { id: 5 }, undefined ) ).toEqual( { id: 5, avatarHideDefault: false } );
	} );

	it( 'leaves the author record untouched', () => {
		const record = { id: 5 };

		getAuthorContextValue( record, true );
		expect( record ).toEqual( { id: 5 } );
	} );

	it( 'returns the Site Editor placeholder author unchanged', () => {
		const placeholder = { id: 'placeholder', avatar: '' };

		expect( getAuthorContextValue( placeholder, true ) ).toBe( placeholder );
	} );

	it( 'returns a missing author as it is', () => {
		expect( getAuthorContextValue( undefined, true ) ).toBeUndefined();
	} );
} );
