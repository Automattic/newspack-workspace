/**
 * WordPress dependencies
 */
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Turns a markup string into the text a screen reader should hear.
 *
 * For `Notice`'s `__unstableHTML` children, which core hands to `RawHTML`. The
 * reader has to be given what the browser displays: tags removed, and entities
 * resolved to the characters they stand for. `@wordpress/a11y` writes the
 * announcement with `textContent`, so an entity left encoded is read out as its
 * source (`&amp;` rather than `&`).
 *
 * @param {string} html A markup string.
 * @return {string} The displayed text, with collapsed whitespace.
 */
export const htmlToText = html =>
	decodeEntities( String( html ).replace( /<[^<>]+>/g, ' ' ) )
		.replace( /\s+/g, ' ' )
		.trim();
