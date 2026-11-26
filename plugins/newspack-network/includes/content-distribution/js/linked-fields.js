/**
 * Locks the sidebar fields a linked post takes from its origin: excerpt, discussion and
 * featured image. They stay visible, but can't be opened by mouse or keyboard.
 *
 * WordPress 7.2 loads this as a script module of the post fields (see
 * Editor::lock_linked_fields()). It replaces the stylesheet overlay, which can't target
 * 7.2's sidebar markup and never stopped the keyboard.
 *
 * The lock follows the post's unlinked state, which the incoming-post script refreshes in
 * the editor on unlink, so the fields unlock without a reload.
 *
 * This file is served as is, not built, so it uses the editor's globals.
 */

const { createElement } = window.wp.element;
const { useSelect } = window.wp.data;

const isIncomingPost = () => !! window.newspack_network_incoming_post;

const isLinked = item => {
	if ( ! isIncomingPost() ) {
		return false;
	}
	const unlinked = item?.newspack_network_unlinked;
	if ( unlinked === undefined ) {
		return ! window.newspack_network_incoming_post.unlinked;
	}
	return ! unlinked;
};

// Core's view of the field reads the image from `_embedded`, which the editor's copy of the
// post doesn't have, so it would show an empty box. Look the attachment up instead.
function FeaturedImagePreview( { item } ) {
	const id = item?.featured_media;
	const media = useSelect( select => ( id ? select( 'core' ).getEntityRecord( 'postType', 'attachment', id ) : null ), [ id ] );
	const url = media?.media_details?.sizes?.large?.source_url ?? media?.source_url;
	if ( ! url ) {
		return null;
	}
	return createElement( 'img', {
		src: url,
		alt: '',
		style: { display: 'block', width: '100%', height: 'auto', borderRadius: '4px' },
	} );
}

// The featured image renders its picker inline and ignores `isDisabled`, so the field swaps
// core's editor for the preview while the post is linked. Core's editor isn't a public
// export; if it can't be loaded, a post that is linked when the editor opens gets a read-only
// preview, which only unlocks after a reload.
let CoreFeaturedImageEdit = null;
try {
	CoreFeaturedImageEdit = ( await import( '@wordpress/core-fields/post_type_supports' ) ).default?.featured_media?.Edit ?? null;
} catch {
	CoreFeaturedImageEdit = null;
}

function FeaturedImageField( props ) {
	if ( isLinked( props.data ) ) {
		return createElement( FeaturedImagePreview, { item: props.data } );
	}
	return createElement( CoreFeaturedImageEdit, props );
}

let featuredImage = {};
if ( CoreFeaturedImageEdit ) {
	featuredImage = { Edit: FeaturedImageField };
} else if ( isLinked() ) {
	featuredImage = { readOnly: true, render: FeaturedImagePreview };
}

const locked = { isDisabled: ( { item } ) => isLinked( item ) };

export default {
	excerpt: locked,
	discussion: locked,
	comment_status: locked,
	ping_status: locked,
	featured_media: featuredImage,
};
