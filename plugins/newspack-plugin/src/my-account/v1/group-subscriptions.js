/* globals newspackMyAccountV1, newspackUI */

/**
 * Initialize functions for the Subscriptions page.
 */

import { domReady } from '../../utils';

domReady( function () {
	const content = document.querySelector( '.newspack-my-account__group_subscription__content' );
	if ( ! content ) {
		return;
	}

	const subId = parseInt( content.getAttribute( 'data-subscription-id' ), 10 );
	const baseUrl = newspackMyAccountV1?.rest?.base_url;
	const namespace = newspackMyAccountV1?.rest?.namespaces?.group;
	const nonce = newspackMyAccountV1?.rest?.nonce;
	const showSnackbar =
		typeof newspackUI?.notices?.createNotice === 'function'
			? newspackUI.notices.createNotice
			: ( msg, type ) => console.warn( '[group-subscriptions]', type, msg ); // eslint-disable-line no-console

	// Swap each tab badge between --outline (default) and --secondary (selected).
	const segmentedControl = content.querySelector( '.newspack-my-account__group_subscription__segmented-control' );
	if ( segmentedControl ) {
		const syncBadgeVariants = () => {
			segmentedControl.querySelectorAll( '.newspack-ui__segmented-control__tabs > .newspack-ui__button' ).forEach( button => {
				const badge = button.querySelector( '.newspack-ui__badge' );
				if ( ! badge ) {
					return;
				}
				const isSelected = button.classList.contains( 'selected' );
				badge.classList.toggle( 'newspack-ui__badge--secondary', isSelected );
				badge.classList.toggle( 'newspack-ui__badge--outline', ! isSelected );
			} );
		};
		syncBadgeVariants();
		// `content-selected` fires after `.selected` is toggled, avoiding a click-handler ordering race.
		segmentedControl.addEventListener( 'content-selected', syncBadgeVariants );
	}

	// Handle invite modal.
	const inviteModal = document.getElementById( 'newspack-my-account__group_subscription--invite-member' );
	const openInviteModal = [ ...document.querySelectorAll( '.newspack-my-account__subscription--invite-member' ) ];
	if ( openInviteModal && inviteModal ) {
		openInviteModal.forEach( open => {
			open.addEventListener( 'click', event => {
				event.preventDefault();
				inviteModal.setAttribute( 'data-state', 'open' );
			} );
		} );
	}

	// Invite-link: copy / create / regenerate / disable flow.
	const restUrl = `${ baseUrl }${ namespace }/invite-link`;
	const copyButtons = [ ...document.querySelectorAll( '.newspack-my-account__group_subscription__invite-link__copy' ) ];
	const regenerateButtons = [ ...document.querySelectorAll( '.newspack-my-account__group_subscription__invite-link__regenerate' ) ];
	const disableButtons = [ ...document.querySelectorAll( '.newspack-my-account__group_subscription__invite-link__disable' ) ];
	const regenerateModal = document.getElementById( 'newspack-my-account__group_subscription--confirm-regenerate-link' );
	const disableModal = document.getElementById( 'newspack-my-account__group_subscription--confirm-disable-link' );
	const openRegenerateModal = [ ...document.querySelectorAll( '.newspack-my-account__group_subscription__invite-link__confirm-regenerate' ) ];
	const openDisableModal = [ ...document.querySelectorAll( '.newspack-my-account__group_subscription__invite-link__confirm-disable' ) ];
	if ( regenerateModal ) {
		openRegenerateModal.forEach( open => {
			open.addEventListener( 'click', event => {
				event.preventDefault();
				regenerateModal.setAttribute( 'data-state', 'open' );
			} );
		} );
	}
	if ( disableModal ) {
		openDisableModal.forEach( open => {
			open.addEventListener( 'click', event => {
				event.preventDefault();
				disableModal.setAttribute( 'data-state', 'open' );
			} );
		} );
	}

	// After an invite link is created or deleted, close open modals and show or hide invite link controls.
	const afterInviteLink = ( show = true ) => {
		[ ...openRegenerateModal, ...openDisableModal ].forEach( button => {
			const parent = button.closest( 'li' );
			const el = parent || button;
			if ( show ) {
				const wasHidden = el.classList.contains( 'hidden' );
				el.classList.remove( 'hidden' );
				if ( wasHidden ) {
					el.classList.add( 'newspack-my-account__group_subscription__entering' );
					if ( parseFloat( getComputedStyle( el ).animationDuration ) > 0 ) {
						el.addEventListener( 'animationend', () => el.classList.remove( 'newspack-my-account__group_subscription__entering' ), {
							once: true,
						} );
					} else {
						el.classList.remove( 'newspack-my-account__group_subscription__entering' );
					}
				}
			} else {
				el.classList.add( 'hidden' );
			}
		} );
		[ ...document.querySelectorAll( '.newspack-ui__modal-container[data-state="open"]' ) ].forEach( modal =>
			modal.setAttribute( 'data-state', 'closed' )
		);
	};
	const copyToClipboard = async text => {
		if ( ! text ) {
			return false;
		}
		try {
			await navigator.clipboard.writeText( text );
			return true;
		} catch ( e ) {
			// Legacy fallback for insecure contexts / blocked clipboard permission.
			try {
				const textarea = document.createElement( 'textarea' );
				textarea.value = text;
				textarea.setAttribute( 'readonly', '' );
				textarea.style.position = 'fixed';
				textarea.style.opacity = '0';
				document.body.appendChild( textarea );
				textarea.select();
				const ok = document.execCommand( 'copy' );
				document.body.removeChild( textarea );
				return ok;
			} catch ( e2 ) {
				return false;
			}
		}
	};

	const copyFailedMessage = url => {
		const text = newspackMyAccountV1?.labels?.invite_link_copy_failed || "Couldn't copy the invite link to your clipboard. Copy it manually:";
		return `${ text } ${ url }`;
	};
	// Minimum loading duration so the spinner reads as "system thinking" even when the API is instant.
	const MIN_LOADING_MS = 500;
	const now = () => ( typeof performance !== 'undefined' && performance.now ? performance.now() : Date.now() );
	const waitForMinLoading = start => {
		const elapsed = now() - start;
		return elapsed < MIN_LOADING_MS ? new Promise( resolve => setTimeout( resolve, MIN_LOADING_MS - elapsed ) ) : Promise.resolve();
	};

	const generateLink = async e => {
		const el = e.currentTarget;
		el.classList.add( 'newspack-ui__button--loading' );
		el.setAttribute( 'aria-busy', 'true' );
		el.setAttribute( 'disabled', '' );
		const errorText = e.currentTarget.getAttribute( 'data-error-text' );
		const loadingStart = now();
		try {
			const response = await fetch( restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify( { subscription_id: subId } ),
			} );
			const data = await response.json();
			await waitForMinLoading( loadingStart );
			if ( ! response.ok || ! data || ! data.url ) {
				const message = ( data && data.message ) || errorText;
				showSnackbar( message, 'error' );
				return;
			}
			const isRegenerate = !! content.getAttribute( 'data-invite-link' );
			content.setAttribute( 'data-invite-link', data.url );
			afterInviteLink( true );
			if ( await copyToClipboard( data.url ) ) {
				const message = isRegenerate
					? newspackMyAccountV1?.labels?.invite_link_regenerated || 'New invite link copied. The old one no longer works.'
					: newspackMyAccountV1?.labels?.invite_link_copied || 'Invite link copied.';
				showSnackbar( message );
			} else {
				showSnackbar( copyFailedMessage( data.url ), 'error' );
			}
		} catch ( error ) {
			await waitForMinLoading( loadingStart );
			showSnackbar( errorText, 'error' );
		} finally {
			el.classList.remove( 'newspack-ui__button--loading' );
			el.removeAttribute( 'aria-busy' );
			el.removeAttribute( 'disabled' );
		}
	};

	const deleteLink = async e => {
		const el = e.currentTarget;
		el.classList.add( 'newspack-ui__button--loading' );
		el.setAttribute( 'aria-busy', 'true' );
		el.setAttribute( 'disabled', '' );
		const errorText = e.currentTarget.getAttribute( 'data-error-text' );
		const loadingStart = now();
		try {
			const response = await fetch( restUrl, {
				method: 'DELETE',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify( { subscription_id: subId } ),
			} );
			const data = await response.json();
			await waitForMinLoading( loadingStart );
			if ( ! response.ok ) {
				const message = ( data && data.message ) || errorText;
				showSnackbar( message, 'error' );
				return;
			}
			showSnackbar( newspackMyAccountV1?.labels?.invite_link_disabled || 'Invite link disabled. You can create a new link any time.' );
			content.removeAttribute( 'data-invite-link' );
			afterInviteLink( false );
		} catch ( error ) {
			await waitForMinLoading( loadingStart );
			showSnackbar( errorText, 'error' );
		} finally {
			el.classList.remove( 'newspack-ui__button--loading' );
			el.removeAttribute( 'aria-busy' );
			el.removeAttribute( 'disabled' );
		}
	};

	copyButtons.forEach( button => {
		button.addEventListener( 'click', async e => {
			e.preventDefault();
			const inviteLink = content.getAttribute( 'data-invite-link' );
			if ( inviteLink ) {
				if ( await copyToClipboard( inviteLink ) ) {
					showSnackbar( newspackMyAccountV1?.labels?.invite_link_copied || 'Invite link copied.' );
				} else {
					showSnackbar( copyFailedMessage( inviteLink ), 'error' );
				}
			} else {
				generateLink( e );
			}
		} );
	} );
	regenerateButtons.forEach( button => {
		button.addEventListener( 'click', async e => {
			e.preventDefault();
			generateLink( e );
		} );
	} );
	disableButtons.forEach( button => {
		button.addEventListener( 'click', async e => {
			e.preventDefault();
			deleteLink( e );
		} );
	} );
} );
