import { getMatchedForms, getEmailValue, getNameValues } from './utils';

describe( 'getMatchedForms', () => {
	beforeEach( () => {
		document.body.innerHTML = `
			<form id="direct" class="newspack-form-capture"></form>
			<div id="container"><form id="inner"></form></div>
			<form id="other"></form>
			<div id="formless"></div>
		`;
	} );
	it( 'matches forms directly and resolves containers to their inner form', () => {
		const forms = getMatchedForms( [ '.newspack-form-capture', '#container' ] );
		expect( forms.map( f => f.id ) ).toEqual( [ 'direct', 'inner' ] );
	} );
	it( 'dedupes and ignores invalid selectors and formless matches', () => {
		const forms = getMatchedForms( [ '.newspack-form-capture', '.newspack-form-capture', ':::garbage', '#formless' ] );
		expect( forms.map( f => f.id ) ).toEqual( [ 'direct' ] );
	} );
	it( 'cannot use a selector list with a trailing comma', () => {
		// Invalid CSS, so querySelectorAll() throws and the whole list is lost —
		// which is why Form_Capture::get_selectors() rebuilds each configured
		// line from its non-empty parts before it reaches the client.
		document.body.innerHTML = `<form id="a"></form><form id="b"></form>`;
		expect( getMatchedForms( [ '#a, #b' ] ).map( f => f.id ) ).toEqual( [ 'a', 'b' ] );
		expect( getMatchedForms( [ '#a, #b,' ] ) ).toEqual( [] );
	} );
} );

describe( 'getEmailValue', () => {
	it( 'prefers input[type=email]', () => {
		document.body.innerHTML = `<form><input type="text" name="whatever" value="x"><input type="email" value="reader@example.com"></form>`;
		expect( getEmailValue( document.querySelector( 'form' ) ) ).toBe( 'reader@example.com' );
	} );
	it( 'falls back to name/id heuristics for text inputs (CF7 style)', () => {
		document.body.innerHTML = `<form><input type="text" name="your-email" value="reader@example.com"></form>`;
		expect( getEmailValue( document.querySelector( 'form' ) ) ).toBe( 'reader@example.com' );
	} );
	it( 'returns empty for invalid email values', () => {
		document.body.innerHTML = `<form><input type="email" value="not-an-email"></form>`;
		expect( getEmailValue( document.querySelector( 'form' ) ) ).toBe( '' );
	} );
	it( 'returns empty when there is no email field', () => {
		document.body.innerHTML = `<form><input type="text" name="city" value="Lisbon"></form>`;
		expect( getEmailValue( document.querySelector( 'form' ) ) ).toBe( '' );
	} );
	it( 'skips an empty honeypot text input and returns the first valid email value', () => {
		document.body.innerHTML = `<form>
			<input type="text" name="email" value="" style="display:none">
			<input type="text" name="your-email" value="reader@example.com">
		</form>`;
		expect( getEmailValue( document.querySelector( 'form' ) ) ).toBe( 'reader@example.com' );
	} );
	it( 'skips an empty first email input (confirmation layouts) for a later valid one', () => {
		document.body.innerHTML = `<form>
			<input type="email" value="">
			<input type="email" value="reader@example.com">
		</form>`;
		expect( getEmailValue( document.querySelector( 'form' ) ) ).toBe( 'reader@example.com' );
	} );
	it( 'lower-cases the harvested email so client dedupe agrees with the server', () => {
		document.body.innerHTML = `<form><input type="email" value="Reader@Example.com"></form>`;
		expect( getEmailValue( document.querySelector( 'form' ) ) ).toBe( 'reader@example.com' );
	} );
} );

describe( 'getNameValues', () => {
	it( 'finds first/last name by autocomplete attributes (GF advanced name field)', () => {
		document.body.innerHTML = `<form>
			<input type="text" name="input_1_3" autocomplete="given-name" value="Ada">
			<input type="text" name="input_1_6" autocomplete="family-name" value="Lovelace">
		</form>`;
		expect( getNameValues( document.querySelector( 'form' ) ) ).toEqual( { first_name: 'Ada', last_name: 'Lovelace' } );
	} );
	it( 'finds first/last name by name attributes', () => {
		document.body.innerHTML = `<form>
			<input type="text" name="first_name" value="Ada">
			<input type="text" name="last-name" value="Lovelace">
		</form>`;
		expect( getNameValues( document.querySelector( 'form' ) ) ).toEqual( { first_name: 'Ada', last_name: 'Lovelace' } );
	} );
	it( 'uses a single full-name field as first_name', () => {
		document.body.innerHTML = `<form><input type="text" name="name" value="Ada Lovelace"></form>`;
		expect( getNameValues( document.querySelector( 'form' ) ) ).toEqual( { first_name: 'Ada Lovelace', last_name: '' } );
	} );
	it( 'returns empty object when nothing matches', () => {
		document.body.innerHTML = `<form><input type="text" name="username" value="ada"></form>`;
		expect( getNameValues( document.querySelector( 'form' ) ) ).toEqual( {} );
	} );
	it( 'does not treat a non-person name field as the reader name', () => {
		document.body.innerHTML = `<form>
			<input type="text" name="organization_name" value="Acme Corp">
			<input type="text" name="display-name" value="acme_handle">
		</form>`;
		expect( getNameValues( document.querySelector( 'form' ) ) ).toEqual( {} );
	} );
	it( 'still harvests a real full-name field alongside a non-person name field', () => {
		document.body.innerHTML = `<form>
			<input type="text" name="company_name" value="Acme Corp">
			<input type="text" name="name" value="Ada Lovelace">
		</form>`;
		expect( getNameValues( document.querySelector( 'form' ) ) ).toEqual( { first_name: 'Ada Lovelace', last_name: '' } );
	} );
} );
