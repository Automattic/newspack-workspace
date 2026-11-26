/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';

/**
 * Is this the post type the editor page was opened for?
 *
 * "Edit original" on a synced pattern swaps the pattern into the same editor
 * page. `core/editor` then reports `wp_block`, but every plugin on the page
 * keeps rendering. WP sets the `post-type-{cpt}` body class for the post the
 * page was opened for, and the swap leaves it in place, so the two only match
 * while that post is the one being edited.
 *
 * @param {?string} postType The current post type, from `core/editor`.
 * @return {boolean} True if the page was opened for this post type.
 */
export const isOpenedPostType = postType =>
	!! postType && typeof document !== 'undefined' && !! document.body?.classList?.contains( `post-type-${ postType }` );

/**
 * Custom hook: is the editor showing the post the page was opened for?
 *
 * See `isOpenedPostType`. False while a synced pattern is open through "Edit original".
 *
 * @return {boolean} True while the opened post is the one being edited.
 */
export const useIsEditingOpenedPost = () =>
	useSelect( registrySelect => isOpenedPostType( registrySelect( 'core/editor' ).getCurrentPostType() ), [] );
