/**
 * Internal dependencies
 */
import type { Author } from './single-author';

/**
 * Build the author value that Author Profile passes to its inner blocks in the editor.
 *
 * Like the front end, it adds whether default avatars are hidden, so the avatar block can leave
 * one out. Returns a new object, since author records are shared between blocks on the page.
 *
 * The Site Editor placeholder author has no avatar of its own. It stands in for every author, and
 * some of them have a photo, so it is returned unchanged and keeps the avatar block's default
 * avatar preview.
 *
 * @param author            Author record from the authors endpoint, or the placeholder author.
 * @param avatarHideDefault Whether the block hides default avatars.
 * @return The author for inner blocks.
 */
export function getAuthorContextValue( author: Author | null, avatarHideDefault: unknown ): Author | null {
	if ( ! author || author.id === 'placeholder' ) {
		return author;
	}
	return { ...author, avatarHideDefault: !! avatarHideDefault };
}
