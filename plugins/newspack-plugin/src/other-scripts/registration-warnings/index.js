/**
 * Keeps the "Anyone can register" warnings on Settings → General in step with
 * the form, so a publisher sees the risk before saving, not after.
 *
 * PHP prints both warnings and decides which roles are risky and whether
 * reCAPTCHA is on; this script only places the warnings under their settings
 * and shows or hides them as the checkbox and the role dropdown change.
 *
 * It reads out changes through the `wp.a11y` global instead of importing
 * `@wordpress/a11y`: with the import, the built script waited on Newspack's
 * commons chunk, which isn't loaded on this core screen, so it never ran.
 */

function init() {
	const data = window.newspackRegistrationWarnings;
	const checkbox = document.getElementById( 'users_can_register' );
	const roleSelect = document.getElementById( 'default_role' );
	const botNotice = document.querySelector( '[data-newspack-registration-warning="bot"]' );
	const roleNotice = document.querySelector( '[data-newspack-registration-warning="role"]' );
	if ( ! data || ! checkbox || ! roleSelect || ! botNotice || ! roleNotice ) {
		return;
	}

	checkbox.closest( 'td' )?.appendChild( botNotice );
	roleSelect.closest( 'td' )?.appendChild( roleNotice );

	const roleTitle = roleNotice.querySelector( 'strong' );
	const roleBody = roleNotice.querySelectorAll( 'p' )[ 1 ];
	const riskyRoles = data.riskyRoles || {};

	/**
	 * The warning for a role, looked up only among the roles PHP listed, so a
	 * slug such as "constructor" can't match a built-in object property.
	 *
	 * @param {string} role Role slug.
	 * @return {Object|undefined} The role's title and body, if it can edit posts.
	 */
	function getRoleWarning( role ) {
		return Object.prototype.hasOwnProperty.call( riskyRoles, role ) ? riskyRoles[ role ] : undefined;
	}

	/**
	 * Show or hide a warning, and return what to read out: the warning's text
	 * when it appears, or changes while shown, otherwise nothing.
	 *
	 * @param {HTMLElement} notice  The warning.
	 * @param {boolean}     visible Whether it should show.
	 * @param {boolean}     changed Whether its text just changed.
	 * @return {string} Text to read out, or an empty string.
	 */
	function setVisible( notice, visible, changed ) {
		const appeared = visible && notice.hidden;
		notice.hidden = ! visible;
		if ( ! visible || ! ( appeared || changed ) ) {
			return '';
		}
		// The title has no closing punctuation; a period keeps it a sentence when read aloud.
		return Array.from( notice.querySelectorAll( 'p' ), paragraph => paragraph.textContent.trim() )
			.map( text => ( /[.!?]$/.test( text ) ? text : `${ text }.` ) )
			.join( ' ' );
	}

	/**
	 * Bring both warnings in line with the form. Changes caused by the
	 * person's own edit are read out in one message, since each speak() call
	 * replaces the last; a page load reads out nothing.
	 *
	 * @param {boolean} isEdit Whether this update comes from an edit.
	 */
	function update( isEdit ) {
		const isOpen = checkbox.checked;
		const botMessage = setVisible( botNotice, isOpen && ! data.hasBotProtection, false );

		const roleWarning = isOpen ? getRoleWarning( roleSelect.value ) : undefined;
		let changed = false;
		if ( roleWarning && roleTitle && roleBody && roleTitle.textContent !== roleWarning.title ) {
			roleTitle.textContent = roleWarning.title;
			roleBody.textContent = roleWarning.body;
			changed = true;
		}
		const roleMessage = setVisible( roleNotice, Boolean( roleWarning ), changed );

		const message = [ botMessage, roleMessage ].filter( Boolean ).join( ' ' );
		if ( isEdit && message ) {
			window.wp?.a11y?.speak( message );
		}
	}

	update( false );
	checkbox.addEventListener( 'change', () => update( true ) );
	roleSelect.addEventListener( 'change', () => update( true ) );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
