/**
 * What the Republish Copy button puts on the clipboard.
 *
 * The block editor strips pasted scripts but keeps pasted block markup, so the
 * HTML copy wraps the tracking in a Custom HTML block. The plain-text copy
 * stays exactly as shown, because a classic editor's Code tab reads it and
 * block markup there would stop WordPress adding paragraph tags.
 */

import '../assets/clipboard-utils';

const PIXEL =
	'<img id="republication-tracker-tool-source" src="https://example.test/?republication-pixel=true&post=1&amp;ga4=G-TEST" style="width:1px;height:1px;">';
const TRACKING =
	PIXEL + '<script> PARSELY = { autotrack: false }; </script> <script id="parsely-cfg" src="//cdn.parsely.com/keys/example.test/p.js"></script>';
const ATTRIBUTION = '<p>This <a href="https://example.test/story/">article</a> first appeared on <a href="https://example.test/">Example</a>.</p>\n';
// The story quotes another handout's pixel, so only a match on the last pixel
// keeps the story and the attribution outside the wrapper.
const STORY = '<h1>Story</h1>\n<p>Quoted: ' + PIXEL + '</p>\n';
const HANDOUT = STORY + ATTRIBUTION + TRACKING;

class FakeBlob {
	constructor( parts, options ) {
		this.content = parts.join( '' );
		this.type = options.type;
	}
}

class FakeClipboardItem {
	constructor( items ) {
		this.items = items;
	}
}

const { ClipboardUtils } = window;
const OriginalBlob = global.Blob;
let clipboard;

const textarea = value => {
	const element = document.createElement( 'textarea' );
	element.value = value;
	return element;
};

const writtenFlavors = () => {
	const [ [ items ] ] = clipboard.write.mock.calls;
	expect( items ).toHaveLength( 1 );
	return Object.fromEntries( Object.entries( items[ 0 ].items ).map( ( [ type, blob ] ) => [ type, blob.content ] ) );
};

beforeEach( () => {
	clipboard = {
		write: jest.fn().mockResolvedValue( undefined ),
		writeText: jest.fn().mockResolvedValue( undefined ),
	};
	Object.defineProperty( window.navigator, 'clipboard', { value: clipboard, configurable: true } );
	global.ClipboardItem = FakeClipboardItem;
	global.Blob = FakeBlob;
} );

afterEach( () => {
	delete global.ClipboardItem;
	global.Blob = OriginalBlob;
	jest.restoreAllMocks();
} );

describe( 'ClipboardUtils.copyHandoutFromElement', () => {
	it( 'copies the handout unchanged as plain text and wraps only the tracking in the HTML copy', async () => {
		await expect( ClipboardUtils.copyHandoutFromElement( textarea( HANDOUT ) ) ).resolves.toBe( true );

		expect( clipboard.writeText ).not.toHaveBeenCalled();
		expect( writtenFlavors() ).toEqual( {
			'text/plain': HANDOUT,
			'text/html': STORY + ATTRIBUTION + '\n<!-- wp:html -->\n' + TRACKING + '\n<!-- /wp:html -->\n',
		} );
	} );

	it( 'starts the clipboard write before its first await, while the click still counts', () => {
		ClipboardUtils.copyHandoutFromElement( textarea( HANDOUT ) );

		expect( clipboard.write ).toHaveBeenCalledTimes( 1 );
	} );

	it.each( [
		[ 'ClipboardItem is unavailable', HANDOUT, () => delete global.ClipboardItem ],
		[ 'the clipboard has no write()', HANDOUT, () => delete clipboard.write ],
		[ 'the handout has no tracking pixel', STORY.replace( PIXEL, '' ) + ATTRIBUTION, () => {} ],
		// A paste keeps a lone pixel without the wrapper, which would only cost
		// the story its ordinary blocks.
		[ 'the tracking is the pixel alone', STORY + ATTRIBUTION + PIXEL + '\n', () => {} ],
	] )( 'copies the plain text alone when %s', async ( _label, handout, arrange ) => {
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		arrange();

		await expect( ClipboardUtils.copyHandoutFromElement( textarea( handout ) ) ).resolves.toBe( true );

		expect( clipboard.writeText ).toHaveBeenCalledWith( handout );
		// A warning would mean the HTML write was tried and failed, not skipped.
		expect( warn ).not.toHaveBeenCalled();
	} );

	it( 'copies the plain text alone, and logs why, when the browser refuses the HTML copy', async () => {
		const refusal = new Error( 'NotAllowedError' );
		clipboard.write.mockRejectedValue( refusal );
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );

		await expect( ClipboardUtils.copyHandoutFromElement( textarea( HANDOUT ) ) ).resolves.toBe( true );

		expect( clipboard.writeText ).toHaveBeenCalledWith( HANDOUT );
		expect( warn ).toHaveBeenCalledWith( expect.any( String ), refusal );
	} );
} );
