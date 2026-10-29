/**
 * A modal with steps shows one step at a time: a request whose action succeeds
 * turns into its result in the same dialog, with focus on the result's title,
 * and the modal opens on its first step again next time.
 */

const nextTask = () => new Promise( resolve => setTimeout( resolve, 0 ) );

const nativeDialog = {
	showModal: HTMLDialogElement.prototype.showModal,
	show: HTMLDialogElement.prototype.show,
	close: HTMLDialogElement.prototype.close,
};

beforeAll( () => {
	HTMLDialogElement.prototype.showModal = function () {
		this.setAttribute( 'open', '' );
	};
	HTMLDialogElement.prototype.show = HTMLDialogElement.prototype.showModal;
	HTMLDialogElement.prototype.close = function () {
		this.removeAttribute( 'open' );
	};
	// jsdom has no layout, so only inert and hidden steps count as invisible.
	Element.prototype.checkVisibility = function () {
		return ! this.closest( '[inert], [hidden]' );
	};
} );

afterAll( () => {
	Object.assign( HTMLDialogElement.prototype, nativeDialog );
	delete Element.prototype.checkVisibility;
} );

afterEach( () => {
	jest.restoreAllMocks();
	delete global.fetch;
	document.body.innerHTML = '';
} );

/**
 * Render a two-step modal like the delete account request, and load the modal script.
 *
 * @return {HTMLElement} The modal.
 */
function renderStepsModal() {
	const fetchData = JSON.stringify( { url: 'https://example.test/wp-json/delete', method: 'POST', nonce: 'abc', next: 'email-sent' } );
	document.body.innerHTML = `
		<dialog id="newspack-my-account__delete" class="newspack-ui__modal-container" data-state="closed" data-step="request">
			<div class="newspack-ui__modal newspack-ui__modal--steps">
				<div class="newspack-ui__modal__steps">
					<div class="newspack-ui__modal__step" data-step="request">
						<header class="newspack-ui__modal__header"><h2>Delete account</h2><button class="newspack-ui__modal__close">Close</button></header>
						<section class="newspack-ui__modal__content">
							<button type="submit" id="confirm" data-fetch='${ fetchData }'><span>Delete account</span></button>
						</section>
					</div>
					<div class="newspack-ui__modal__step" data-step="email-sent" inert>
						<header class="newspack-ui__modal__header"><h2>Delete account</h2><button class="newspack-ui__modal__close">Close</button></header>
						<section class="newspack-ui__modal__content">
							<button type="submit" id="continue" class="newspack-ui__modal__close"><span>Continue</span></button>
							<button type="submit" id="resend" data-fetch='${ fetchData }'><span>Resend</span></button>
						</section>
					</div>
				</div>
			</div>
		</dialog>
	`;
	jest.isolateModules( () => {
		require( './modals' );
	} );
	return document.getElementById( 'newspack-my-account__delete' );
}

/**
 * Open the modal and confirm its request, which the server accepts.
 *
 * @param {HTMLElement} modal The modal.
 */
async function confirmRequest( modal ) {
	global.fetch = jest.fn( () => Promise.resolve( { ok: true, json: () => Promise.resolve( { success: true } ) } ) );
	modal.setAttribute( 'data-state', 'open' );
	await nextTask();
	modal.querySelector( '#confirm' ).click();
	await nextTask();
	await nextTask();
}

describe( 'newspack-ui modal steps', () => {
	it( 'shows the next step in the same open dialog when the request succeeds', async () => {
		const modal = renderStepsModal();
		await confirmRequest( modal );

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		expect( modal.dataset.state ).toBe( 'open' );
		expect( modal.dataset.step ).toBe( 'email-sent' );
		expect( modal.querySelector( '[data-step="request"]' ).hasAttribute( 'inert' ) ).toBe( true );
		expect( modal.querySelector( '[data-step="email-sent"]' ).hasAttribute( 'inert' ) ).toBe( false );
	} );

	it( 'opens on the first step’s title, not its action', async () => {
		const modal = renderStepsModal();
		modal.setAttribute( 'data-state', 'open' );
		await nextTask();

		expect( document.activeElement ).toBe( modal.querySelector( '[data-step="request"] h2' ) );
	} );

	it( 'moves focus to the next step’s title, not an action or its Close button', async () => {
		const modal = renderStepsModal();
		await confirmRequest( modal );

		const title = modal.querySelector( '.newspack-ui__modal__step[data-step="email-sent"] h2' );
		expect( document.activeElement ).toBe( title );
		expect( title.getAttribute( 'tabindex' ) ).toBe( '-1' );
		expect( modal.getAttribute( 'aria-labelledby' ) ).toBe( title.id );
	} );

	it( 'moves focus to the next step’s first field', async () => {
		const modal = renderStepsModal();
		modal
			.querySelector( '[data-step="email-sent"] .newspack-ui__modal__content' )
			.insertAdjacentHTML( 'afterbegin', '<input type="text" id="code" />' );
		await confirmRequest( modal );

		expect( document.activeElement.id ).toBe( 'code' );
	} );

	it( 'moves focus to the next step’s first heading when the step has no title', async () => {
		const modal = renderStepsModal();
		const step = modal.querySelector( '[data-step="email-sent"]' );
		step.querySelector( 'header' ).remove();
		step.querySelector( '.newspack-ui__modal__content' ).insertAdjacentHTML( 'afterbegin', '<h3 id="sent">Check your email</h3>' );
		await confirmRequest( modal );

		expect( document.activeElement.id ).toBe( 'sent' );
	} );

	it( 'moves focus to the dialog when the next step has no title or heading', async () => {
		const modal = renderStepsModal();
		modal.querySelector( '[data-step="email-sent"] header' ).remove();
		await confirmRequest( modal );

		expect( document.activeElement ).toBe( modal );
	} );

	it( 'focuses an action marked data-autofocus in the next step', async () => {
		const modal = renderStepsModal();
		modal.querySelector( '#resend' ).setAttribute( 'data-autofocus', '' );
		await confirmRequest( modal );

		expect( document.activeElement.id ).toBe( 'resend' );
	} );

	it( 'opens on its first step again after it closes', async () => {
		const modal = renderStepsModal();
		await confirmRequest( modal );

		modal.querySelector( '#continue' ).click();
		await nextTask();
		expect( modal.dataset.state ).toBe( 'closed' );

		modal.setAttribute( 'data-state', 'open' );
		await nextTask();
		expect( modal.dataset.step ).toBe( 'request' );
		expect( modal.querySelector( '[data-step="email-sent"]' ).hasAttribute( 'inert' ) ).toBe( true );
		expect( document.activeElement ).toBe( modal.querySelector( '[data-step="request"] h2' ) );
	} );

	it( 'names and describes the dialog with the step it shows', async () => {
		const modal = renderStepsModal();
		await confirmRequest( modal );

		const step = modal.querySelector( '[data-step="email-sent"]' );
		expect( modal.getAttribute( 'aria-labelledby' ) ).toBe( step.querySelector( 'h2' ).id );
		expect( modal.getAttribute( 'aria-describedby' ) ).toBe( step.querySelector( '.newspack-ui__modal__content' ).id );

		modal.querySelector( '#continue' ).click();
		await nextTask();
		modal.setAttribute( 'data-state', 'open' );
		await nextTask();
		expect( modal.getAttribute( 'aria-labelledby' ) ).toBe( modal.querySelector( '[data-step="request"] h2' ).id );
		expect( modal.hasAttribute( 'aria-describedby' ) ).toBe( false );
	} );

	it( 'names the dialog with, and focuses, the message of a success step with no title bar', async () => {
		const modal = renderStepsModal();
		const step = modal.querySelector( '[data-step="email-sent"]' );
		step.querySelector( 'header' ).hidden = true;
		step.querySelector( '.newspack-ui__modal__content' ).insertAdjacentHTML( 'afterbegin', '<h2 id="requested">Requested</h2>' );
		await confirmRequest( modal );

		expect( document.activeElement.id ).toBe( 'requested' );
		expect( modal.getAttribute( 'aria-labelledby' ) ).toBe( 'requested' );
	} );

	it( 'shows an error in the step whose action failed', async () => {
		const modal = renderStepsModal();
		await confirmRequest( modal );
		global.fetch = jest.fn( () => Promise.resolve( { ok: false, json: () => Promise.resolve( { message: 'Nope.' } ) } ) );

		modal.querySelector( '#resend' ).click();
		await nextTask();
		await nextTask();

		expect( modal.querySelector( '[data-step="email-sent"] [role="alert"]' ).textContent ).toContain( 'Nope.' );
		expect( modal.querySelector( '[data-step="request"] [role="alert"]' ) ).toBeNull();
	} );

	it( 'stays on the request when the server refuses it', async () => {
		const modal = renderStepsModal();
		global.fetch = jest.fn( () => Promise.resolve( { ok: false, json: () => Promise.resolve( { message: 'Nope.' } ) } ) );
		modal.setAttribute( 'data-state', 'open' );
		await nextTask();
		modal.querySelector( '#confirm' ).click();
		await nextTask();
		await nextTask();

		expect( modal.dataset.step ).toBe( 'request' );
		expect( modal.querySelector( '[role="alert"]' ).textContent ).toContain( 'Nope.' );
	} );
} );
