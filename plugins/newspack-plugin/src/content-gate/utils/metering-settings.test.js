/**
 * The allowance is read from the DOM when a caller asks for it, never at module
 * evaluation, because a performance optimizer can put the element in the page after
 * this module has loaded. Memoizing a miss would freeze the first caller's answer for
 * the page and reintroduce exactly that order dependency.
 */

const ELEMENT_ID = 'newspack-content-gate-metering-settings';

const SETTINGS = { count: 2, gate_id: 84, meter_key: 'news' };

/**
 * Append the allowance element to the page.
 *
 * @param {string} json Element contents.
 */
function printSettings( json ) {
	const element = document.createElement( 'script' );
	element.type = 'application/json';
	element.id = ELEMENT_ID;
	element.textContent = json;
	document.body.appendChild( element );
}

/**
 * Load the util fresh, so its module-level cache starts empty.
 *
 * @return {Object} The module's exports.
 */
function loadUtil() {
	let util;
	jest.isolateModules( () => {
		util = require( './metering-settings' );
	} );
	return util;
}

describe( 'metering settings', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'answers a caller that asked before the element reached the page', () => {
		const { getMeteringSettings } = loadUtil();

		expect( getMeteringSettings() ).toBeNull();
		printSettings( JSON.stringify( SETTINGS ) );

		expect( getMeteringSettings() ).toEqual( SETTINGS );
	} );

	it( 'returns null and warns once when the allowance will not parse', () => {
		printSettings( 'not json' );
		const { getMeteringSettings } = loadUtil();
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );

		expect( getMeteringSettings() ).toBeNull();
		expect( getMeteringSettings() ).toBeNull();

		expect( warn ).toHaveBeenCalledTimes( 1 );
		warn.mockRestore();
	} );
} );
