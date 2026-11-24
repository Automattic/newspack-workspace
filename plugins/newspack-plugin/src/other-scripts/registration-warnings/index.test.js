// The script calls the wp.a11y global its `wp-a11y` dependency provides.
const speak = jest.fn();
window.wp = { a11y: { speak } };

const RISKY_ROLES = {
	editor: {
		title: 'Anyone can sign up as an Editor',
		body: 'Editors can publish and change any post.',
	},
	administrator: {
		title: 'Anyone can sign up as an Administrator',
		body: 'Administrators can change any setting, add users and install plugins.',
	},
};

/**
 * Render General Settings the way WordPress does, with the two notices
 * printed above the form as PHP leaves them.
 *
 * @param {Object}  options
 * @param {boolean} options.open      Whether "Anyone can register" starts checked.
 * @param {string}  options.role      The selected New User Default Role.
 * @param {boolean} options.botShown  Whether PHP printed the amber notice visible.
 * @param {boolean} options.roleShown Whether PHP printed the red notice visible.
 */
function renderSettings( { open = false, role = 'subscriber', botShown = false, roleShown = false } = {} ) {
	const roleWarning = roleShown ? RISKY_ROLES[ role ] : { title: '', body: '' };
	document.body.innerHTML = `
		<div class="notice notice-warning inline notice-alt" data-newspack-registration-warning="bot"${ botShown ? '' : ' hidden' }>
			<p><strong>Sign-ups have no bot protection</strong></p><p>reCAPTCHA is off.</p>
		</div>
		<div class="notice notice-error inline notice-alt" data-newspack-registration-warning="role"${ roleShown ? '' : ' hidden' }>
			<p><strong>${ roleWarning.title }</strong></p><p>${ roleWarning.body }</p>
		</div>
		<table class="form-table"><tbody>
			<tr><th>Membership</th><td id="membership-cell"><fieldset><label>
				<input type="checkbox" id="users_can_register"${ open ? ' checked' : '' }> Anyone can register
			</label></fieldset></td></tr>
			<tr><th>New User Default Role</th><td id="role-cell"><select id="default_role">
				<option value="subscriber">Subscriber</option>
				<option value="editor">Editor</option>
				<option value="administrator">Administrator</option>
			</select></td></tr>
		</tbody></table>`;
	document.getElementById( 'default_role' ).value = role;
}

function loadScript( data = { hasBotProtection: false, riskyRoles: RISKY_ROLES } ) {
	window.newspackRegistrationWarnings = data;
	jest.isolateModules( () => {
		require( './index' );
	} );
}

const botNotice = () => document.querySelector( '[data-newspack-registration-warning="bot"]' );
const roleNotice = () => document.querySelector( '[data-newspack-registration-warning="role"]' );
const checkbox = () => document.getElementById( 'users_can_register' );
const select = () => document.getElementById( 'default_role' );

function setOpen( open ) {
	checkbox().checked = open;
	checkbox().dispatchEvent( new Event( 'change', { bubbles: true } ) );
}

function setRole( role ) {
	select().value = role;
	select().dispatchEvent( new Event( 'change', { bubbles: true } ) );
}

describe( 'registration warnings', () => {
	afterEach( () => {
		delete window.newspackRegistrationWarnings;
		document.body.innerHTML = '';
		speak.mockClear();
	} );

	it( 'moves each notice under its setting', () => {
		renderSettings();
		loadScript();
		expect( document.getElementById( 'membership-cell' ).lastElementChild ).toBe( botNotice() );
		expect( document.getElementById( 'role-cell' ).lastElementChild ).toBe( roleNotice() );
	} );

	it( 'reflects the saved state on load without announcing', () => {
		renderSettings( { open: true, role: 'editor', botShown: true, roleShown: true } );
		loadScript();
		expect( botNotice().hidden ).toBe( false );
		expect( roleNotice().hidden ).toBe( false );
		expect( roleNotice().querySelector( 'strong' ).textContent ).toBe( RISKY_ROLES.editor.title );
		expect( speak ).not.toHaveBeenCalled();
	} );

	it( 'follows a form state the browser restored on reload, without announcing', () => {
		// Saved settings are safe, so PHP printed both hidden, but the browser
		// restored an unsaved check and role from before the reload.
		renderSettings( { open: true, role: 'editor', botShown: false, roleShown: false } );
		loadScript();
		expect( botNotice().hidden ).toBe( false );
		expect( roleNotice().hidden ).toBe( false );
		expect( speak ).not.toHaveBeenCalled();
	} );

	it( 'shows both warnings when the box is checked live', () => {
		renderSettings( { open: false, role: 'editor' } );
		loadScript();
		expect( botNotice().hidden ).toBe( true );
		expect( roleNotice().hidden ).toBe( true );

		setOpen( true );
		expect( botNotice().hidden ).toBe( false );
		expect( roleNotice().hidden ).toBe( false );
		expect( roleNotice().querySelector( 'strong' ).textContent ).toBe( RISKY_ROLES.editor.title );
		// wp.a11y.speak() clears the live region before each write, so both
		// warnings go out in one call or only the last is heard.
		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect( speak.mock.calls[ 0 ][ 0 ] ).toContain( 'Sign-ups have no bot protection' );
		expect( speak.mock.calls[ 0 ][ 0 ] ).toContain( RISKY_ROLES.editor.title );
	} );

	it( 'swaps the role text when the role changes', () => {
		renderSettings( { open: true, role: 'editor', botShown: true, roleShown: true } );
		loadScript();
		setRole( 'administrator' );
		expect( roleNotice().hidden ).toBe( false );
		expect( roleNotice().querySelector( 'strong' ).textContent ).toBe( RISKY_ROLES.administrator.title );
		expect( roleNotice().querySelectorAll( 'p' )[ 1 ].textContent ).toBe( RISKY_ROLES.administrator.body );
		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect( speak ).toHaveBeenCalledWith( `${ RISKY_ROLES.administrator.title } ${ RISKY_ROLES.administrator.body }` );
	} );

	it( 'hides the role warning for a role that cannot edit posts', () => {
		renderSettings( { open: true, role: 'editor', botShown: true, roleShown: true } );
		loadScript();
		setRole( 'subscriber' );
		expect( roleNotice().hidden ).toBe( true );
		expect( botNotice().hidden ).toBe( false );
	} );

	it( 'hides both warnings when the box is unchecked', () => {
		renderSettings( { open: true, role: 'editor', botShown: true, roleShown: true } );
		loadScript();
		setOpen( false );
		expect( botNotice().hidden ).toBe( true );
		expect( roleNotice().hidden ).toBe( true );
		expect( speak ).not.toHaveBeenCalled();
	} );

	it( 'keeps the bot warning hidden when reCAPTCHA is on', () => {
		renderSettings( { open: false, role: 'subscriber' } );
		loadScript( { hasBotProtection: true, riskyRoles: RISKY_ROLES } );
		setOpen( true );
		expect( botNotice().hidden ).toBe( true );
		expect( speak ).not.toHaveBeenCalled();
	} );

	it( 'writes role text as text, not markup', () => {
		renderSettings( { open: true, role: 'subscriber' } );
		loadScript( {
			hasBotProtection: true,
			riskyRoles: { editor: { title: '<img src=x onerror=alert(1)>', body: '<b>x</b>' } },
		} );
		setRole( 'editor' );
		expect( roleNotice().querySelector( 'img' ) ).toBeNull();
		expect( roleNotice().querySelector( 'strong' ).textContent ).toBe( '<img src=x onerror=alert(1)>' );
	} );

	it( 'leaves the notices alone without data', () => {
		renderSettings( { open: true, role: 'editor', botShown: true, roleShown: true } );
		window.newspackRegistrationWarnings = undefined;
		expect( () =>
			jest.isolateModules( () => {
				require( './index' );
			} )
		).not.toThrow();
		expect( botNotice().parentElement ).toBe( document.body );
		expect( botNotice().hidden ).toBe( false );
	} );
} );
