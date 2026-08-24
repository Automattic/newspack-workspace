/* globals jQuery, newspackGroupSubscriptions */

/**
 * Group Subscriptions admin JS.
 */

import './admin.scss';

( function ( $ ) {
	if ( ! $ ) {
		return;
	}

	// Initialize UI elements.
	function init() {
		$( 'input#_newspack_group_subscription_enabled' ).trigger( 'change' );
		const $select = $( '#_newspack_group_subscription_member_ids' );
		$select.select2( {
			ajax: {
				url: `${ newspackGroupSubscriptions.apiUrl }/search-users`,
				beforeSend( xhr ) {
					xhr.setRequestHeader( 'X-WP-Nonce', newspackGroupSubscriptions.apiNonce );
				},
				type: 'POST',
				delay: 2000,
				data( params ) {
					const subscriptionId = $select.closest( '.newspack-group-subscription__container' ).data( 'subscription-id' );
					return {
						search: params.term,
						subscription_id: subscriptionId,
					};
				},
				processResults( data ) {
					return {
						results: data,
					};
				},
				error( xhr, status, error ) {
					const errorMessage = xhr.responseJSON?.message || error;
					if ( errorMessage === 'abort' ) {
						return;
					}
					$select.before( `<mark class="error"><span class="dashicons dashicons-warning"></span><span class="message"></span></mark>` );
					$select.prev( 'mark.error' ).find( '.message' ).text( errorMessage );
				},
				cache: true,
			},
			closeOnSelect: true,
			minimumInputLength: 2,
			placeholder: newspackGroupSubscriptions.placeholder,
			allowClear: true,
		} );
		$select.on( 'select2:opening', function () {
			$select.parent().find( '.error' ).remove();
		} );
		$( '.newspack-group-subscription__container' ).each( function () {
			refreshLimitState( $( this ) );
		} );
	}

	// Toggle the at-limit state on a container: when the spots in use reach the member-seat limit,
	// the add-member form is hidden (via the `hidden` attribute, mirroring the server-side render)
	// and the "limit reached" notice is written into the live region. Spots in use = the
	// spot-marked rendered rows (reader-members + pending invites) plus data-spots-offset, which
	// folds in any members the server counts but that aren't rendered as rows (a non-reader member,
	// or a manager who also carries member meta) so this matches the server-side count. An empty
	// data-member-limit means unlimited.
	function refreshLimitState( $container ) {
		const rawLimit = $container.attr( 'data-member-limit' );
		const limit = parseInt( rawLimit, 10 );
		const $notice = $container.find( '.newspack-group-subscription__limit-notice' );
		const $addMember = $container.find( '.newspack-group-subscription__add-member' );
		let isAtLimit = false;
		if ( rawLimit !== '' && ! isNaN( limit ) ) {
			const offset = parseInt( $container.attr( 'data-spots-offset' ), 10 ) || 0;
			const used = $container.find( '.newspack-group-subscription__members-list li[data-consumes-spot]' ).length + offset;
			isAtLimit = used >= limit;
		}
		$container.toggleClass( 'is-at-limit', isAtLimit );
		$addMember.attr( 'hidden', isAtLimit ? 'hidden' : null );
		// Write and clear the sentence rather than toggling the visibility of static text: the live
		// region has to stay in the accessibility tree for the change to be announced at all.
		if ( isAtLimit && ! $notice.children().length ) {
			$notice.append( `<div class="notice notice-warning inline"><p></p></div>` );
			$notice.find( 'p' ).text( newspackGroupSubscriptions.limit_notice );
		} else if ( ! isAtLimit ) {
			$notice.empty();
		}
	}

	// Find rendered invite rows by email. Matching is done on the attribute value rather than by
	// interpolating the address into a selector string, and case-insensitively to match how the
	// server cancels invites (a stored invite and the account's email can differ in case).
	function findInviteRows( $membersList, email ) {
		const needle = String( email ).toLowerCase();
		return $membersList.find( 'li[data-email]' ).filter( function () {
			return $( this ).attr( 'data-email' ).toLowerCase() === needle;
		} );
	}

	// Remove any lingering "invitation sent" confirmation from the members section. It's shown by
	// inviteMember(); clear it before an unrelated add/remove/cancel so a stale notice doesn't read
	// oddly after an action it has nothing to do with.
	function clearInviteSuccess( $container ) {
		$container.find( '.newspack-group-subscription__members mark.success' ).remove();
	}

	// Show or hide group subscription options based on the enabled checkbox.
	function showOrHideOptions( e ) {
		const $metabox = $( e.currentTarget ).closest( '#newspack-group-subscription' );
		if ( $( e.currentTarget ).is( ':checked' ) ) {
			$metabox.addClass( 'enabled' );
		} else {
			$metabox.removeClass( 'enabled' );
		}
	}

	// Add member by ID to a group subscription.
	function addMember( e ) {
		e.preventDefault();
		const $select = $( e.currentTarget );
		$select.attr( 'disabled', true );
		const $container = $select.closest( '.newspack-group-subscription__container' );
		const subscriptionId = $container.data( 'subscription-id' );
		const memberToAdd = $select.val();
		if ( ! memberToAdd || ! subscriptionId ) {
			return;
		}
		clearInviteSuccess( $container );
		fetch( `${ newspackGroupSubscriptions.apiUrl }/members`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': newspackGroupSubscriptions.apiNonce,
			},
			body: JSON.stringify( { subscription_id: subscriptionId, members_to_add: [ memberToAdd ] } ),
		} )
			.then( response => response.json() )
			.then( data => {
				if ( data.code && data.message && data.message !== 'abort' ) {
					throw new Error( data.message );
				}
				if ( data.members_added?.[ memberToAdd ] ) {
					const $membersList = $container.find( '.newspack-group-subscription__members-list' );
					const $membersCount = $container.find( '.newspack-group-subscription__members-count' );
					$membersList.append(
						`<li data-consumes-spot="1"><a class="newspack-group-subscription__member-user-link" href="#"></a><a href="#" class="newspack-group-subscription__remove-member">&#215; <span class="screen-reader-text"></span></a></li>`
					);
					const $added = $membersList.find( 'li' ).last();
					$added
						.find( '.newspack-group-subscription__member-user-link' )
						.text( data.members_added[ memberToAdd ].email )
						.attr( 'href', data.members_added[ memberToAdd ].url );
					$added.find( ' .newspack-group-subscription__remove-member' ).data( 'user-id', memberToAdd );
					$added.find( '.screen-reader-text' ).text( newspackGroupSubscriptions.remove_label );
					// The server cancels any pending invite the add fulfils, so drop those rows too --
					// otherwise they keep counting toward the tally and the limit until a reload.
					( data.invites_cancelled || [] ).forEach( email => {
						findInviteRows( $membersList, email ).remove();
					} );
					$membersCount.text( $membersList.find( 'li' ).length );
					refreshLimitState( $container );
				}
			} )
			.catch( error => {
				$select.before( `<mark class="error"><span class="dashicons dashicons-warning"></span><span class="message"></span></mark>` );
				$select.parent().find( '.message' ).text( error.message );
			} )
			.finally( () => {
				$select.val( null ).trigger( 'change' );
				$select.attr( 'disabled', false );
			} );
	}

	// Remove member from a group subscription.
	function removeMember( e ) {
		e.preventDefault();
		const $this = $( e.currentTarget );
		const userId = $this.data( 'user-id' );
		const $container = $this.closest( '.newspack-group-subscription__container' );
		const subscriptionId = $container.data( 'subscription-id' );
		if ( ! userId || ! subscriptionId ) {
			return;
		}
		clearInviteSuccess( $container );
		const $listItem = $this.closest( 'li' );
		$listItem.addClass( 'newspack-group-subscription__to-remove' );
		$listItem.find( '.error' ).remove();
		fetch( `${ newspackGroupSubscriptions.apiUrl }/members`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': newspackGroupSubscriptions.apiNonce,
			},
			body: JSON.stringify( { subscription_id: subscriptionId, members_to_remove: [ userId ] } ),
		} )
			.then( response => response.json() )
			.then( data => {
				if ( data.code && data.message && data.message !== 'abort' ) {
					throw new Error( data.message );
				}
				if ( data.members_removed?.[ userId ] ) {
					const $membersList = $container.find( '.newspack-group-subscription__members-list' );
					const $membersCount = $container.find( '.newspack-group-subscription__members-count' );
					$listItem.remove();
					$membersCount.text( $membersList.find( 'li' ).length );
					refreshLimitState( $container );
				}
			} )
			.catch( error => {
				$this.after( `<mark class="error"><span class="dashicons dashicons-warning"></span><span class="message"></span></mark>` );
				$this.parent().find( '.message' ).text( error.message );
			} )
			.finally( () => {
				$this.parent().removeClass( 'newspack-group-subscription__to-remove' );
			} );
	}
	function inviteMember( e ) {
		if ( e.keyCode && e.keyCode !== 13 ) {
			return;
		}
		e.preventDefault();
		const $this = $( e.currentTarget );
		const $container = $this.closest( '.newspack-group-subscription__container' );
		$this.parent().find( '.error,.success' ).remove();
		clearInviteSuccess( $container );
		const $email = $container.find( 'input[name="_newspack_group_subscription_invite_email"]' );
		const $button = $this.parent().find( 'button' );
		$email.attr( 'disabled', true );
		$button.attr( 'disabled', true );
		const email = $email.val();
		if ( ! email ) {
			$this.parent().append( `<mark class="error"><span class="dashicons dashicons-warning"></span><span class="message"></span></mark>` );
			$this.parent().find( '.message' ).text( newspackGroupSubscriptions.invalid_email_message );
			$email.attr( 'disabled', false );
			$button.attr( 'disabled', false );
			return;
		}
		const subscriptionId = $container.data( 'subscription-id' );
		fetch( `${ newspackGroupSubscriptions.apiUrl }/invite`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': newspackGroupSubscriptions.apiNonce,
			},
			body: JSON.stringify( { subscription_id: subscriptionId, email } ),
		} )
			.then( response => response.json() )
			.then( data => {
				if ( data.code && data.message && data.message !== 'abort' ) {
					throw new Error( data.message );
				}
				$email.val( '' );
				const $membersList = $container.find( '.newspack-group-subscription__members-list' );
				const $membersCount = $container.find( '.newspack-group-subscription__members-count' );
				findInviteRows( $membersList, data.email ).remove();
				// The row is built empty and then populated with .text()/.attr(), so the address is
				// never interpolated into markup -- the escaping is local, not reliant on the REST
				// layer's sanitize_email().
				$membersList.append(
					`<li data-consumes-spot="1"><span class="newspack-group-subscription__pending-invite"></span> <span class="newspack-group-subscription__pending-invite-label"></span><a href="#" class="newspack-group-subscription__cancel-invite">&#215; <span class="screen-reader-text"></span></a></li>`
				);
				const $added = $membersList.find( 'li' ).last();
				$added.attr( 'data-email', data.email );
				$added.find( '.newspack-group-subscription__pending-invite' ).text( data.email );
				$added.find( '.newspack-group-subscription__pending-invite-label' ).text( newspackGroupSubscriptions.pending_label );
				$added.find( '.screen-reader-text' ).text( newspackGroupSubscriptions.cancel_label );
				$membersCount.text( $membersList.find( 'li' ).length );
				refreshLimitState( $container );
				// Show the confirmation in the members section, not the add-member form: when this
				// invite reaches the limit the form is hidden, which would otherwise swallow its own
				// "invitation sent" message.
				const $members = $container.find( '.newspack-group-subscription__members' );
				$members.append( `<mark class="success"><span class="dashicons dashicons-yes-alt"></span><span class="message"></span></mark>` );
				$members.find( 'mark.success .message' ).text( newspackGroupSubscriptions.success_message );
			} )
			.catch( error => {
				$this.parent().append( `<mark class="error"><span class="dashicons dashicons-warning"></span><span class="message"></span></mark>` );
				$this.parent().find( '.message' ).text( error.message );
			} )
			.finally( () => {
				$email.attr( 'disabled', false );
				$button.attr( 'disabled', false );
			} );
	}
	function cancelInvite( e ) {
		e.preventDefault();
		const $this = $( e.currentTarget );
		const $listItem = $this.closest( 'li' );
		$listItem.addClass( 'newspack-group-subscription__to-remove' );
		$listItem.find( '.error' ).remove();
		const email = $listItem.find( '.newspack-group-subscription__pending-invite' ).text();
		if ( ! email ) {
			$listItem.removeClass( 'newspack-group-subscription__to-remove' );
			$this.parent().removeClass( 'newspack-group-subscription__to-remove' );
			return;
		}
		const $container = $this.closest( '.newspack-group-subscription__container' );
		clearInviteSuccess( $container );
		const subscriptionId = $container.data( 'subscription-id' );
		fetch( `${ newspackGroupSubscriptions.apiUrl }/invite`, {
			method: 'DELETE',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': newspackGroupSubscriptions.apiNonce,
			},
			body: JSON.stringify( { subscription_id: subscriptionId, email } ),
		} )
			.then( response => response.json() )
			.then( data => {
				if ( data === false || ( data && data.code && data.message && data.message !== 'abort' ) ) {
					throw new Error( data.message || newspackGroupSubscriptions.cancel_error_message );
				}
				const $membersList = $container.find( '.newspack-group-subscription__members-list' );
				const $membersCount = $container.find( '.newspack-group-subscription__members-count' );
				$listItem.remove();
				$membersCount.text( $membersList.find( 'li' ).length );
				refreshLimitState( $container );
			} )
			.catch( error => {
				$this.after( `<mark class="error"><span class="dashicons dashicons-warning"></span><span class="message"></span></mark>` );
				$this.parent().find( '.message' ).text( error.message );
			} )
			.finally( () => {
				$this.parent().removeClass( 'newspack-group-subscription__to-remove' );
			} );
	}

	$( '#newspack-group-subscription' ).on( 'change', 'input#_newspack_group_subscription_enabled', showOrHideOptions );
	$( '#newspack-group-subscription' ).on( 'change', '#_newspack_group_subscription_member_ids', addMember );
	$( '#newspack-group-subscription' ).on( 'click', '.newspack-group-subscription__remove-member', removeMember );
	$( '#newspack-group-subscription' ).on( 'click', '.newspack-group-subscription__invite-member button', inviteMember );
	$( '#newspack-group-subscription' ).on( 'keydown', '.newspack-group-subscription__invite-member input', inviteMember );
	$( '#newspack-group-subscription' ).on( 'click', '.newspack-group-subscription__cancel-invite', cancelInvite );
	$( document ).ready( init );
} )( jQuery );
