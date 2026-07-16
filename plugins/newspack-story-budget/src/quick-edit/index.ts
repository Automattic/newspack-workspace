/**
 * Minimal WordPress inline-edit ("Quick Edit") global, provided by
 * `wp-admin/js/inline-edit-post.js`. The real `edit()` also forwards a second (`bulk`) argument
 * that this file never reads -- typed as a rest param so `arguments` can still be forwarded
 * verbatim to `$wp_inline_edit` below without widening `id` itself past what this file uses.
 */
interface InlineEditPost {
	edit: ( id: string | Record< string, unknown >, ...rest: unknown[] ) => void;
	getId: ( id: Record< string, unknown > ) => string;
}

declare const inlineEditPost: InlineEditPost;

/** Minimal jQuery selection surface used by this admin script. */
interface QuickEditSelection {
	find: ( selector: string ) => QuickEditSelection;
	data: ( key: string ) => string | number | undefined;
	val: ( value?: string | string[] ) => void;
}

/** Minimal jQuery global surface used by this admin script: only the ready-callback form. */
declare const jQuery: ( callback: ( $: ( selector: string ) => QuickEditSelection ) => void ) => void;

jQuery( function ( $ ) {
	const $wp_inline_edit = inlineEditPost.edit;
	inlineEditPost.edit = function ( this: InlineEditPost, id: string | Record< string, unknown >, ...rest: unknown[] ) {
		$wp_inline_edit.apply( this, [ id, ...rest ] );
		let post_id = 0;
		if ( typeof id === 'object' ) {
			post_id = parseInt( this.getId( id ) );
		}
		if ( post_id > 0 ) {
			const $row = $( '#post-' + post_id );
			const $quick_edit_row = $( '#edit-' + post_id );
			const $budgets_span = $row.find( '.np-story-budget-budgets' );
			const budgets = $budgets_span.data( 'budgets' );
			const $select = $quick_edit_row.find( 'select[name="newspack_story_budget_budgets[]"]' );
			if ( typeof budgets !== 'undefined' ) {
				if ( budgets ) {
					// If multiple, split, otherwise set directly
					const arr = budgets.toString().split( ',' );
					$select.val( arr );
				} else {
					$select.val( '' );
				}
			}
		}
	};
} );
