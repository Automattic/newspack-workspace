/* globals newspackMyAccountV1, newspackUI */

/**
 * Initialize functions for the Subscriptions page.
 */

import { domReady, setButtonLoading } from '../../utils';
import { initGroupMemberSearch } from './group-member-search';

domReady( function () {
	const content = document.querySelector( '.newspack-my-account__group_subscription__content' );
	if ( ! content ) {
		return;
	}

	initGroupMemberSearch( content );

	const subId = parseInt( content.getAttribute( 'data-subscription-id' ), 10 );
	const baseUrl = newspackMyAccountV1?.rest?.base_url;
	const namespace = newspackMyAccountV1?.rest?.namespaces?.group;
	const nonce = newspackMyAccountV1?.rest?.nonce;
	const showSnackbar =
		typeof newspackUI?.notices?.createNotice === 'function'
			? newspackUI.notices.createNotice
			: ( msg, type ) => console.warn( '[group-subscriptions]', type, msg ); // eslint-disable-line no-console

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

	// Invite in bulk: the list goes to the server twice, once for a review and once to send, so
	// the server's parser and seat check decide both and the reader confirms what is sent.
	const bulkModal = document.getElementById( 'newspack-my-account__group_subscription--invite-bulk' );
	const bulkForm = bulkModal?.querySelector( '.newspack-my-account__group_subscription__invite-bulk__input' );
	const bulkReview = bulkModal?.querySelector( '.newspack-my-account__group_subscription__invite-bulk__review' );
	// The buttons open the modal even when the group is full: the modal then carries the
	// at-limit message instead of the form.
	if ( bulkModal ) {
		[ ...document.querySelectorAll( '.newspack-my-account__subscription--invite-bulk' ) ].forEach( open => {
			open.addEventListener( 'click', event => {
				event.preventDefault();
				open.closest( '.newspack-ui__dropdown' )?.classList.remove( 'active' );
				bulkModal.setAttribute( 'data-state', 'open' );
			} );
		} );
	}
	if ( bulkForm && bulkReview ) {
		const summary = bulkReview.querySelector( '.newspack-my-account__group_subscription__invite-bulk__summary' );
		const errorNotice = bulkModal.querySelector( '.newspack-my-account__group_subscription__invite-bulk__error' );
		const sendButton = bulkReview.querySelector( '.newspack-my-account__group_subscription__invite-bulk__send' );
		const backButton = bulkReview.querySelector( '.newspack-my-account__group_subscription__invite-bulk__back' );
		const doneLink = bulkReview.querySelector( '.newspack-my-account__group_subscription__invite-bulk__done' );
		const textarea = bulkForm.querySelector( 'textarea' );
		const fileInput = bulkForm.querySelector( 'input[type="file"]' );
		const fileName = bulkForm.querySelector( '.newspack-my-account__group_subscription__invite-bulk__filename' );
		const removeFile = bulkForm.querySelector( '.newspack-my-account__group_subscription__invite-bulk__remove-file' );
		const closeButtons = [ ...bulkModal.querySelectorAll( '.newspack-ui__modal__close' ) ];
		const errorText = bulkForm.getAttribute( 'data-error-text' );
		const unfinishedText = bulkForm.getAttribute( 'data-unfinished-text' );
		let sent = false;

		// Each step hides the button that had focus, so focus moves with the step.
		const showStep = isReview => {
			bulkForm.classList.toggle( 'hidden', isReview );
			bulkReview.classList.toggle( 'hidden', ! isReview );
			( isReview ? bulkReview : textarea ).focus();
		};
		const showError = message => {
			errorNotice.classList.toggle( 'hidden', ! message );
			errorNotice.querySelector( 'p' ).textContent = message || '';
		};
		// Lines come from the server and may echo what the reader typed, so they are set as text.
		const renderSummary = lines => {
			summary.replaceChildren(
				...( lines || [] ).map( line => {
					const item = document.createElement( 'li' );
					item.textContent = line;
					return item;
				} )
			);
		};
		const readInput = async () => {
			const file = fileInput.files?.[ 0 ];
			return {
				subscription_id: subId,
				emails: textarea.value,
				csv: file ? await file.text() : '',
			};
		};
		// A refusal comes back as a JSON error with a reason. Anything else (a dropped
		// connection, a timeout page) says nothing about what the server got through.
		const postBulk = async body => {
			let response;
			let data;
			try {
				response = await fetch( `${ baseUrl }${ namespace }/invite-bulk`, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body: JSON.stringify( body ),
				} );
				data = await response.json();
			} catch ( error ) {
				throw Object.assign( new Error( errorText ), { unfinished: true } );
			}
			if ( ! response.ok ) {
				throw Object.assign( new Error( data?.message || errorText ), { unfinished: response.status >= 500 } );
			}
			return data;
		};

		const showFileName = () => {
			fileName.textContent = fileInput.files?.[ 0 ]?.name || '';
			removeFile.classList.toggle( 'hidden', ! fileName.textContent );
		};
		const uploadButton = bulkForm.querySelector( '.newspack-my-account__group_subscription__invite-bulk__upload' );
		uploadButton.addEventListener( 'click', () => fileInput.click() );
		fileInput.addEventListener( 'change', showFileName );
		removeFile.addEventListener( 'click', event => {
			event.preventDefault();
			fileInput.value = '';
			showFileName();
			uploadButton.focus();
		} );

		// Send stays focusable while it can't be used, so a keyboard user can reach it and
		// hear why; the click handler refuses instead.
		const setSendable = sendable => {
			sendButton.setAttribute( 'aria-disabled', sendable ? 'false' : 'true' );
			sendButton.classList.toggle( 'disabled', ! sendable );
		};

		// Once something was sent, closing the modal reloads the Invitations tab, as Done does.
		closeButtons.forEach( close => {
			close.addEventListener( 'click', () => {
				if ( sent ) {
					window.location.href = doneLink.href;
				}
			} );
		} );

		bulkForm.addEventListener( 'submit', event => {
			event.preventDefault();
			const reviewButton = bulkForm.querySelector( 'button[type="submit"]' );
			return withLoadingButton( reviewButton, async () => {
				showError( '' );
				try {
					const data = await postBulk( await readInput() );
					renderSummary( data.summary );
					showError( data.over_limit || '' );
					setSendable( ! data.over_limit && !! data.to_invite?.length );
					sendButton.classList.remove( 'hidden' );
					backButton.classList.remove( 'hidden' );
					doneLink.classList.add( 'hidden' );
					showStep( true );
				} catch ( error ) {
					showError( error.message );
				}
			} );
		} );

		backButton.addEventListener( 'click', event => {
			event.preventDefault();
			showError( '' );
			showStep( false );
		} );

		// Focus goes to the outcome, so it is read before Done.
		const showDone = () => {
			sendButton.classList.add( 'hidden' );
			backButton.classList.add( 'hidden' );
			doneLink.classList.remove( 'hidden' );
			bulkReview.focus();
		};

		sendButton.addEventListener( 'click', event => {
			event.preventDefault();
			if ( 'true' === sendButton.getAttribute( 'aria-disabled' ) ) {
				return;
			}
			// Nothing else in the modal may act while the send is in flight. Closing it then
			// would leave a review whose list may already have been sent, so a close during
			// or after a send reloads the Invitations tab, as Done does.
			sent = true;
			[ backButton, ...closeButtons ].forEach( button => button.setAttribute( 'disabled', '' ) );
			let refused = false;
			return withLoadingButton( sendButton, async () => {
				showError( '' );
				try {
					const data = await postBulk( { ...( await readInput() ), confirm: true } );
					renderSummary( data.summary );
					showDone();
				} catch ( error ) {
					if ( error.unfinished ) {
						renderSummary( [] );
						showError( unfinishedText );
						showDone();
						return;
					}
					// A refusal, the seat check included, sends nothing, so the review stays
					// open, and the same list can't be sent again until it changes.
					refused = true;
					sent = false;
					showError( error.message );
				}
			} ).finally( () => {
				[ backButton, ...closeButtons ].forEach( button => button.removeAttribute( 'disabled' ) );
				if ( refused ) {
					setSendable( false );
				}
			} );
		} );
	}

	// Handle remove-member confirm modal via event delegation.
	const removeMemberModal = document.getElementById( 'newspack-my-account__group_subscription--confirm-remove-member' );
	if ( removeMemberModal ) {
		const memberIdInput = removeMemberModal.querySelector( '[data-remove-member-id]' );
		const memberNameSlot = removeMemberModal.querySelector( '[data-member-name]' );
		document.addEventListener( 'click', event => {
			const trigger = event.target.closest( '.newspack-my-account__group_subscription__remove-member' );
			if ( ! trigger ) {
				return;
			}
			event.preventDefault();
			if ( memberIdInput ) {
				memberIdInput.value = trigger.getAttribute( 'data-member-id' ) || '';
			}
			if ( memberNameSlot ) {
				memberNameSlot.textContent = trigger.getAttribute( 'data-member-name' ) || '';
			}
			trigger.closest( '.newspack-ui__dropdown' )?.classList.remove( 'active' );
			removeMemberModal.setAttribute( 'data-state', 'open' );
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

	// Run an async action while a button shows its loading state, always restoring the
	// button afterwards. A press while its action is still running does nothing, even
	// if something else has cleared the button's loading state in the meantime. The
	// action owns its own waitForMinLoading() timing.
	const runningButtons = new WeakSet();
	const withLoadingButton = async ( button, action ) => {
		if ( runningButtons.has( button ) ) {
			return;
		}
		runningButtons.add( button );
		setButtonLoading( button, true );
		try {
			return await action();
		} finally {
			runningButtons.delete( button );
			setButtonLoading( button, false );
		}
	};

	const generateLink = e =>
		withLoadingButton( e.currentTarget, async () => {
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
						? newspackMyAccountV1?.labels?.invite_link_regenerated || 'New invite link copied. Any older link no longer works.'
						: newspackMyAccountV1?.labels?.invite_link_copied || 'Invite link copied.';
					showSnackbar( message );
				} else {
					showSnackbar( copyFailedMessage( data.url ), 'error' );
				}
			} catch ( error ) {
				await waitForMinLoading( loadingStart );
				showSnackbar( errorText, 'error' );
			}
		} );

	const deleteLink = e =>
		withLoadingButton( e.currentTarget, async () => {
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
			}
		} );

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

	// Rename group: the "Rename" action in the header (More menu at large viewports, plain button at small ones) opens a modal; saving POSTs the new name and
	// updates the header (and any other) group-name element in place.
	const renameModal = document.getElementById( 'newspack-my-account__group_subscription--rename' );
	const renameForm = renameModal?.querySelector( '.newspack-my-account__group--rename-form' );
	const renameInput = renameModal?.querySelector( '.newspack-my-account__group--rename-input' );
	const renameTriggers = [ ...document.querySelectorAll( '.newspack-my-account__group--rename' ) ];
	const groupNameEls = [ ...document.querySelectorAll( '[data-group-name]' ) ];
	if ( renameModal && renameForm && renameInput ) {
		// The rename request targets the subscription id carried on the form itself (always
		// rendered server-side), rather than reusing the invite panel's id — a missing
		// attribute then surfaces as a failed request instead of silently renaming the wrong
		// group. The input is seeded with the stored custom name (empty when the group uses an
		// inherited fallback), tracked here so it survives a rename without a page reload; the
		// resolved fallback shows as the input's placeholder.
		const renameSubId = parseInt( renameForm.getAttribute( 'data-subscription-id' ), 10 );
		let customName = renameInput.value;
		renameTriggers.forEach( trigger => {
			trigger.addEventListener( 'click', event => {
				event.preventDefault();
				renameInput.value = customName;
				renameModal.setAttribute( 'data-state', 'open' );
				window.requestAnimationFrame( () => {
					renameInput.focus();
					renameInput.select();
				} );
			} );
		} );

		renameForm.addEventListener( 'submit', event => {
			event.preventDefault();
			const saveButton = renameForm.querySelector( '.newspack-my-account__group--rename-save' );
			const errorText = renameForm.getAttribute( 'data-error-text' );
			const name = renameInput.value.trim();
			return withLoadingButton( saveButton, async () => {
				const loadingStart = now();
				try {
					const response = await fetch( `${ baseUrl }${ namespace }/name`, {
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': nonce,
						},
						body: JSON.stringify( { subscription_id: renameSubId, name } ),
					} );
					const data = await response.json();
					await waitForMinLoading( loadingStart );
					if ( ! response.ok || ! data || typeof data.name !== 'string' ) {
						showSnackbar( ( data && data.message ) || errorText, 'error' );
						return;
					}
					// Track what the user actually typed (empty when cleared) for the next open,
					// while the header shows the resolved name (which may be the inherited fallback).
					customName = name;
					groupNameEls.forEach( el => {
						el.textContent = data.name;
					} );
					renameModal.setAttribute( 'data-state', 'closed' );
					showSnackbar( newspackMyAccountV1?.labels?.group_name_updated || 'Name updated.' );
				} catch ( error ) {
					await waitForMinLoading( loadingStart );
					showSnackbar( errorText, 'error' );
				}
			} );
		} );
	}
} );
