// The post subtitle is post meta, which kses does not filter, and this module turns it
// into a core/heading attribute that is serialized into the newsletter's own
// post_content -- so anything that survives here is copied into a second post.
import { sanitizeSubtitle } from './utils';

describe( 'sanitizeSubtitle', () => {
	it( 'keeps the inline formatting a subtitle is allowed to carry', () => {
		expect( sanitizeSubtitle( 'A <em>real</em> <strong>subtitle</strong>' ) ).toBe( 'A <em>real</em> <strong>subtitle</strong>' );
	} );

	it( 'keeps a link and its target and rel', () => {
		expect( sanitizeSubtitle( '<a href="https://example.com" target="_blank" rel="noopener">Link</a>' ) ).toBe(
			'<a href="https://example.com" target="_blank" rel="noopener">Link</a>'
		);
	} );

	it( 'drops an element that is not on the allowlist but keeps its text', () => {
		expect( sanitizeSubtitle( 'before <span class="x">middle</span> after' ) ).toBe( 'before middle after' );
	} );

	it( 'removes an event handler from an allowed element', () => {
		expect( sanitizeSubtitle( '<em onmouseover="steal()">hover</em>' ) ).toBe( '<em>hover</em>' );
	} );

	it( 'removes an image carrying an error handler', () => {
		// The reported payload: an element that fires without any interaction.
		const out = sanitizeSubtitle( '<img src=x onerror="alert(1)">' );
		expect( out ).not.toContain( 'onerror' );
		expect( out ).not.toContain( '<img' );
	} );

	it( 'drops a link whose protocol is not one a subtitle may link to', () => {
		const out = sanitizeSubtitle( '<a href="javascript:alert(1)">Click</a>' );
		expect( out ).toBe( '<a>Click</a>' );
	} );

	it( 'keeps a relative and a fragment link', () => {
		expect( sanitizeSubtitle( '<a href="/about">About</a>' ) ).toBe( '<a href="/about">About</a>' );
		expect( sanitizeSubtitle( '<a href="#top">Top</a>' ) ).toBe( '<a href="#top">Top</a>' );
	} );

	it( 'unwraps nested disallowed elements all the way down', () => {
		expect( sanitizeSubtitle( '<div><span><em>deep</em></span></div>' ) ).toBe( '<em>deep</em>' );
	} );

	it( 'returns an empty string for an empty subtitle', () => {
		expect( sanitizeSubtitle( '' ) ).toBe( '' );
	} );
} );
