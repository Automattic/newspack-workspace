/* global inlineEditPost, jQuery */
jQuery( function ( $ ) {
	const $wp_inline_edit = inlineEditPost.edit;
	inlineEditPost.edit = function ( id ) {
		$wp_inline_edit.apply( this, arguments );
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
