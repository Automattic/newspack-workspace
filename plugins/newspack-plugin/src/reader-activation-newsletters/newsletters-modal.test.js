/**
 * Escape is a way to close the newsletters modal, so it runs the same teardown and
 * onDismiss as the modal's own close buttons, once per open. It relies on the modal
 * script handling Escape first, so both run here.
 */

import { openNewslettersSignupModal } from './newsletters-modal';

const nextTask = () => new Promise( resolve => setTimeout( resolve, 0 ) );

const nativeDialog = {};

beforeAll( () => {
	[ 'showModal', 'show', 'close' ].forEach( method => {
		nativeDialog[ method ] = HTMLDialogElement.prototype[ method ];
	} );
	HTMLDialogElement.prototype.showModal = function () {
		this.setAttribute( 'open', '' );
	};
	HTMLDialogElement.prototype.show = HTMLDialogElement.prototype.showModal;
	HTMLDialogElement.prototype.close = function () {
		this.removeAttribute( 'open' );
	};
} );

afterAll( () => {
	Object.assign( HTMLDialogElement.prototype, nativeDialog );
} );

afterEach( () => {
	document.body.innerHTML = '';
} );

/**
 * Render the newsletters signup modal and load the modal script.
 *
 * @param {string} content Extra markup inside the form.
 */
function renderModal( content = '' ) {
	document.body.innerHTML = `
		<dialog class="newspack-ui newspack-ui__modal-container newspack-newsletters-signup-modal" data-state="closed">
			<header class="newspack-ui__modal__header"><h2>Newsletters</h2><button class="newspack-ui__modal__close">Close</button></header>
			<div class="newspack-newsletters-signup"><form>${ content }</form></div>
		</dialog>
	`;
	jest.isolateModules( () => {
		require( '../newspack-ui/js/modals' );
	} );
	return document.querySelector( '.newspack-newsletters-signup-modal' );
}

/**
 * Open the modal and let the modal script open its dialog.
 *
 * @param {Object} config Modal config.
 */
async function open( config ) {
	openNewslettersSignupModal( config );
	await nextTask();
}

const pressEscape = modal => modal.dispatchEvent( new Event( 'cancel', { cancelable: true } ) );

describe( 'newsletters signup modal', () => {
	it( 'dismisses on Escape', async () => {
		const modal = renderModal();
		const onDismiss = jest.fn();
		await open( { onDismiss } );

		pressEscape( modal );

		expect( onDismiss ).toHaveBeenCalledTimes( 1 );
		expect( modal.dataset.state ).toBe( 'closed' );
	} );

	it( 'stays open on Escape while a signup is in flight', async () => {
		const modal = renderModal( '<button type="submit" aria-busy="true">Sign up</button>' );
		const onDismiss = jest.fn();
		await open( { onDismiss } );

		pressEscape( modal );

		expect( onDismiss ).not.toHaveBeenCalled();
		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'dismisses once after being opened more than once', async () => {
		const modal = renderModal();
		const onDismiss = jest.fn();
		await open( { onDismiss } );
		await open( { onDismiss } );

		modal.querySelector( '.newspack-ui__modal__close' ).click();

		expect( onDismiss ).toHaveBeenCalledTimes( 1 );
	} );
} );
