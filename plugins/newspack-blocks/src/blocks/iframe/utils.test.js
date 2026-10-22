import { getEmbeddableSrc } from './utils';

describe( 'getEmbeddableSrc', () => {
	it.each( [
		[ 'script scheme', 'javascript:void(0)' ],
		[ 'mixed-case scheme', 'JavaScript:void(0)' ],
		[ 'leading whitespace', '  javascript:void(0)' ],
		[ 'embedded tab', 'java\tscript:void(0)' ],
		[ 'data scheme', 'data:text/html,<p>x</p>' ],
		[ 'vbscript scheme', 'vbscript:msgbox(1)' ],
		[ 'empty', '' ],
		[ 'whitespace only', '   ' ],
	] )( 'loads nothing for a source outside http(s): %s', ( _label, src ) => {
		expect( getEmbeddableSrc( src ) ).toBe( '' );
	} );

	it.each( [
		[ 'https', 'https://example.test/embed?id=1', 'https://example.test/embed?id=1' ],
		[ 'http', 'http://example.test/embed', 'http://example.test/embed' ],
		[ 'uploads-relative', '/wp-content/uploads/iframe/demo/index.html', `${ window.location.origin }/wp-content/uploads/iframe/demo/index.html` ],
		[ 'no scheme, as the front end completes it', 'example.test/page', 'https://example.test/page' ],
	] )( 'loads a supported source: %s', ( _label, src, expected ) => {
		expect( getEmbeddableSrc( src ) ).toBe( expected );
	} );
} );
