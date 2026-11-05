/**
 * Which text the republish page's Copy to clipboard button copies.
 *
 * The markup below follows templates/republish-template.php for each value of
 * the "Offer plain text version alongside HTML" setting. With the setting off
 * the HTML textarea has no tab classes, and the button must copy it anyway.
 */

const tabsMarkup = `
	<div class="republish-format-tabs">
		<button class="republish-format-tabs__button republish-format-tabs__button--active" data-tab="html">HTML</button>
		<button class="republish-format-tabs__button" data-tab="plain-text">Plain Text</button>
	</div>`;

const plainTextMarkup = `
	<div class="republish-content" data-tab-content="plain-text">
		<div class="plain-text-field">
			<input type="text" id="republish-canonical-url" class="republish-content__input" readonly value="<link rel=&quot;canonical&quot; />" />
			<button class="plain-text-field__button" data-target="#republish-canonical-url">Copy Tag</button>
		</div>
		<div class="plain-text-field">
			<textarea id="republish-plain-text-content" class="republish-content__textarea" readonly>Story</textarea>
			<button class="plain-text-field__button" data-target="#republish-plain-text-content">Copy Content</button>
		</div>
		<div class="plain-text-field">
			<input type="text" id="republish-tracking-snippet" class="republish-content__input" readonly value="" />
			<button class="plain-text-field__button" data-target="#republish-tracking-snippet">Copy Snippet</button>
		</div>
	</div>`;

const renderPage = ( { plainText } ) => {
	const textareaClass = plainText ? 'republish-content__textarea republish-content republish-content--active' : 'republish-content__textarea ';
	document.body.innerHTML = `
		<article class="republish-article">
			<div class="republish-article__content">
				<section class="republish-article__info">
					${ plainText ? tabsMarkup : '' }
					<div class="republish-content-container">
						<textarea id="republish-html-content" class="${ textareaClass }" data-tab-content="html" readonly>&lt;h1&gt;Story&lt;/h1&gt;</textarea>
						${ plainText ? plainTextMarkup : '' }
					</div>
					<button class="republish-article__copy-button republish-article__copy-button--main show-for-html">Copy to clipboard</button>
				</section>
			</div>
		</article>`;
};

// jsdom keeps one document for the whole file, so dispatching DOMContentLoaded
// would also rerun the listeners earlier cases registered. Call only the one
// this load registers.
const loadScript = () => {
	window.ClipboardUtils = {
		copyHandoutFromElement: jest.fn(),
		copyFromElement: jest.fn(),
	};
	const addEventListener = jest.spyOn( document, 'addEventListener' );
	jest.isolateModules( () => {
		require( '../assets/republish-template' );
	} );
	const [ , onReady ] = addEventListener.mock.calls.find( ( [ type ] ) => type === 'DOMContentLoaded' );
	addEventListener.mockRestore();
	onReady();
	return window.ClipboardUtils;
};

const click = selector => document.querySelector( selector ).click();
const mainButton = () => document.querySelector( '.republish-article__copy-button--main' );
const htmlTextarea = () => document.getElementById( 'republish-html-content' );

afterEach( () => {
	document.body.innerHTML = '';
	delete window.ClipboardUtils;
} );

describe( 'Republish page Copy to clipboard', () => {
	it( 'copies the HTML when the plain-text option is off', () => {
		renderPage( { plainText: false } );
		const clipboard = loadScript();

		click( '.republish-article__copy-button' );

		expect( clipboard.copyHandoutFromElement ).toHaveBeenCalledTimes( 1 );
		expect( clipboard.copyHandoutFromElement ).toHaveBeenCalledWith( htmlTextarea(), mainButton() );
	} );

	it( 'copies the HTML when the plain-text option is on', () => {
		renderPage( { plainText: true } );
		const clipboard = loadScript();

		click( '.republish-article__copy-button' );

		expect( clipboard.copyHandoutFromElement ).toHaveBeenCalledTimes( 1 );
		expect( clipboard.copyHandoutFromElement ).toHaveBeenCalledWith( htmlTextarea(), mainButton() );
	} );

	it( 'does nothing when the page has no HTML textarea', () => {
		renderPage( { plainText: false } );
		htmlTextarea().remove();
		const clipboard = loadScript();

		expect( () => click( '.republish-article__copy-button' ) ).not.toThrow();
		expect( clipboard.copyHandoutFromElement ).not.toHaveBeenCalled();
	} );

	it( 'hides the button on the Plain Text tab and still copies the HTML after switching back', () => {
		renderPage( { plainText: true } );
		const clipboard = loadScript();

		click( '[data-tab="plain-text"]' );
		expect( mainButton().classList.contains( 'show-for-html' ) ).toBe( false );

		click( '[data-tab="html"]' );
		expect( mainButton().classList.contains( 'show-for-html' ) ).toBe( true );

		click( '.republish-article__copy-button' );
		expect( clipboard.copyHandoutFromElement ).toHaveBeenCalledTimes( 1 );
		expect( clipboard.copyHandoutFromElement ).toHaveBeenCalledWith( htmlTextarea(), mainButton() );

		click( '[data-target="#republish-plain-text-content"]' );
		expect( clipboard.copyFromElement ).toHaveBeenCalledTimes( 1 );
		expect( clipboard.copyFromElement.mock.calls[ 0 ][ 0 ] ).toBe( '#republish-plain-text-content' );
	} );
} );
