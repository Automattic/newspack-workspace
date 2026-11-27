/**
 * The WordPress list-table inline-edit ("Quick Edit") global, provided by
 * `wp-admin/js/inline-edit-post.js`.
 */
interface InlineEditPost {
	edit: Function;
	getId: ( post: unknown ) => string;
}

declare const inlineEditPost: InlineEditPost;

/** Minimal jQuery surface used by this admin script. */
interface QuickEditSelection {
	data: ( key: string ) => unknown;
	prop: ( name: string, value: boolean ) => void;
}
type QuickEditJQuery = ( selector: string ) => QuickEditSelection;

const jQuerySource: unknown = window && window.jQuery;
const jQuery = jQuerySource as QuickEditJQuery;

if ( 'undefined' !== typeof inlineEditPost ) {
	// eslint-disable-next-line no-undef
	const wp_inline_edit_function = inlineEditPost.edit;

	// eslint-disable-next-line no-undef
	inlineEditPost.edit = function ( this: InlineEditPost, post_id: unknown, ...rest: unknown[] ) {
		wp_inline_edit_function.apply( this, [ post_id, ...rest ] );

		let id = 0;
		if ( typeof post_id === 'object' ) {
			id = parseInt( this.getId( post_id ) );
		}

		if ( id > 0 ) {
			if ( jQuery( `tr#post-${ id } .inline_data.is_public` ).data( 'is_public' ) ) {
				jQuery( `tr#edit-${ id } :input[name="switch_public_page"]` ).prop( 'checked', true );
			}
		}
	};
}

export {};
