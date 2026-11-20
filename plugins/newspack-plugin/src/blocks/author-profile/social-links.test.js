import { getSocialLinks } from './social-links';

const author = () => ( {
	social: { twitter: { url: 'https://x.com/a' } },
	email: { url: 'mailto:a@example.test' },
	newspack_phone_number: { url: 'tel:1' },
} );

describe( 'getSocialLinks', () => {
	it( 'adds the email to the social links when it is shown', () => {
		expect( getSocialLinks( author(), { showSocial: true, showEmail: true } ) ).toEqual( {
			twitter: { url: 'https://x.com/a' },
			email: { url: 'mailto:a@example.test' },
		} );
	} );

	it( 'returns only the email when social links are hidden', () => {
		expect( getSocialLinks( author(), { showSocial: false, showEmail: true } ) ).toEqual( { email: { url: 'mailto:a@example.test' } } );
	} );

	it( 'adds the phone number when it is shown', () => {
		expect( getSocialLinks( author(), { showSocial: false, showEmail: false, showPhone: true } ) ).toEqual( {
			newspack_phone_number: { url: 'tel:1' },
		} );
	} );

	it( 'leaves the author record untouched', () => {
		const record = author();

		getSocialLinks( record, { showSocial: true, showEmail: true } );
		expect( record.social ).toEqual( { twitter: { url: 'https://x.com/a' } } );

		// A record shared between blocks may already carry a link another block added.
		record.social.email = { url: 'mailto:other@example.test' };
		expect( getSocialLinks( record, { showSocial: true, showEmail: false } ) ).toEqual( { twitter: { url: 'https://x.com/a' } } );
		expect( record.social.email ).toEqual( { url: 'mailto:other@example.test' } );
	} );

	it( 'returns an empty object for a missing author', () => {
		expect( getSocialLinks( undefined, { showSocial: true, showEmail: true } ) ).toEqual( {} );
	} );
} );
