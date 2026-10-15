/**
 * What the Republish Button block's modal leaves in its copy textarea.
 *
 * The modal strips captions by parsing the textarea's text and writing the
 * result back. Writing back has to keep the text exactly as parsed: code a story
 * shows as text stays text, and the footer stays working markup. The served
 * encoding this starts from is pinned by tests/test-modal-handout-encoding.php.
 */

// The textarea's value once the browser has decoded the served source: the
// story as HTML that shows code as text, a caption to strip, then the footer.
const STORY =
	'<p>Code: &lt;script&gt;window.rttMarker=1&lt;/script&gt;</p>\n' +
	'<div class="wp-caption"><img src="https://example.test/a.jpg" alt=""><p class="wp-caption-text">Caption</p></div>\n' +
	'<p>After the caption.</p>\n';
const FOOTER =
	'<p>This <a target="_blank" href="https://example.test/story/">article</a> first appeared on <a target="_blank" href="https://example.test">Example</a>.</p>\n' +
	'<img id="republication-tracker-tool-source" src="https://example.test/?republication-pixel=true&amp;post=1&amp;ga4=G-TEST" style="width:1px;height:1px;">' +
	'<script id="parsely-cfg" src="//cdn.parsely.com/keys/example.test/p.js"></script>';

const openModal = () => {
	document.body.innerHTML = `
		<button data-modal-trigger="republish">Republish</button>
		<div id="republication-tracker-tool-modal" style="display:none;">
			<div id="republication-tracker-tool-modal-content">
				<button class="republication-tracker-tool-close"></button>
				<textarea id="republication-tracker-tool-shareable-content"></textarea>
			</div>
		</div>`;
	const textarea = document.getElementById( 'republication-tracker-tool-shareable-content' );
	textarea.textContent = STORY + FOOTER;
	// view.js keeps module state (initialized once), so load a fresh copy per case.
	jest.isolateModules( () => require( './view' ) );
	document.querySelector( '[data-modal-trigger="republish"]' ).click();
	return textarea;
};

describe( 'Republish Button modal copy', () => {
	it( 'keeps code the story shows as text as text', () => {
		const { value } = openModal();
		expect( value ).toContain( 'Code: &lt;script&gt;window.rttMarker=1&lt;/script&gt;' );
		expect( value ).not.toContain( '<script>window.rttMarker' );
	} );

	it( 'keeps the footer as working markup', () => {
		const { value } = openModal();
		expect( value ).toContain( '<img id="republication-tracker-tool-source" src="' );
		expect( value ).toContain( '<script id="parsely-cfg" src="//cdn.parsely.com/keys/example.test/p.js"></script>' );
		expect( value ).toContain( '<a target="_blank" href="https://example.test/story/">article</a>' );
	} );

	it( 'still removes captions', () => {
		const { value } = openModal();
		expect( value ).not.toContain( 'wp-caption' );
		expect( value ).toContain( '<p>After the caption.</p>' );
	} );
} );
