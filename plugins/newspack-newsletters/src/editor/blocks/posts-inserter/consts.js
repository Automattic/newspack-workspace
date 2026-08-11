export const POSTS_INSERTER_BLOCK_NAME = 'newspack-newsletters/posts-inserter';
export const POSTS_INSERTER_STORE_NAME = 'newspack-newsletters/posts-inserter-block';

/**
 * Post statuses a hand-picked post may have.
 *
 * Used both by the "Add posts" search and by the lookup of posts already saved on the
 * block. Private posts are deliberately left out.
 */
export const SEARCHABLE_STATUSES = [ 'publish', 'future', 'draft', 'pending' ];
