/**
 * A form submitted inside a prompt reaches GA4 as a `form_submission`
 * interaction on that prompt, so Insights can count it the way it counts
 * `seen`, `clicked` and `dismissed` (NPPD-2505).
 */
import { manageFormSubmissions } from './form-submission';
import { handleAnalytics } from './ga4';

const prompt = ( id, inner ) => `<div id="id_${ id }" class="newspack-popup-container newspack-popup">${ inner }</div>`;
const registrationForm = ( fields = '' ) => `<div class="newspack-registration"><form>${ fields }<button type="submit">Go</button></form></div>`;
const newsletterForm = ( fields = '' ) =>
	`<div class="newspack-newsletters-subscribe"><form>${ fields }<button type="submit">Go</button></form></div>`;

const submit = form => form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
const prompts = () => [ ...document.querySelectorAll( '.newspack-popup-container' ) ];

describe( 'prompt form submissions', () => {
	beforeEach( () => {
		global.gtag = jest.fn();
		global.newspackPopupsData = {
			7: { newspack_popup_id: 7, prompt_title: 'Join us', action_type: 'registration' },
		};
	} );

	it( 'sends form_submission with the prompt params, only for the form inside the prompt, even when the block prevents the default', () => {
		document.body.innerHTML = `<form id="search"><input name="s" /></form>${ prompt( 7, registrationForm() ) }`;
		const form = document.querySelector( '#id_7 form' );
		// The Reader Registration block submits over fetch and cancels the native submit.
		form.addEventListener( 'submit', ev => ev.preventDefault() );

		manageFormSubmissions( prompts() );
		submit( document.getElementById( 'search' ) );
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
		// 'undefined' is the literal the data API prints for a prompt holding more
		// than one watched block (Newspack_Popups_Data_Api::prepare_popup_params_for_ga).
		global.newspackPopupsData = { 7: { newspack_popup_id: 7, action_type: 'undefined' } };
		document.body.innerHTML = prompt( 7, newsletterForm( '<input type="hidden" name="newspack_newsletters_subscribe" value="1" />' ) );

		manageFormSubmissions( prompts() );
		submit( document.querySelector( '#id_7 form' ) );

		expect( global.gtag.mock.calls[ 0 ][ 2 ].action_type ).toBe( 'undefined' );
	} );

	it( 'ignores a form from a block that is not one of the four Newspack form blocks', () => {
		document.body.innerHTML = prompt( 7, '<form class="wp-block-search"><input name="s" /><button type="submit">Search</button></form>' );

		manageFormSubmissions( prompts() );
		submit( document.querySelector( '#id_7 form' ) );

		expect( global.gtag ).not.toHaveBeenCalled();
	} );

	it( 'is wired into the GA4 analytics setup alongside the other prompt events', () => {
		document.body.innerHTML = prompt( 7, registrationForm() );

		handleAnalytics( prompts() );
		submit( document.querySelector( '#id_7 form' ) );

		expect( global.gtag ).toHaveBeenCalledWith(
			'event',
			'np_prompt_interaction',
			expect.objectContaining( { action: 'form_submission', newspack_popup_id: 7 } )
		);
	} );
} );
