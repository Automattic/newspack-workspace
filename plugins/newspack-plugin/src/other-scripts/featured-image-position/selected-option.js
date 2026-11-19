/**
 * Which of the control's options a stored position corresponds to.
 *
 * The control offers three choices — follow the site default, shown, hidden —
 * but the meta can hold any of the classic theme's six layout positions, since
 * the same value drives the classic theme and a site can switch back. Anything
 * that is not `hidden` and not empty means the image shows, so it reads as
 * "Shown" and keeps whatever value it already has unless the publisher picks
 * something else.
 *
 * @param {string} position    The stored position.
 * @param {string} shownValue  The value the "Shown" option writes.
 * @param {string} hiddenValue The value that hides the image.
 * @return {string} The option to select.
 */
export const getSelectedOption = ( position, shownValue, hiddenValue ) => {
	if ( ! position ) {
		return '';
	}

	return hiddenValue === position ? hiddenValue : shownValue;
};

/**
 * The value the "Shown" option writes.
 *
 * Never a layout the publisher did not choose. A post that already holds one of
 * classic's shown positions keeps it, so clicking "Shown" on such a post is a
 * no-op rather than a silent rewrite to `large`. Otherwise it pins whatever the
 * site default already puts in force, and only falls back to the type's default
 * when the site itself hides images and there is nothing else to go on.
 *
 * This matters because nothing on a block theme renders the five layout
 * positions differently, so a publisher gets no feedback from overwriting one —
 * the damage only surfaces if the site ever returns to the classic theme.
 *
 * It also refuses a candidate outside the known vocabulary. Nothing sanitizes
 * these values on the way in while a classic theme is active — that theme
 * registers the meta with no sanitize callback at all — so a post or a theme mod
 * can hold junk. Writing that back would fail the REST enum and take the whole
 * post save with it, not just this field.
 *
 * @param {string}   position      The stored position.
 * @param {string}   siteDefault   What the site-wide default resolves to.
 * @param {string}   hiddenValue   The value that hides the image.
 * @param {string}   fallbackValue Last resort when everything else is hidden.
 * @param {string[]} positions     The positions the themes understand. An absent
 *                                 or malformed list clamps everything, which
 *                                 fails toward the site default.
 * @return {string} The value to write.
 */
export const getShownValue = ( position, siteDefault, hiddenValue, fallbackValue, positions ) => {
	const vocabulary = Array.isArray( positions ) ? positions : [];
	const usable = candidate => candidate && hiddenValue !== candidate && vocabulary.includes( candidate );

	if ( usable( position ) ) {
		return position;
	}

	if ( usable( siteDefault ) ) {
		return siteDefault;
	}

	/*
	 * The fallback is checked too. It comes from PHP as a literal today, but
	 * `siteDefault` was a literal once as well and became a theme mod, which is
	 * how the clamp came to be needed. '' is the safe tail: it means "follow the
	 * site default" rather than naming a position nobody chose.
	 */
	return usable( fallbackValue ) ? fallbackValue : '';
};
