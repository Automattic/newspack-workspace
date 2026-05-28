/* globals jQuery, newspack_revisions_control */

import './newspack-revisions.scss';

( function ( $ ) {
	if ( typeof wp.revisions.view.MetaTo !== 'undefined' ) {
		const TickMarks = wp.revisions.view.Tickmarks,
			old_ready = TickMarks.prototype.ready;

		TickMarks.prototype.ready = function () {
			old_ready.apply( this );

			// update Tickmarks
			const tickmarks = $( '.revisions-tickmarks > div' );

			for ( let i = 0; i < tickmarks.length; i++ ) {
				// Add the ID to the element so we can manipulate it later.
				$( tickmarks[ i ] )
					.attr( 'id', 'tickmark-' + this.model.revisions.models[ i ].attributes.id )
					.addClass( 'newspack-revisions-after-bgcolor' );
				if ( this.model.revisions.models[ i ].attributes.newspack_major ) {
					$( tickmarks[ i ] ).addClass( 'newspack-major' );
				}
			}
		};

		const MetaTo = wp.revisions.view.MetaTo,
			old_render = MetaTo.prototype.render;

		/**
		 * Callback after ajax request
		 */
		MetaTo.prototype.updateRevisionMajor = function ( major ) {
			this.model.attributes.to.attributes.newspack_major = major;
			this.updateToggleMajorButton();
			const tick = $( '#tickmark-' + this.model.attributes.to.attributes.id );
			if ( major ) {
				tick.addClass( 'newspack-major' );
				this.$el.addClass( 'newspack-major' );
			} else {
				tick.removeClass( 'newspack-major' );
				this.$el.removeClass( 'newspack-major' );
			}
		};

		/**
		 * Handles the creation of the button to toggle revision
		 */
		MetaTo.prototype.updateToggleMajorButton = function () {
			const labels = newspack_revisions_control.labels;

			const button = this.$el.find( '.mark-major' );
			button.prop( 'value', this.model.attributes.to.attributes.newspack_major ? labels.unmark : labels.mark );
		};

		/**
		 * Gets the Message element
		 */
		MetaTo.prototype.getMessageSpan = function () {
			return this.$el.find( '.mark-major-message' );
		};

		/**
		 * Overrides the original render method
		 */
		MetaTo.prototype.render = function () {
			// Have the original renderer run first.
			old_render.apply( this, arguments );

			const labels = newspack_revisions_control.labels;

			// Add button
			const button = document.createElement( 'input' );
			button.type = 'button';
			button.value = '';
			button.className = 'mark-major button button-secondary';

			const t = this; // eslint-disable-line @typescript-eslint/no-this-alias
			const revision_id = this.model.attributes.to.attributes.id;
			const post_id = this.model.attributes.postId;

			button.onclick = () => {
				t.getMessageSpan().html( labels.loading ).show();
				toggleRevisionMajor( post_id, revision_id, function ( data ) {
					t.getMessageSpan().html( labels.saved ).fadeOut( 1000 );
					const response = jQuery.parseJSON( data );
					t.updateRevisionMajor( response.major );
				} );
			};

			this.$el.find( '.mark-major' ).remove();
			this.$el.append( button );

			// Add feedback message Span
			const message = document.createElement( 'span' );
			message.className = 'mark-major-message';
			message.style = 'margin-left:10px';
			this.$el.append( message );

			const label = document.createElement( 'span' );
			label.className = 'major-label newspack-revisions-color';
			label.visible = false;
			label.innerHTML = labels.major_revision;
			this.$el.find( '.author-info' ).append( label );

			this.updateRevisionMajor( this.model.attributes.to.attributes.newspack_major );
		};

		/**
		 * Ajax call to toggle revision
		 */
		function toggleRevisionMajor( post_id, id, callback ) {
			$.post(
				newspack_revisions_control.ajax_url,
				{
					action: 'newspack_toggle_revision_major',
					revision_id: id,
					post_id,
					_ajax_nonce: newspack_revisions_control.mark_major_nonce,
				},
				callback
			);
		}
	}
} )( jQuery );
