/**
 * A Newspack form block submitted inside a prompt reaches GA4 as a
 * `form_submission` interaction on that prompt, so Insights can count it the
 * way it counts `seen`, `clicked` and `dismissed` (NPPD-2505).
 */
import { manageFormSubmissions } from './form-submission';
import { handleAnalytics } from './ga4';

// Wrapper classes as each block's renderer prints them; the selectors in
// form-submission.js have to match these.
const BLOCK_WRAPPERS = {
	'Reader Registration': 'newspack-registration',
	'Newsletter Subscribe': 'newspack-newsletters-subscribe',
	Donate: 'wp-block-newspack-blocks-donate',
	'Checkout Button': 'wp-block-newspack-blocks-checkout-button',
};

const blockForm = ( wrapper, fields = '' ) => `<div class="${ wrapper }"><form>${ fields }<button type="submit">Go</button></form></div>`;
const prompt = ( id, inner ) => `<div id="id_${ id }" class="newspack-popup-container newspack-popup">${ inner }</div>`;
const submit = form => form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
const prompts = () => [ ...document.querySelectorAll( '.newspack-popup-container' ) ];

describe( 'prompt form submissions', () => {
	beforeEach( () => {
		global.gtag = jest.fn();
		global.newspackPopupsData = { 7: { newspack_popup_id: 7, prompt_title: 'Join us' } };
	} );

	it.each( Object.entries( BLOCK_WRAPPERS ) )(
		'sends form_submission for a %s form inside the prompt and for nothing else on the page',
		( label, wrapper ) => {
			// A registration block in the article body, then the prompt holding the
			// block under test beside a search form.
			document.body.innerHTML = `<article>${ blockForm( BLOCK_WRAPPERS[ 'Reader Registration' ] ) }</article>${ prompt(
				7,
				`${ blockForm( wrapper ) }<form class="wp-block-search"><input name="s" /></form>`
			) }`;
			const form = document.querySelector( `#id_7 .${ wrapper } form` );
			// Registration and Newsletter Subscribe cancel the native submit to post
			// over fetch; a cancelled submit still has to count, so every row plays it.
			form.addEventListener( 'submit', ev => ev.preventDefault() );

			manageFormSubmissions( prompts() );
			submit( document.querySelector( 'article form' ) );
			submit( document.querySelector( '#id_7 .wp-block-search' ) );
			submit( form );

			expect( global.gtag ).toHaveBeenCalledTimes( 1 );
			expect( global.gtag ).toHaveBeenCalledWith(
				'event',
				'np_prompt_interaction',
				expect.objectContaining( { action: 'form_submission', newspack_popup_id: 7 } )
			);
		}
	);

	it( 'keeps the prompt action_type rather than deriving one from the form', () => {
		// 'undefined' is the literal the data API prints for a prompt holding more
		// than one watched block (Newspack_Popups_Data_Api::prepare_popup_params_for_ga).
		global.newspackPopupsData = { 7: { newspack_popup_id: 7, action_type: 'undefined' } };
		document.body.innerHTML = prompt(
			7,
			blockForm( BLOCK_WRAPPERS[ 'Newsletter Subscribe' ], '<input type="hidden" name="newspack_newsletters_subscribe" value="1" />' )
		);

		manageFormSubmissions( prompts() );
		submit( document.querySelector( '#id_7 form' ) );

		expect( global.gtag.mock.calls[ 0 ][ 2 ].action_type ).toBe( 'undefined' );
	} );

	it( 'is wired into the GA4 analytics setup alongside the other prompt events', () => {
		document.body.innerHTML = prompt( 7, blockForm( BLOCK_WRAPPERS[ 'Reader Registration' ] ) );

		handleAnalytics( prompts() );
		submit( document.querySelector( '#id_7 form' ) );

		expect( global.gtag ).toHaveBeenCalledWith(
			'event',
			'np_prompt_interaction',
			expect.objectContaining( { action: 'form_submission', newspack_popup_id: 7 } )
		);
	} );
} );
