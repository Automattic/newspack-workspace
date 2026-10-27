/**
 * A form submitted inside a prompt reaches GA4 as a `form_submission`
 * interaction on that prompt, so Insights can count it the way it counts
 * `seen`, `clicked` and `dismissed` (NPPD-2505).
 */
import { manageFormSubmissions } from './form-submission';
import { handleAnalytics } from './ga4';

const promptWithForm = ( id, hiddenField ) =>
	`<div id="id_${ id }" class="newspack-popup-container newspack-popup"><form><input type="hidden" name="${ hiddenField }" value="1" /><input type="email" name="npe" value="reader@example.com" /><button type="submit">Go</button></form></div>`;

const submit = form => form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );

describe( 'prompt form submissions', () => {
	beforeEach( () => {
		global.gtag = jest.fn();
		global.newspackPopupsData = {
			7: { newspack_popup_id: 7, prompt_title: 'Join us', action_type: 'registration' },
		};
	} );

	it( 'sends form_submission with the prompt params, even when the block prevents the default', () => {
		document.body.innerHTML = promptWithForm( 7, 'newspack_reader_registration' );
		const form = document.querySelector( 'form' );
		// The Reader Registration block submits over fetch and cancels the native submit.
		form.addEventListener( 'submit', ev => ev.preventDefault() );

		manageFormSubmissions( [ ...document.querySelectorAll( '.newspack-popup-container' ) ] );
		submit( form );

		expect( global.gtag ).toHaveBeenCalledTimes( 1 );
		expect( global.gtag ).toHaveBeenCalledWith(
			'event',
			'np_prompt_interaction',
			expect.objectContaining( {
				action: 'form_submission',
				newspack_popup_id: 7,
				action_type: 'registration',
			} )
		);
	} );

	it( 'keeps the prompt action_type rather than deriving one from the form', () => {
		global.newspackPopupsData = { 7: { newspack_popup_id: 7, action_type: 'undefined' } };
		document.body.innerHTML = promptWithForm( 7, 'newspack_newsletters_subscribe' );

		manageFormSubmissions( [ ...document.querySelectorAll( '.newspack-popup-container' ) ] );
		submit( document.querySelector( 'form' ) );

		expect( global.gtag.mock.calls[ 0 ][ 2 ].action_type ).toBe( 'undefined' );
	} );

	it( 'is wired into the GA4 analytics setup alongside the other prompt events', () => {
		document.body.innerHTML = promptWithForm( 7, 'newspack_reader_registration' );

		handleAnalytics( [ ...document.querySelectorAll( '.newspack-popup-container' ) ] );
		submit( document.querySelector( 'form' ) );

		expect( global.gtag ).toHaveBeenCalledWith(
			'event',
			'np_prompt_interaction',
			expect.objectContaining( { action: 'form_submission', newspack_popup_id: 7 } )
		);
	} );

	it( 'ignores forms outside the prompt', () => {
		document.body.innerHTML = `<form id="search"><input name="s" /></form>${ promptWithForm( 7, 'newspack_reader_registration' ) }`;

		manageFormSubmissions( [ ...document.querySelectorAll( '.newspack-popup-container' ) ] );
		submit( document.getElementById( 'search' ) );

		expect( global.gtag ).not.toHaveBeenCalled();
	} );
} );
