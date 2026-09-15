/* globals newspackBlocksModal, newspack_ras_config */

/**
 * Style dependencies
 */
import './modal.scss';

/**
 * Internal dependencies
 */
import { manageDismissed, manageOpened } from './analytics';
import {
	afterDeferredScripts,
	domReady,
	whenReaderDataSynced,
	whenSignedInReaderDataSynced,
	iframeReady,
	onCheckoutReady,
	onCheckoutComplete,
	onCheckoutCancel,
	onCheckoutPlaceOrderStart,
	onCheckoutPlaceOrderGatewayAction,
	onCheckoutPlaceOrderError,
	onCheckoutPlaceOrderCriticalError,
	createHiddenInput,
	triggerFormSubmit,
	getCheckoutData,
	getFormattedAmount,
	getInitialFocus,
	hasFrameProcessingScreen,
	mountFrameProcessingScreen,
	preventDisabledCloseClicks,
	removeFrameProcessingScreen,
	setCloseButtonsDisabled,
} from './utils';
import {
	resolveCheckoutButtonForm,
	readCheckoutData,
	applyContextFields,
	appendUtmFields,
	readUtmParams,
	getDroppedLinkContext,
} from './checkout-button-trigger';
import { resolveDonationTrigger } from './donate-trigger';
import { TIERS_BASED_READY_EVENT } from '../shared/js/tiers-based-ready';
import { applyCtaAttribution } from '../shared/js/cta-attribution';

const CLASS_PREFIX = newspackBlocksModal.newspack_class_prefix;
const IFRAME_NAME = 'newspack_modal_checkout_iframe';
const IFRAME_CONTAINER_ID = 'newspack_modal_checkout_container';
const MODAL_CHECKOUT_ID = 'newspack_modal_checkout';
const MODAL_CLASS_PREFIX = `${ CLASS_PREFIX }__modal`;
const VARIATON_MODAL_CLASS_PREFIX = 'newspack-blocks__modal-variation';
const PROCESSING_PAYMENT_TEXT_CLASS = `${ CLASS_PREFIX }__processing-payment-text`;
const PROCESSING_PAYMENT_MESSAGES = newspackBlocksModal.processing_payment_messages;
// newspack-ui opens its dialogs itself. Without it, this script does.
const OPENS_OWN_DIALOGS = 'newspack-ui' !== CLASS_PREFIX;

// Track the checkout intent to avoid multiple analytics events.
let inCheckoutIntent = false;

// Checkout title.
let checkoutTitle = newspackBlocksModal.labels.checkout_modal_title;

// Last-submitted checkout form.
let activeCheckoutForm = null;

// Close the modal.
const closeModal = el => {
	if ( el.overlayId && window.newspackReaderActivation?.overlays ) {
		window.newspackReaderActivation?.overlays.remove( el.overlayId );
	}
	el.setAttribute( 'data-state', 'closed' );
	// Closed now rather than when newspack-ui catches up, so the page behind is no
	// longer inert and focus can go back to the trigger.
	if ( el.open ) {
		el.close();
	}
	document.body.style.overflow = 'auto';
};

// A content gate on the page reloads it once the last overlay closes after a checkout,
// which cancels a post-checkout navigation that is still loading. An overlay held from
// the moment the checkout navigates keeps the gate waiting.
const heldOverlays = [];
const holdOverlay = () => {
	const overlayId = window.newspackReaderActivation?.overlays?.add?.();
	if ( overlayId ) {
		heldOverlays.push( overlayId );
	}
};
const releaseHeldOverlays = () => {
	heldOverlays.splice( 0 ).forEach( overlayId => window.newspackReaderActivation?.overlays?.remove?.( overlayId ) );
};

// Cleanup if page is loaded via back button.
window.onpageshow = event => {
	if ( event.persisted ) {
		// If the page is loaded from the back button, find and remove any loading-related classes and modals:
		document.querySelectorAll( '.modal-processing' ).forEach( el => el.classList.remove( 'modal-processing' ) );
		// The payment that was processing belongs to the page the reader left.
		document.querySelector( `#${ MODAL_CHECKOUT_ID } .${ CLASS_PREFIX }__spinner` )?.removeAttribute( 'aria-busy' );
		document.querySelectorAll( '.non-modal-checkout-loading' ).forEach( el => {
			el.classList.remove( 'non-modal-checkout-loading' );
			el.removeAttribute( 'aria-busy' );
		} );
		document.querySelectorAll( `.${ MODAL_CLASS_PREFIX }-container` ).forEach( el => closeModal( el ) );
		// The reader is back on a page they were redirected away from, so the gate may reload it.
		releaseHeldOverlays();
	}
};

// Register the "checkout closed" event.
const checkoutClosedEvent = new CustomEvent( 'checkout-closed' );

window.newspackRAS = window.newspackRAS || [];

domReady( () => {
	const modalCheckout = document.querySelector( `#${ MODAL_CHECKOUT_ID }` );
	if ( ! modalCheckout ) {
		return;
	}

	const modalContent = modalCheckout.querySelector( `.${ MODAL_CLASS_PREFIX }__content` );
	const modalCheckoutHiddenInput = createHiddenInput( 'modal_checkout', '1' );
	const spinner = modalContent.querySelector( `.${ CLASS_PREFIX }__spinner` );
	const closeButtons = modalCheckout.querySelectorAll( `.${ MODAL_CLASS_PREFIX }__close` );
	preventDisabledCloseClicks( modalCheckout, `.${ MODAL_CLASS_PREFIX }__close` );
	// newspack-ui's processing screen already covers the modal header, close button included,
	// and the checkout document only has styles for a processing screen of its own with newspack-ui.
	const HAS_NEWSPACK_UI = CLASS_PREFIX === 'newspack-ui';
	let modalTrigger = document.querySelector( '.newspack-reader__account-link' )?.[ 0 ];
	// Initialize empty iframe.
	const initialHeight = '600px'; // Fixed initial height to avoid too much layout shift.
	const iframe = document.createElement( 'iframe' );
	iframe.name = IFRAME_NAME;
	iframe.style.height = initialHeight;
	iframe.style.visibility = 'hidden';

	/**
	 * Set the modal as ready.
	 *
	 * @param {Object} container The container element inside the iframe document.
	 */
	const setModalReady = container => {
		iframeResizeObserver.observe( container );
		if ( spinner.style.display !== 'none' ) {
			spinner.style.display = 'none';
		}
		// A gateway step in progress keeps the processing screen, and the disabled close button, in place.
		if ( ! hasFrameProcessingScreen( container.ownerDocument ) ) {
			setCloseButtonsDisabled( closeButtons, false );
		}
		if ( iframe.style.visibility !== 'visible' ) {
			iframe.style.visibility = 'visible';
		}
		iframe._ready = true;
	};

	/**
	 * Handle iframe load state.
	 */
	function handleIframeReady() {
		const location = iframe.contentWindow?.location;
		// If RAS is available, set the front-end authentication.
		if ( window.newspackReaderActivation && location?.href?.includes( 'order-received' ) ) {
			const ras = window.newspackReaderActivation;
			const params = new Proxy( new URLSearchParams( location.search ), {
				get: ( searchParams, prop ) => searchParams.get( prop ),
			} );
			if ( params.email ) {
				ras.setReaderEmail( params.email );
				ras.setAuthenticated( true );
			}
		}
		const container = iframe?.contentDocument?.querySelector( `#${ IFRAME_CONTAINER_ID }` );
		if ( ! container ) {
			return;
		}

		const productDetails = container.querySelector( '#modal-checkout-product-details' );
		const checkoutData = getCheckoutData( productDetails );

		let hasFocused = false;

		/**
		 * Move focus into this checkout screen once it shows. Opening the dialog can't
		 * see into the iframe, so focus waits on the heading until then. Focus the
		 * reader has moved to the Close button in the meantime stays there.
		 */
		const focusCheckout = () => {
			if ( hasFocused || 'open' !== modalCheckout.dataset.state ) {
				return;
			}
			hasFocused = true;
			const active = modalCheckout.ownerDocument.activeElement;
			if ( active && active !== iframe && active !== modalCheckout && modalCheckout.contains( active ) && active.tabIndex >= 0 ) {
				return;
			}
			getInitialFocus( container )?.focus();
		};

		const processingPaymentText = document.createElement( 'p' );
		processingPaymentText.classList.add( PROCESSING_PAYMENT_TEXT_CLASS );
		let processingPaymentTimeouts = [];

		const clearProcessingPaymentTimeouts = () => {
			processingPaymentTimeouts.forEach( timeoutId => clearTimeout( timeoutId ) );
			processingPaymentTimeouts = [];
		};

		const renderProcessingPaymentScreen = event => {
			spinner.querySelectorAll( `.${ PROCESSING_PAYMENT_TEXT_CLASS }` ).forEach( node => node.remove() );
			spinner.style.display = 'flex';
			// Escape leaves a checkout that is taking a payment open.
			spinner.setAttribute( 'aria-busy', 'true' );
			clearProcessingPaymentTimeouts();
			processingPaymentText.textContent = PROCESSING_PAYMENT_MESSAGES[ 0 ]?.text ?? '';
			PROCESSING_PAYMENT_MESSAGES.slice( 1 ).forEach( ( { text, delay } ) => {
				const timeoutId = setTimeout( () => {
					event.target.dispatchEvent( new CustomEvent( 'checkout-place-order-processing' ) );
					processingPaymentText.textContent = text;
				}, delay );
				processingPaymentTimeouts.push( timeoutId );
			} );
			spinner.appendChild( processingPaymentText );
			if ( HAS_NEWSPACK_UI ) {
				setCloseButtonsDisabled( closeButtons, true );
			}
		};

		const hideProcessingPaymentScreen = () => {
			spinner.style.display = 'none';
			spinner.removeAttribute( 'aria-busy' );
			clearProcessingPaymentTimeouts();
			spinner.querySelectorAll( `.${ PROCESSING_PAYMENT_TEXT_CLASS }` ).forEach( node => node.remove() );
			removeFrameProcessingScreen( container.ownerDocument );
			setCloseButtonsDisabled( closeButtons, false );
		};

		// The gateway draws its step (e.g. a 3D Secure challenge) inside the checkout
		// document, so the screen in this document would cover it. Move the processing
		// screen into the checkout document, where the gateway's step sits on top of it.
		const moveProcessingPaymentScreenToCheckout = () => {
			spinner.style.display = 'none';
			if ( HAS_NEWSPACK_UI ) {
				mountFrameProcessingScreen( container.ownerDocument, `${ CLASS_PREFIX }__spinner`, processingPaymentText );
			}
		};

		onCheckoutCancel( container, () => {
			closeCheckout();
		} );

		onCheckoutPlaceOrderStart( container, renderProcessingPaymentScreen );

		onCheckoutPlaceOrderGatewayAction( container, moveProcessingPaymentScreenToCheckout );

		onCheckoutPlaceOrderError( container, hideProcessingPaymentScreen );

		// The processing screen steps aside for a payment challenge, and comes back
		// while the payment finishes. Once it has moved into the checkout document,
		// under the gateway's step, that copy stays in charge.
		container.addEventListener( 'checkout-payment-challenge', ( { detail } ) => {
			if ( 'true' === spinner.getAttribute( 'aria-busy' ) && ! hasFrameProcessingScreen( container.ownerDocument ) ) {
				spinner.style.display = detail.shown ? 'none' : 'flex';
			}
		} );

		onCheckoutReady( container, () => {
			// Display initial errors if any.
			if ( modalCheckout.initialErrors ) {
				const errorContainer = document.createElement( 'div' );
				errorContainer.classList.add( 'woocommerce-error' );
				errorContainer.textContent = modalCheckout.initialErrors;
				container.prepend( errorContainer );
				delete modalCheckout.initialErrors;
			}

			// Revert modal title and width default value.
			setModalSize();
			setModalTitle( checkoutTitle );
			if ( iframe.contentWindow?.newspackBlocksModalCheckout?.checkout_nonce ) {
				// Store the checkout nonce for later use.
				// We store the nonce from the iframe content window to ensure the nonce was generated for a logged in session
				modalCheckout.checkout_nonce = iframe.contentWindow.newspackBlocksModalCheckout.checkout_nonce;
			}
			setModalReady( container );
			focusCheckout();
		} );

		onCheckoutComplete( container, () => {
			// Dispatch a `checkout_completed` activity to RAS.
			window.newspackRAS.push( [ 'checkout_completed', checkoutData ] );

			// Update the newsletters signup modal if it exists.
			if ( window?.newspackReaderActivation?.refreshNewslettersSignupModal && window?.newspackReaderActivation?.getReader()?.email ) {
				window.newspackReaderActivation.refreshNewslettersSignupModal( window.newspackReaderActivation.getReader().email );
			}

			// Update the modal title and width to reflect successful transaction.
			setModalSize( 'small' );
			setModalTitle( newspackBlocksModal.labels.thankyou_modal_title );
			setModalReady( container );
			hideProcessingPaymentScreen();
			focusCheckout();
		} );

		// Resubmit modal checkout form if an unrecoverable error is encountered.
		const refreshCheckout = form => {
			if ( ! form ) {
				return;
			}
			closeCheckout();
			spinner.style.display = 'none';
			modalCheckout.initialErrors = newspackBlocksModal.labels.critical_error;
			form.requestSubmit( form.querySelector( 'button[type="submit"]' ) );
			hideProcessingPaymentScreen();
		};

		onCheckoutPlaceOrderCriticalError( container, () => refreshCheckout( activeCheckoutForm ) );
	}

	iframeReady( iframe, handleIframeReady, () => {
		spinner.style.display = 'flex';
	} );

	/**
	 * Generate cart via ajax.
	 *
	 * This strategy, used for anonymous users, addresses an edge case in which
	 * the session for a newly registered reader fails to carry the cart over to
	 * the checkout.
	 *
	 * @param {Object} checkoutData The checkout data.
	 *
	 * @return {Promise} The promise that resolves with the checkout URL.
	 */
	const generateCart = checkoutData => {
		return new Promise( ( resolve, reject ) => {
			const urlParams = new URLSearchParams( checkoutData );
			urlParams.append( 'action', 'modal_checkout_request' );
			fetch( newspackBlocksModal.ajax_url + '?' + urlParams.toString() )
				.then( res => {
					if ( ! res.ok ) {
						reject( res );
					}
					res.json()
						.then( jsonData => {
							resolve( jsonData.url );
						} )
						.catch( reject );
				} )
				.catch( reject );
		} );
	};

	/**
	 * Empty cart via ajax.
	 */
	const emptyCart = async () => {
		const body = new FormData();
		if ( ! newspackBlocksModal.has_unsupported_payment_gateway ) {
			body.append( 'modal_checkout', '1' );
		}
		body.append( 'action', 'abandon_modal_checkout' );
		body.append( '_wpnonce', modalCheckout.checkout_nonce );
		modalCheckout.checkout_nonce = null;
		try {
			await fetch( newspackBlocksModal.ajax_url, {
				method: 'POST',
				body,
			} );
		} catch ( error ) {
			console.warn( 'Unable to empty cart:', error ); // eslint-disable-line no-console
		}
	};

	/**
	 * Whether reader should be prompted with registration.
	 */
	const shouldPromptRegistration = () =>
		typeof newspack_ras_config !== 'undefined' &&
		! newspack_ras_config?.is_logged_in &&
		! window?.newspackReaderActivation?.getReader?.()?.authenticated &&
		newspackBlocksModal?.is_registration_required &&
		window?.newspackReaderActivation?.openAuthModal;

	// Snapshot the landing page's utm params: the form's own GET submission
	// replaces the query string entirely, so the request the checkout sees
	// carries only what rides the form. Captured once, applied to every form
	// (direct, donate, picker) at submit time.
	const landingUtmParams = readUtmParams( window.location.search );

	/**
	 * Handle checkout form submit.
	 *
	 * @param {Event} ev
	 */
	const handleCheckoutFormSubmit = ev => {
		const isModalCheckout = ! newspackBlocksModal.has_unsupported_payment_gateway;
		if ( ! isModalCheckout ) {
			ev.preventDefault();
		}
		const form = ev.target;
		// On the redirect path the form stays on the page while its cart is built, so a
		// second press would build another.
		if ( ! isModalCheckout && form.classList.contains( 'modal-processing' ) ) {
			return;
		}
		form.classList.add( 'modal-processing' );

		// NPPD-1887: if the reader arrived here by clicking a paid-intent CTA in a gate
		// or prompt, replay that surface's id as a hidden field so the order carries
		// `_gate_post_id` / `_newspack_popup_id`. No-ops when the form already has one
		// (a form rendered inside the surface itself always wins) or when the form is
		// inside a gate. Must run BEFORE getCheckoutData(), which snapshots the form.
		applyCtaAttribution( form );

		// Carry the landing page's utm params into the checkout request itself, so
		// Modal_Checkout::merge_request_utm_params() reads them from $_GET instead
		// of depending on the referer.
		appendUtmFields( form, landingUtmParams );

		const checkoutData = getCheckoutData( form );

		const isDonateBlock = checkoutData.newspack_donate;
		if ( isDonateBlock ) {
			const frequency = checkoutData.donation_frequency;
			const donationTiers = [ ...form.querySelectorAll( `.donation-tier__${ frequency }, .donation-frequency__${ frequency }` ) ];
			const donationTierIndex = checkoutData.donation_tier_index;
			let donationContainer, customAmount;
			if ( donationTierIndex ) {
				donationContainer = donationTiers[ donationTierIndex ];
				customAmount = checkoutData[ `donation_value_${ frequency }` ];
			} else {
				donationContainer = donationTiers[ 0 ];
				if ( checkoutData[ `donation_value_${ frequency }_untiered` ] ) {
					customAmount = checkoutData[ `donation_value_${ frequency }_untiered` ];
				} else {
					customAmount = checkoutData[ `donation_value_${ frequency }` ];
					if ( customAmount === 'other' ) {
						customAmount = checkoutData[ `donation_value_${ frequency }_other` ];
					}
				}
			}
			const donationData = getCheckoutData( donationContainer );
			for ( const key in donationData ) {
				checkoutData[ key ] = donationData[ key ];
			}
			checkoutData.amount = customAmount;
			checkoutData.price_summary = checkoutData.summary_template.replace(
				'{{PRICE}}',
				getFormattedAmount( checkoutData.amount, checkoutData.currency )
			);
		}

		if ( checkoutData ) {
			Object.keys( checkoutData ).forEach( key => {
				const existingInputs = form.querySelectorAll( 'input[name="' + key + '"]' );
				if ( 0 === existingInputs.length ) {
					form.prepend( createHiddenInput( key, checkoutData[ key ] ) );
				}
			} );
		}

		// If we're not going from variation picker to checkout, set the modal trigger:
		if ( ! checkoutData.variation_id ) {
			modalTrigger = ev.submitter;
		}
		// Clear any open variation modal.
		const variationModals = document.querySelectorAll( `.${ VARIATON_MODAL_CLASS_PREFIX }` );
		variationModals.forEach( variationModal => {
			// Only close the variation picker if is the modal checkout, or if registration is required.
			if ( shouldPromptRegistration() || isModalCheckout ) {
				closeModal( variationModal );
			}
		} );

		// Trigger variation modal if variation is not selected.
		if ( checkoutData.is_grouped || ( checkoutData.is_variable && ! checkoutData.variation_id ) ) {
			const variationModal = [ ...variationModals ].find( modal => modal.dataset.productId === checkoutData.product_id );
			if ( variationModal ) {
				variationModal.querySelectorAll( `form[target="${ IFRAME_NAME }"]` ).forEach( singleVariationForm => {
					// Fill in the hidden params in the variation modal. The picker is
					// shared by every button for this product and is never reset, so
					// this overwrites the previous open's context rather than adding to it.
					// The shared PICKER_CONTEXT_FIELDS list carries `after_success_token`, so
					// the signed destination propagates through the picker with everything else.
					applyContextFields( singleVariationForm, checkoutData );

					// Append the product data hidden inputs.
					const data = readCheckoutData( singleVariationForm );
					if ( data ) {
						Object.keys( data ).forEach( key => {
							const existingInputs = singleVariationForm.querySelectorAll( 'input[name="' + key + '"]' );
							if ( 0 === existingInputs.length ) {
								singleVariationForm.prepend( createHiddenInput( key, data[ key ] ) );
							}
						} );
					}
				} );

				// Open the variations modal.
				ev.preventDefault();
				form.classList.remove( 'modal-processing' );
				openModal( variationModal );

				// For the variation modal we will not set `inCheckoutIntent = true` and
				// let the `opened` event get triggered once the user selects a
				// variation so we track the selection.
				if ( ! inCheckoutIntent ) {
					manageOpened( checkoutData );
				}

				// Append product data info to the modal itself, so we can grab it for manageDismissed:
				document.getElementById( 'newspack_modal_checkout' ).setAttribute( 'data-checkout', JSON.stringify( checkoutData ) );
				return;
			}
		}

		// Populate cart and redirect to checkout if there is an unsupported payment gateway.
		if ( ! isModalCheckout && ! shouldPromptRegistration() ) {
			generateCart( checkoutData )
				.then( url => {
					// Remove modal checkout query string and trailing question mark (if any).
					window.location.href = url;
				} )
				.catch( error => {
					console.warn( 'Unable to generate cart:', error ); // eslint-disable-line no-console
					form.classList.remove( 'modal-processing' );
					form.querySelectorAll( '.non-modal-checkout-loading' ).forEach( button => {
						button.classList.remove( 'non-modal-checkout-loading' );
						button.removeAttribute( 'aria-busy' );
					} );
				} );
			// Add some animation to the Checkout Button while the non-modal checkout is loading.
			// For now, don't do it when any popup opens, just when we go right to the checkout page.
			if ( ! ( checkoutData.is_variable && ! checkoutData.variation_id ) ) {
				const button = ev.submitter || form.querySelector( 'button[type=submit]' );
				if ( button ) {
					button.classList.add( 'non-modal-checkout-loading' );
					button.setAttribute( 'aria-busy', 'true' );
					const buttonText = button.innerHTML;
					button.innerHTML = '<span>' + buttonText + '</span>';
				}
			}
			return;
		}
		form.classList.remove( 'modal-processing' );

		// Analytics.
		if ( ! inCheckoutIntent ) {
			manageOpened( checkoutData );
		}
		inCheckoutIntent = true;

		if ( shouldPromptRegistration() ) {
			ev.preventDefault();

			// Generate cart asynchroneously.
			const cartReq = generateCart( checkoutData );

			// Update pending checkout URL.
			cartReq.then( url => {
				window.newspackReaderActivation?.setPendingCheckout?.( url );
			} );
			// Initialize auth flow if reader is not authenticated.
			window.newspackReaderActivation.openAuthModal( {
				title: newspackBlocksModal.labels.auth_modal_title,
				onSuccess: ( message, authData ) => {
					// The reader was anonymous when this page evaluated their
					// segments. Let the session hydrate and the snapshot reach the
					// server before the checkout reads it for pricing.
					whenSignedInReaderDataSynced( 'matched_segments' )
						.then( () => cartReq )
						.then( url => {
							// If registered and in a modal checkout, append the registration flag query param to the url.
							if ( authData?.registered && isModalCheckout ) {
								url += `&${ newspackBlocksModal.checkout_registration_flag }=1`;
							}
							// Populate cart and redirect to checkout if there is an unsupported payment gateway.
							if ( ! isModalCheckout ) {
								// Remove modal checkout query string, and trailing question mark (if any).
								generateCart( checkoutData ).then( ( window.location.href = url ) );
							} else {
								const checkoutForm = generateCheckoutPageForm( url );
								triggerFormSubmit( checkoutForm );
							}
						} )
						.catch( error => {
							console.warn( 'Unable to generate cart:', error ); // eslint-disable-line no-console
							closeCheckout();
						} );
				},
				onError: () => {
					closeCheckout();
				},
				onDismiss: () => {
					// Analytics: Track a dismissal event (modal has been manually closed without completing the checkout).
					manageDismissed( checkoutData );
					inCheckoutIntent = false;
					document.getElementById( 'newspack_modal_checkout' ).removeAttribute( 'data-checkout' );
				},
				skipSuccess: true,
				skipNewslettersSignup: true,
				// A payment that completes at checkout verifies the reader, so don't hold a new
				// reader at the "verify your email" step on the way there. One whose payment is
				// still pending (a check, a bank transfer) is asked to verify in My Account.
				skipVerification: true,
				labels: {
					signin: {
						title: newspackBlocksModal.labels.signin_modal_title,
					},
					register: {
						title: newspackBlocksModal.labels.register_modal_title,
					},
				},
				trigger: ev.submitter,
				closeOnSuccess: isModalCheckout,
			} );
		} else {
			// Otherwise initialize checkout.
			openCheckout();
			// Append product data info to the modal, so we can grab it for GA4 events outside of the iframe.
			document.getElementById( 'newspack_modal_checkout' ).setAttribute( 'data-checkout', JSON.stringify( checkoutData ) );
		}
		activeCheckoutForm = form;
	};

	/**
	 * Generate checkout page form.
	 *
	 * A form that goes directly to checkout in case the cart has already been
	 * created.
	 */
	const generateCheckoutPageForm = checkoutUrl => {
		const checkoutForm = document.createElement( 'form' );
		checkoutForm.method = 'POST';
		checkoutForm.action = checkoutUrl;
		checkoutForm.target = IFRAME_NAME;
		checkoutForm.style.display = 'none';

		const submitButton = document.createElement( 'button' );
		submitButton.setAttribute( 'type', 'submit' );

		checkoutForm.appendChild( submitButton );
		document.body.appendChild( checkoutForm );

		checkoutForm.addEventListener( 'submit', handleCheckoutFormSubmit );
		return checkoutForm;
	};

	const iframeResizeObserver = new ResizeObserver( entries => {
		if ( ! entries || ! entries.length ) {
			return;
		}
		if ( ! iframe.contentDocument ) {
			return;
		}
		const contentRect = entries[ 0 ].contentRect;
		if ( contentRect ) {
			const vh = 0.01 * Math.max( document.documentElement.clientHeight, window.innerHeight || 0 );
			const headerHeight = modalCheckout.querySelector( `.${ MODAL_CLASS_PREFIX }__header` )?.offsetHeight || 0;
			const maxHeight = 90 * vh - headerHeight;
			const contentHeight = contentRect.top + contentRect.bottom;
			const iframeHeight = Math.min( contentHeight, maxHeight );
			if ( iframeHeight === 0 ) {
				// If height is 0, hide iframe content instead of resizing to avoid layout shift.
				iframe.style.visibility = 'hidden';
				return;
			}
			// Match iframe and modal content heights to avoid inner iframe scollbar.
			modalContent.style.height = iframeHeight + 'px';
			iframe.style.height = iframeHeight + 'px';
		}
	} );

	const closeCheckout = () => {
		setCloseButtonsDisabled( closeButtons, false );
		const container = iframe?.contentDocument?.querySelector( `#${ IFRAME_CONTAINER_ID }` );
		const afterSuccessUrlInput = container?.querySelector( 'input[name="after_success_url"]' );
		const afterSuccessBehaviorInput = container?.querySelector( 'input[name="after_success_behavior"]' );
		const hasNewsletterPopup = document?.querySelector( '.newspack-newsletters-signup-modal' );

		const checkoutData = getCheckoutData( container?.querySelector( '#modal-checkout-product-details' ) );

		// Empty cart if checkout is not complete.
		if ( ! container?.checkoutComplete ) {
			emptyCart();
		}

		// Only close the modal if the iframe contentDocument is null, the checkout is not complete, or we are not redirecting.
		const shouldCloseModal = ! iframe.contentDocument || ! afterSuccessUrlInput || ! afterSuccessBehaviorInput || ! container?.checkoutComplete;
		if ( shouldCloseModal || hasNewsletterPopup ) {
			spinner.style.display = 'flex';
			// A payment abandoned mid-way would otherwise keep the next checkout from closing on Escape.
			spinner.removeAttribute( 'aria-busy' );
			if ( iframe && modalContent.contains( iframe ) ) {
				// Reset iframe and modal content heights.
				iframe._ready = false;
				iframe.src = 'about:blank';
				iframe.style.height = initialHeight;
				iframe.style.visibility = 'hidden';
				modalContent.style.height = initialHeight;
				modalContent.removeChild( iframe );
			}

			if ( iframeResizeObserver ) {
				iframeResizeObserver.disconnect();
			}

			document.querySelectorAll( `.${ MODAL_CLASS_PREFIX }-container` ).forEach( el => closeModal( el ) );

			if ( modalTrigger ) {
				modalTrigger.focus();
			}

			document.dispatchEvent( checkoutClosedEvent );
		}

		if ( container?.checkoutComplete ) {
			let isComplete = false;
			const handleCheckoutComplete = () => {
				// The signup modal can report twice: answered, then closed while the redirect loads.
				if ( isComplete ) {
					return;
				}
				isComplete = true;
				if ( afterSuccessUrlInput && afterSuccessBehaviorInput ) {
					const afterSuccessUrl = afterSuccessUrlInput.getAttribute( 'value' );
					const afterSuccessBehavior = afterSuccessBehaviorInput.getAttribute( 'value' );

					if ( 'custom' === afterSuccessBehavior ) {
						holdOverlay();
						window.location.href = afterSuccessUrl;
					} else if ( 'referrer' === afterSuccessBehavior ) {
						// window.history.back() is a no-op when there's no in-session
						// history entry (reader landed directly, opened in a new tab, or
						// the previous entry is cross-origin), stranding the reader with no
						// feedback. Prefer the same-origin document.referrer when we have
						// one, and fall back to history.back() otherwise.
						const referrer = document.referrer;
						let navigated = false;
						if ( referrer ) {
							try {
								if ( new URL( referrer ).origin === window.location.origin ) {
									holdOverlay();
									window.location.href = referrer;
									navigated = true;
								}
							} catch ( e ) {
								// Malformed referrer — fall through to history.back().
							}
						}
						if ( ! navigated ) {
							holdOverlay();
							window.history.back();
							// With no entry to go back to, the reader stays on this page: let go.
							setTimeout( releaseHeldOverlays, 1000 );
						}
					}
				}
				window?.newspackReaderActivation?.setPendingCheckout?.();
				inCheckoutIntent = false;
			};

			if ( checkoutData.action_type !== 'subscription_switch' && window?.newspackReaderActivation?.openNewslettersSignupModal ) {
				window.newspackReaderActivation.openNewslettersSignupModal( {
					onSuccess: handleCheckoutComplete,
					onError: handleCheckoutComplete,
					// Closing the signup modal ends the checkout the same way as answering it,
					// or the reader never reaches the post-checkout destination.
					onDismiss: handleCheckoutComplete,
					closeOnSuccess: shouldCloseModal,
				} );
			} else {
				handleCheckoutComplete();
			}

			// Ensure we always reset the modal title and width once the modal closes.
			if ( shouldCloseModal ) {
				checkoutTitle = newspackBlocksModal.labels.checkout_modal_title;
				setModalSize();
				setModalTitle( checkoutTitle );
			}
		} else {
			window?.newspackReaderActivation?.setPendingCheckout?.();
			// Analytics: Track a dismissal event (modal has been manually closed without completing the checkout).
			manageDismissed();
			inCheckoutIntent = false;
			document.getElementById( 'newspack_modal_checkout' ).removeAttribute( 'data-checkout' );
		}
	};

	const openCheckout = url => {
		if ( url ) {
			iframe.src = url;
		}

		spinner.style.display = 'flex';
		openModal( modalCheckout );
		modalContent.appendChild( iframe );
		modalCheckout.addEventListener( 'click', ev => {
			if ( ev.target === modalCheckout ) {
				closeCheckout();
			}
		} );
	};

	/**
	 * Move focus off the Close button the browser puts it on when a dialog opens,
	 * following newspack-ui's rule: first field, else first action, else the heading.
	 *
	 * @param {HTMLDialogElement} el The dialog.
	 */
	const focusOpenedDialog = el => {
		const content = el.querySelector( `.${ MODAL_CLASS_PREFIX }__content` );
		const heading = el.querySelector( `.${ MODAL_CLASS_PREFIX }__header h2` );
		const target = ( content && getInitialFocus( content ) ) || heading;
		if ( target === heading && heading ) {
			heading.tabIndex = -1;
		}
		target?.focus();
	};

	const openModal = el => {
		if ( window.newspackReaderActivation?.overlays ) {
			el.overlayId = window.newspackReaderActivation?.overlays.add();
		}
		el.setAttribute( 'data-state', 'open' );
		if ( OPENS_OWN_DIALOGS && ! el.open && typeof el.showModal === 'function' ) {
			el.showModal();
			focusOpenedDialog( el );
		}
		document.body.style.overflow = 'hidden';
	};

	/**
	 * Set the modal title.
	 *
	 * @param {string} title The title to set.
	 */
	const setModalTitle = title => {
		const modalTitle = modalCheckout.querySelector( `.${ MODAL_CLASS_PREFIX }__header h2` );
		if ( ! modalTitle ) {
			return;
		}

		modalTitle.innerText = title;
	};

	/**
	 * Sets the size of the modal.
	 *
	 * @param {string} size Options are 'small' or 'default'. Default is 'default'.
	 */
	const setModalSize = ( size = 'default' ) => {
		const modal = modalCheckout.querySelector( `.${ MODAL_CLASS_PREFIX }` );
		if ( ! modal ) {
			return;
		}

		if ( size === 'small' ) {
			modal.classList.add( `${ MODAL_CLASS_PREFIX }--small` );
		} else {
			modal.classList.remove( `${ MODAL_CLASS_PREFIX }--small` );
		}
	};

	/**
	 * Handle modal checkout close button.
	 */
	closeButtons.forEach( button => {
		button.addEventListener( 'click', ev => {
			ev.preventDefault();
			closeCheckout();
		} );
	} );

	/**
	 * Handle variations modal close button.
	 */
	document.querySelectorAll( '.newspack-blocks__modal-variation' ).forEach( variationModal => {
		variationModal.addEventListener( 'click', ev => {
			if ( ev.target === variationModal ) {
				closeCheckout();
			}
		} );
		variationModal.querySelectorAll( `.${ MODAL_CLASS_PREFIX }__close` ).forEach( button => {
			button.addEventListener( 'click', ev => {
				ev.preventDefault();
				closeCheckout();
			} );
		} );
	} );

	/**
	 * Escape closes checkout and the variations modal the way their Close buttons do,
	 * so the cart is emptied and the dismissal tracked.
	 */
	[ modalCheckout, ...document.querySelectorAll( `.${ VARIATON_MODAL_CLASS_PREFIX }` ) ].forEach( dialog => {
		dialog.addEventListener( 'cancel', ev => {
			if ( ev.target !== dialog ) {
				return;
			}
			ev.preventDefault();
			if ( ! dialog.querySelector( '[aria-busy="true"]' ) ) {
				closeCheckout();
			}
		} );
	} );

	// The browser can close the dialog itself, such as on a repeated Escape. A
	// payment in progress keeps checkout open; newspack-ui handles this for its dialogs.
	if ( OPENS_OWN_DIALOGS ) {
		modalCheckout.addEventListener( 'close', () => {
			if ( ! modalCheckout.open && 'true' === spinner.getAttribute( 'aria-busy' ) ) {
				modalCheckout.showModal();
				focusOpenedDialog( modalCheckout );
			}
		} );
	}

	/**
	 * Handle modal checkout triggers.
	 */
	document
		.querySelectorAll( '.wpbnbd.wpbnbd--platform-wc, .wp-block-newspack-blocks-checkout-button, .newspack-blocks__modal-variation' )
		.forEach( element => {
			const forms = element.querySelectorAll( 'form' );
			forms.forEach( form => {
				if ( ! newspackBlocksModal.has_unsupported_payment_gateway ) {
					form.prepend( modalCheckoutHiddenInput.cloneNode() );
				}
				form.target = IFRAME_NAME;
				form.addEventListener( 'submit', handleCheckoutFormSubmit );
			} );
		} );

	/**
	 * Query params a modal checkout URL trigger consumes (see
	 * handleModalCheckoutUrlParams). Only these are stripped after a successful
	 * trigger — unrelated params (UTMs, plain-permalink ids) and the hash
	 * survive.
	 */
	const CHECKOUT_TRIGGER_PARAMS = [ 'checkout', 'type', 'layout', 'frequency', 'amount', 'other', 'product_id', 'variation_id' ];

	/**
	 * Remove the modal checkout trigger params from the address bar.
	 */
	const stripCheckoutUrlParams = () => {
		const url = new URL( window.location.href );
		CHECKOUT_TRIGGER_PARAMS.forEach( param => url.searchParams.delete( param ) );
		window.history.replaceState( null, null, url.href );
	};

	/**
	 * Handle donation form triggers.
	 *
	 * Resolution (which form, which tier) is delegated to resolveDonationTrigger,
	 * which validates the URL params and never mutates the DOM, so a trigger that
	 * can't complete leaves the page untouched. Only the first form in DOM order
	 * that fully satisfies the trigger is acted on.
	 *
	 * @param {string}      layout    The donation layout.
	 * @param {string}      frequency The donation frequency.
	 * @param {string}      amount    The donation amount.
	 * @param {string|null} other     Optional. The custom amount when other is selected.
	 *
	 * @return {boolean} Whether a donation form was triggered.
	 */
	const triggerDonationForm = ( layout, frequency, amount, other = null ) => {
		const resolution = resolveDonationTrigger( document, { layout, frequency, amount } );
		const described = `layout "${ layout }", frequency "${ frequency }" and amount "${ amount }"`;
		if ( resolution.status === 'invalid-params' ) {
			// eslint-disable-next-line no-console
			console.warn( `Newspack modal checkout: invalid donate trigger parameters (${ described }). The checkout was not triggered.` );
			return false;
		}
		if ( resolution.status === 'no-match' ) {
			// eslint-disable-next-line no-console
			console.warn( `Newspack modal checkout: no donate form matches ${ described }. The checkout was not triggered.` );
			return false;
		}
		if ( resolution.status === 'not-ready' ) {
			// The tiersBased view script has not initialized this block yet —
			// modal.js and tiersBased.js load async in either order, and when
			// modal.js is first (typical on fast/cached loads, where execution
			// follows document order and modal.js is printed first) tab and tier
			// clicks would do nothing. Retry when the block announces readiness;
			// the listener re-arms if the matching block still isn't the one that
			// became ready. Returning false keeps the URL params (see
			// handleModalCheckoutUrlParams), so the retry — or a reload, if
			// readiness never comes — still has them; a successful retry strips
			// them here since the caller's pass is already over. Waiting is the
			// expected order on healthy loads, so nothing is logged unless
			// readiness never arrives.
			const readyWarningTimeout = setTimeout( () => {
				// eslint-disable-next-line no-console
				console.warn(
					`Newspack modal checkout: the donate form matching ${ described } never finished initializing. The checkout was not triggered; the URL params were kept so a reload can retry.`
				);
			}, 5000 );
			document.addEventListener(
				TIERS_BASED_READY_EVENT,
				() => {
					clearTimeout( readyWarningTimeout );
					if ( triggerDonationForm( layout, frequency, amount, other ) ) {
						stripCheckoutUrlParams();
					}
				},
				{ once: true }
			);
			return false;
		}
		const { form } = resolution;
		if ( resolution.status === 'tiered' ) {
			// Clicking the tab synchronously updates each tier submit button's
			// `name` and `value` attributes (see src/blocks/donate/tiers-based/view.ts);
			// resolution already verified the view script is initialized. Blocks
			// with a single enabled frequency render no tabs and need no click.
			if ( resolution.frequencyButton ) {
				resolution.frequencyButton.click();
			}
			const submitButton = form.querySelector( `button[type="submit"][data-tier-index="${ resolution.tierIndex }"]` );
			if ( ! submitButton ) {
				// eslint-disable-next-line no-console
				console.warn( `Newspack modal checkout: no tier submit button matches ${ described }. The checkout was not triggered.` );
				return false;
			}
			submitButton.click();
			return true;
		}
		resolution.frequencyInput.checked = true;
		if ( resolution.status === 'untiered' ) {
			resolution.amountInput.value = amount;
		} else if ( amount === 'other' ) {
			resolution.amountInput.click();
			const otherInput = form.querySelector( `input[name="donation_value_${ frequency }_other"]` );
			if ( otherInput && other ) {
				otherInput.value = other;
			}
		} else {
			resolution.amountInput.checked = true;
		}
		// requestSubmit runs the form's constraint validation (e.g. the untiered
		// amount input's `min`), and a refused submit would otherwise still be
		// reported as success — stripping the URL params of a link that did
		// nothing. Check first so failures stay visible and retryable.
		if ( ! form.checkValidity() ) {
			// eslint-disable-next-line no-console
			console.warn( `Newspack modal checkout: the donate form rejected the values for ${ described }. The checkout was not triggered.` );
			return false;
		}
		triggerFormSubmit( form );
		return true;
	};

	/**
	 * Handle checkout button URL triggers.
	 *
	 * @param {string}      productId   The product ID.
	 * @param {string|null} variationId Optional. The variation ID.
	 *
	 * @return {boolean} Whether a matching form was submitted.
	 */
	const triggerCheckoutButtonForm = ( productId, variationId = null ) => {
		const form = resolveCheckoutButtonForm( document, productId, variationId, {
			variationModalClassPrefix: VARIATON_MODAL_CLASS_PREFIX,
			iframeName: IFRAME_NAME,
		} );
		if ( form ) {
			// A page-authored form wins with its own context; say so when that
			// drops something the link carried, instead of applying list price
			// or the default thank-you behavior with no trace.
			const dropped = getDroppedLinkContext( form, window.location.search );
			if ( dropped.length ) {
				// eslint-disable-next-line no-console
				console.warn(
					`Newspack modal checkout: the resolved checkout form does not carry ${ dropped.join(
						', '
					) } from the URL. The page block's own settings apply instead.`
				);
			}
			triggerFormSubmit( form );
			return true;
		}
		const message =
			`Newspack modal checkout: no checkout form found for product_id "${ productId }"` +
			( variationId ? ` and variation_id "${ variationId }"` : '' ) +
			'. The checkout was not triggered.';
		// eslint-disable-next-line no-console
		console.warn( message );
		return false;
	};

	/**
	 * Handle modal checkout url param triggers.
	 */
	const handleModalCheckoutUrlParams = () => {
		const urlParams = new URLSearchParams( window.location.search );
		if ( ! urlParams.has( 'checkout' ) ) {
			return;
		}
		// Default to stripping the params after handling. The donate and
		// checkout button triggers override this so a link that fails to
		// trigger stays visible and diagnosable rather than being silently
		// dropped, and a reload can retry a transient failure.
		let shouldStripParams = true;
		const type = urlParams.get( 'type' );
		if ( type === 'donate' ) {
			const layout = urlParams.get( 'layout' );
			const frequency = urlParams.get( 'frequency' );
			const amount = urlParams.get( 'amount' );
			const other = urlParams.get( 'other' );
			if ( layout && frequency && amount ) {
				shouldStripParams = triggerDonationForm( layout, frequency, amount, other );
			} else {
				shouldStripParams = false;
				// eslint-disable-next-line no-console
				console.warn( 'Newspack modal checkout: donate trigger is missing layout, frequency or amount. The checkout was not triggered.' );
			}
		} else if ( type === 'checkout_button' ) {
			const productId = urlParams.get( 'product_id' );
			const variationId = urlParams.get( 'variation_id' );
			if ( productId ) {
				shouldStripParams = triggerCheckoutButtonForm( productId, variationId );
			} else {
				// A checkout_button trigger with no product_id cannot resolve a
				// form; keep the params visible rather than dropping them silently.
				shouldStripParams = false;
				// eslint-disable-next-line no-console
				console.warn( 'Newspack modal checkout: checkout_button trigger is missing product_id. The checkout was not triggered.' );
			}
		} else {
			const url = window.newspackReaderActivation?.getPendingCheckout?.();
			if ( url ) {
				const form = generateCheckoutPageForm( url );
				triggerFormSubmit( form );
			}
		}
		// Remove the trigger params to prevent re-triggering, but only when the
		// trigger succeeded.
		if ( shouldStripParams ) {
			stripCheckoutUrlParams();
		}
	};
	// A click reaches a form long after every script has loaded; the URL trigger
	// fires as soon as this bundle runs. This bundle is async and the reader
	// activation scripts are deferred, so firing here can beat them — and then
	// the checkout opens without the sign-in step and without registering as an
	// overlay, which lets prompts open on top of it. Deferred scripts are done
	// by DOMContentLoaded, so the trigger waits for that rather than queueing on
	// newspackRAS: that queue only flushes once newspack-plugin's reader
	// activation script runs, and the modal needs only WooCommerce, so on a site
	// without newspack-plugin a queued trigger would never fire. It then waits
	// for the reader's segment snapshot to reach the server: segmentation writes
	// it on this same page load, and pricing rules read it when the checkout
	// loads, so firing first would price against the previous visit's snapshot.
	afterDeferredScripts( () => {
		whenReaderDataSynced( 'matched_segments' ).then( handleModalCheckoutUrlParams );
	} );

	/**
	 * Open the modal checkout.
	 *
	 * @param {Object}   options                    Modal checkout options object.
	 * @param {string}   options.url                The URL to open the modal checkout on.
	 *                                              If not provided, the default checkout URL will be used.
	 * @param {string}   options.title              The title to set for the modal.
	 * @param {string}   options.actionType         The action type to set for the modal.
	 * @param {Object}   options.afterSuccess       The after success configuration object.
	 * @param {number}   options.quantity           Optional. Seats/quantity to purchase. Only sent when above 1.
	 * @param {Function} options.onCheckoutComplete The callback to call when the checkout is complete.
	 * @param {Function} options.onClose            The callback to call when the modal is closed.
	 */
	window.newspackOpenModalCheckout = ( {
		url = null,
		title = null,
		actionType = null,
		afterSuccess = {},
		quantity = null,
		// eslint-disable-next-line @typescript-eslint/no-shadow
		onCheckoutComplete = null,
		onClose = null,
	} ) => {
		// The dialog returns focus to whatever opened it, not to an earlier checkout's button.
		modalTrigger = null;

		/**
		 * Title configuration.
		 */
		checkoutTitle = title || newspackBlocksModal.labels.checkout_modal_title;
		// Set the modal title early, even though it may be overridden by the modal content.
		setModalTitle( checkoutTitle );

		/**
		 * Start with the default checkout URL.
		 */
		url = new URL( url || newspackBlocksModal.checkout_url );

		/**
		 * For integration purposes, remove `my_account_checkout` from the URL if it exists.
		 */
		if ( url.searchParams.has( 'my_account_checkout' ) ) {
			url.searchParams.delete( 'my_account_checkout' );
		}

		/**
		 * Add `modal_checkout` to the URL if it doesn't exist.
		 */
		if ( ! url.searchParams.has( 'modal_checkout' ) ) {
			url.searchParams.set( 'modal_checkout', '1' );
		}

		/**
		 * Custom action type configuration.
		 */
		if ( actionType ) {
			url.searchParams.set( 'action_type', actionType );
		}

		/**
		 * Seat/quantity configuration. Only sent above 1 — the server already
		 * defaults to a single seat, so there's nothing to add to the URL when
		 * the reader is buying just one.
		 */
		if ( quantity && parseInt( quantity, 10 ) > 1 ) {
			url.searchParams.set( 'quantity', String( parseInt( quantity, 10 ) ) );
		}

		/**
		 * After success parameters.
		 */
		if ( afterSuccess?.url ) {
			url.searchParams.set( 'after_success_url', afterSuccess.url );
		}
		if ( afterSuccess?.behavior || afterSuccess?.url ) {
			url.searchParams.set( 'after_success_behavior', afterSuccess.behavior || 'custom' );
		}
		if ( afterSuccess?.buttonLabel ) {
			url.searchParams.set( 'after_success_button_label', afterSuccess.buttonLabel );
		}

		/**
		 * On checkout complete callback.
		 */
		if ( onCheckoutComplete ) {
			const handleCheckoutComplete = ( { detail: { action, data } } ) => {
				if ( action !== 'checkout_completed' ) {
					return;
				}
				onCheckoutComplete( data );
			};
			window.newspackRAS.push( ras => {
				ras.on( 'activity', handleCheckoutComplete );
				// Unsubscribe from the checkout complete event when the modal is closed.
				const closeHandler = () => {
					ras.off( 'activity', handleCheckoutComplete );
					document.removeEventListener( 'checkout-closed', closeHandler );
				};
				document.addEventListener( 'checkout-closed', closeHandler );
			} );
		}

		/**
		 * On close callback.
		 */
		if ( onClose ) {
			const closeHandler = () => {
				onClose();
				document.removeEventListener( 'checkout-closed', closeHandler );
			};
			document.addEventListener( 'checkout-closed', closeHandler );
		}

		/**
		 * Open the modal checkout.
		 */
		openCheckout( url.toString() );
	};

	/**
	 * Close the modal checkout.
	 */
	window.newspackCloseModalCheckout = closeCheckout;
} );
