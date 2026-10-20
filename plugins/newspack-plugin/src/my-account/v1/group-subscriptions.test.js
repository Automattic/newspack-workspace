/**
 * Renaming a group runs inside a newspack-ui modal, alongside the modal script's own
 * submit handling. A save has to send exactly one request however often it's
 * pressed, and release the button afterwards.
 */

/**
 * Render the group page's rename modal and load both scripts in page order.
 */
function renderRenameModal() {
	document.body.innerHTML = `
		<div class="newspack-my-account__group_subscription__content" data-subscription-id="12"></div>
		<h2 data-group-name>Newsroom</h2>
		<div id="newspack-my-account__group_subscription--rename" class="newspack-ui__modal-container" data-state="open">
			<div class="newspack-ui__modal">
				<form class="newspack-ui__modal__content newspack-my-account__group--rename-form" data-subscription-id="12" data-error-text="Could not rename.">
					<input type="text" class="newspack-my-account__group--rename-input" name="group_name" value="Newsroom">
					<button type="submit" class="newspack-ui__button newspack-my-account__group--rename-save"><span>Save</span></button>
				</form>
			</div>
		</div>
	`;
	jest.isolateModules( () => {
		require( '../../newspack-ui/js/modals' );
		require( './group-subscriptions' );
	} );
	return {
		form: document.querySelector( '.newspack-my-account__group--rename-form' ),
		button: document.querySelector( '.newspack-my-account__group--rename-save' ),
	};
}

let resolveFetch;

/**
 * Wait until a condition holds, polling between tasks.
 *
 * @param {Function} condition Returns true once the wait is over.
 */
async function waitFor( condition ) {
	const deadline = Date.now() + 2000;
	while ( ! condition() && Date.now() < deadline ) {
		await new Promise( resolve => setTimeout( resolve, 20 ) );
	}
}

beforeEach( () => {
	global.newspackMyAccountV1 = {
		rest: { base_url: 'https://example.test/wp-json/', namespaces: { group: 'newspack/v1/group' }, nonce: 'nonce-123' },
		labels: {},
	};
	global.newspackUI = { notices: { createNotice: jest.fn() } };
	global.fetch = jest.fn(
		() =>
			new Promise( resolve => {
				resolveFetch = () => resolve( { ok: true, json: () => Promise.resolve( { name: 'Newsroom' } ) } );
			} )
	);
} );

afterEach( () => {
	delete global.fetch;
	delete global.newspackMyAccountV1;
	delete global.newspackUI;
	document.body.innerHTML = '';
} );

describe( 'group rename', () => {
	it( 'sends one request while saving, and releases the button when done', async () => {
		const { form, button } = renderRenameModal();

		form.requestSubmit( button );
		form.requestSubmit( button );

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		expect( global.fetch.mock.calls[ 0 ][ 0 ] ).toBe( 'https://example.test/wp-json/newspack/v1/group/name' );
		expect( button.getAttribute( 'aria-busy' ) ).toBe( 'true' );

		resolveFetch();
		await waitFor( () => ! button.hasAttribute( 'aria-busy' ) );

		expect( button.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( button.hasAttribute( 'aria-disabled' ) ).toBe( false );
	} );
} );
