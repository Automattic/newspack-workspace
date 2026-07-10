import { matchDonation, isDonorFromEmail } from './donation';

/**
 * Build the second argument the matching function receives, with a stubbed
 * reader data library store returning the given values.
 *
 * @param {Object}  values               Store values.
 * @param {boolean} values.isDonor       Value returned by store.get( 'is_donor' ).
 * @param {boolean} values.isFormerDonor Value returned by store.get( 'is_former_donor' ).
 * @return {Object} Stubbed reader activation object.
 */
const ras = ( { isDonor = false, isFormerDonor = false } = {} ) => ( {
	store: { get: key => ( 'is_former_donor' === key ? isFormerDonor : isDonor ) },
} );

describe( 'donation criteria matching', () => {
	beforeEach( () => {
		window.sessionStorage.clear();
		window.history.replaceState( {}, '', '/' );
	} );

	it( 'treats a non-donor arriving with no donor param as a non-donor', () => {
		expect( matchDonation( { value: 'donors' }, ras() ) ).toBe( false );
		expect( matchDonation( { value: 'non-donors' }, ras() ) ).toBe( true );
	} );

	it( 'treats a reader with the stored is_donor flag as a donor', () => {
		expect( matchDonation( { value: 'donors' }, ras( { isDonor: true } ) ) ).toBe( true );
		expect( matchDonation( { value: 'non-donors' }, ras( { isDonor: true } ) ) ).toBe( false );
	} );

	it( 'matches the former-donors value from the store', () => {
		expect( matchDonation( { value: 'formers-donors' }, ras( { isFormerDonor: true } ) ) ).toBe( true );
		expect( matchDonation( { value: 'formers-donors' }, ras() ) ).toBe( false );
	} );

	it.each( [ 'true', 'Yes', '1', 'monthly', '$50.00' ] )( 'treats a reader arriving with np_seg_donor=%s as a donor', value => {
		window.history.replaceState( {}, '', '/?np_seg_donor=' + encodeURIComponent( value ) );
		expect( matchDonation( { value: 'donors' }, ras() ) ).toBe( true );
		expect( matchDonation( { value: 'non-donors' }, ras() ) ).toBe( false );
	} );

	it.each( [ 'false', 'no', 'none', '0' ] )( 'does not treat a reader arriving with falsy np_seg_donor=%s as a donor', value => {
		window.history.replaceState( {}, '', '/?np_seg_donor=' + encodeURIComponent( value ) );
		expect( isDonorFromEmail() ).toBe( false );
	} );

	it( 'remembers a donor email arrival for the rest of the session', () => {
		// Landing page carries the param and sets the session flag.
		window.history.replaceState( {}, '', '/?np_seg_donor=true' );
		expect( matchDonation( { value: 'donors' }, ras() ) ).toBe( true );
		// Subsequent navigation to a clean URL still matches donors.
		window.history.replaceState( {}, '', '/some-article' );
		expect( matchDonation( { value: 'donors' }, ras() ) ).toBe( true );
	} );

	it.each( [
		[ 'Mailchimp', '*|HUB-MEMBER|*' ],
		[ 'Constant Contact', '[[DONOR]]' ],
		[ 'ActiveCampaign', '%DONOR%' ],
		[ 'Campaign Monitor', '[HUB-MEMBER]' ],
	] )( 'ignores an unsubstituted %s merge tag so every recipient is not flagged', ( _esp, value ) => {
		window.history.replaceState( {}, '', '/?np_seg_donor=' + encodeURIComponent( value ) );
		expect( isDonorFromEmail() ).toBe( false );
	} );

	it( 'still matches donors on an email arrival when sessionStorage writes are blocked', () => {
		window.history.replaceState( {}, '', '/?np_seg_donor=true' );
		const setSpy = jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		// The URL param is authoritative; a failed write only costs cross-navigation memory.
		expect( () => matchDonation( { value: 'donors' }, ras() ) ).not.toThrow();
		expect( matchDonation( { value: 'donors' }, ras() ) ).toBe( true );
		setSpy.mockRestore();
	} );

	it( 'does not throw when sessionStorage is fully unavailable', () => {
		window.history.replaceState( {}, '', '/some-article' );
		const setSpy = jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		const getSpy = jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		expect( () => isDonorFromEmail() ).not.toThrow();
		expect( isDonorFromEmail() ).toBe( false );
		setSpy.mockRestore();
		getSpy.mockRestore();
	} );
} );
