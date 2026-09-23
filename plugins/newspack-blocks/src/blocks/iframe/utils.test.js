import { isEmbeddableSrc } from './utils';

describe( 'isEmbeddableSrc', () => {
	it.each( [
		[ 'script scheme', 'javascript:void(0)' ],
		[ 'mixed-case scheme', 'JavaScript:void(0)' ],
		[ 'leading whitespace', '  javascript:void(0)' ],
		[ 'embedded tab', 'java\tscript:void(0)' ],
		[ 'data scheme', 'data:text/html,<p>x</p>' ],
		[ 'vbscript scheme', 'vbscript:msgbox(1)' ],
		[ 'empty', '' ],
	] )( 'does not load a source outside http(s): %s', ( _label, src ) => {
		expect( isEmbeddableSrc( src ) ).toBe( false );
	} );

	it.each( [
		[ 'https', 'https://example.test/embed?id=1' ],
		[ 'http', 'http://example.test/embed' ],
		[ 'uploads-relative', '/wp-content/uploads/iframe/demo/index.html' ],
	] )( 'loads a supported source: %s', ( _label, src ) => {
		expect( isEmbeddableSrc( src ) ).toBe( true );
	} );
} );
