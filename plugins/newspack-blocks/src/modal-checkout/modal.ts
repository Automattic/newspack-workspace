/**
 * Style dependencies
 */
import './modal.scss';
import * as a11y from './accessibility';

/**
 * Internal dependencies
 */
import { manageDismissed, manageOpened } from './analytics';
import {
	domReady,
	iframeReady,
	onCheckoutReady,
	onCheckoutComplete,
	onCheckoutCancel,
	onCheckoutPlaceOrderStart,
	onCheckoutPlaceOrderError,
	onCheckoutPlaceOrderCriticalError,
	createHiddenInput,
	triggerFormSubmit,
	getCheckoutData,
	getFormattedAmount,
} from './utils';
import type { CheckoutContainer, CheckoutData, CheckoutIframe } from './utils';
import { resolveCheckoutButtonForm, readCheckoutData } from './checkout-button-trigger';

/**
 * PHP-injected checkout configuration global.
 */
declare const newspackBlocksModal: {
	newspack_class_prefix: string;
	processing_payment_messages: Array< { text: string; delay: number } >;
	ajax_url: string;
	checkout_url: string;
	has_unsupported_payment_gateway: boolean;
	is_registration_required: boolean;
	checkout_registration_flag: string;
	labels: {
		checkout_modal_title: string;
		thankyou_modal_title: string;
		critical_error: string;
		auth_modal_title: string;
		signin_modal_title: string;
		register_modal_title: string;
	};
};

/**
 * PHP-injected reader activation configuration global.
 */
declare const newspack_ras_config: { is_logged_in: boolean };

/**
 * The queue callback shape pushed onto `window.newspackRAS`.
 *
 * `ReaderActivation` and `RASActivity` (the Reader Activation runtime attached
 * to `window.newspackReaderActivation`, and its `activity` event payload) are
 * declared ambiently in `src/types`, so they are not re-declared here.
 */
type RASQueueEntry = [ string, unknown ] | ( ( ras: ReaderActivation ) => void );

/**
 * The modal checkout's own globals, attached to `window` by this script and
 * consumed elsewhere. `newspackReaderActivation` is declared ambiently in
 * `src/types`, so it is not re-declared here.
 */
declare global {
	interface Window {
		newspackRAS: RASQueueEntry[];
		newspackOpenModalCheckout: ( options: ModalCheckoutOptions ) => void;
		newspackCloseModalCheckout: () => void;
	}
}

/**
 * Options accepted by `window.newspackOpenModalCheckout`.
 */
interface ModalCheckoutOptions {
	url?: string | null;
	title?: string | null;
	actionType?: string | null;
	afterSuccess?: { url?: string; behavior?: string; buttonLabel?: string };
	onCheckoutComplete?: ( ( data: unknown ) => void ) | null;
	onClose?: ( () => void ) | null;
}

/**
 * A DOM element augmented with the modal checkout's per-element bookkeeping.
 */
interface ModalElement extends HTMLElement {
	overlayId?: string;
	initialErrors?: string;
	checkout_nonce?: string | null;
}

const CLASS_PREFIX = newspackBlocksModal.newspack_class_prefix;
const IFRAME_NAME = 'newspack_modal_checkout_iframe';
const IFRAME_CONTAINER_ID = 'newspack_modal_checkout_container';
const MODAL_CHECKOUT_ID = 'newspack_modal_checkout';
const MODAL_CLASS_PREFIX = `${ CLASS_PREFIX }__modal`;
const VARIATON_MODAL_CLASS_PREFIX = 'newspack-blocks__modal-variation';
const PROCESSING_PAYMENT_TEXT_CLASS = `${ CLASS_PREFIX }__processing-payment-text`;
const PROCESSING_PAYMENT_MESSAGES = newspackBlocksModal.processing_payment_messages;

// Track the checkout intent to avoid multiple analytics events.
let inCheckoutIntent = false;

// Checkout title.
let checkoutTitle = newspackBlocksModal.labels.checkout_modal_title;

// Last-submitted checkout form.
let activeCheckoutForm: HTMLFormElement | null = null;

// Close the modal.
const closeModal = ( el: ModalElement ) => {
	if ( el.overlayId && window.newspackReaderActivation?.overlays ) {
		window.newspackReaderActivation?.overlays.remove( el.overlayId );
	}
	el.setAttribute( 'data-state', 'closed' );
	document.body.style.overflow = 'auto';
};

// Cleanup if page is loaded via back button.
window.onpageshow = event => {
	if ( event.persisted ) {
		// If the page is loaded from the back button, find and remove any loading-related classes and modals:
		document.querySelectorAll( '.modal-processing' ).forEach( el => el.classList.remove( 'modal-processing' ) );
		document.querySelectorAll( '.non-modal-checkout-loading' ).forEach( el => el.classList.remove( 'non-modal-checkout-loading' ) );
		document.querySelectorAll< HTMLElement >( `.${ MODAL_CLASS_PREFIX }-container` ).forEach( el => closeModal( el ) );
	}
};

// Register the "checkout closed" event.
const checkoutClosedEvent = new CustomEvent( 'checkout-closed' );

window.newspackRAS = window.newspackRAS || [];

domReady( () => {
	const modalCheckout = document.querySelector( `#${ MODAL_CHECKOUT_ID }` ) as ModalElement;
	if ( ! modalCheckout ) {
		return;
	}

	const modalContent = modalCheckout.querySelector( `.${ MODAL_CLASS_PREFIX }__content` ) as HTMLElement;
	const modalCheckoutHiddenInput = createHiddenInput( 'modal_checkout', '1' );
	const spinner = modalContent.querySelector( `.${ CLASS_PREFIX }__spinner` ) as HTMLElement;
	let modalTrigger: HTMLElement | null | undefined = (
		document.querySelector( '.newspack-reader__account-link' ) as ArrayLike< HTMLElement > | null
	 )?.[ 0 ];
	// Initialize empty iframe.
	const initialHeight = '600px'; // Fixed initial height to avoid too much layout shift.
	const iframe: CheckoutIframe = document.createElement( 'iframe' );
	iframe.name = IFRAME_NAME;
	iframe.style.height = initialHeight;
	iframe.style.visibility = 'hidden';

	/**
	 * Set the modal as ready.
	 *
	 * @param {Object} container The container element inside the iframe document.
	 */
	const setModalReady = ( container: CheckoutContainer ) => {
		iframeResizeObserver.observe( container );
		if ( spinner.style.display !== 'none' ) {
			spinner.style.display = 'none';
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
				get: ( searchParams, prop ) => searchParams.get( prop as string ),
			} ) as { email?: string };
			if ( params.email ) {
				ras.setReaderEmail( params.email );
				ras.setAuthenticated( true );
			}
		}
		const container = iframe?.contentDocument?.querySelector< CheckoutContainer >( `#${ IFRAME_CONTAINER_ID }` );
		if ( ! container ) {
			return;
		}

		const productDetails = container.querySelector< HTMLElement >( '#modal-checkout-product-details' );
		const checkoutData = getCheckoutData( productDetails );

		const processingPaymentText = document.createElement( 'p' );
		processingPaymentText.classList.add( PROCESSING_PAYMENT_TEXT_CLASS );
		let processingPaymentTimeouts: ReturnType< typeof setTimeout >[] = [];

		const clearProcessingPaymentTimeouts = () => {
			processingPaymentTimeouts.forEach( timeoutId => clearTimeout( timeoutId ) );
			processingPaymentTimeouts = [];
		};

		const renderProcessingPaymentScreen = ( event?: Event ) => {
			spinner.querySelectorAll( `.${ PROCESSING_PAYMENT_TEXT_CLASS }` ).forEach( node => node.remove() );
			spinner.style.display = 'flex';
			clearProcessingPaymentTimeouts();
			processingPaymentText.textContent = PROCESSING_PAYMENT_MESSAGES[ 0 ]?.text ?? '';
			PROCESSING_PAYMENT_MESSAGES.slice( 1 ).forEach( ( { text, delay } ) => {
				const timeoutId = setTimeout( () => {
					event!.target!.dispatchEvent( new CustomEvent( 'checkout-place-order-processing' ) );
					processingPaymentText.textContent = text;
				}, delay );
				processingPaymentTimeouts.push( timeoutId );
			} );
			spinner.appendChild( processingPaymentText );
		};

		const hideProcessingPaymentScreen = () => {
			spinner.style.display = 'none';
			clearProcessingPaymentTimeouts();
			spinner.querySelectorAll( `.${ PROCESSING_PAYMENT_TEXT_CLASS }` ).forEach( node => node.remove() );
		};

		onCheckoutCancel( container, () => {
			closeCheckout();
		} );

		onCheckoutPlaceOrderStart( container, renderProcessingPaymentScreen );

		onCheckoutPlaceOrderError( container, hideProcessingPaymentScreen );

		onCheckoutReady( container, () => {
			// Make sure the order summary renders the correct text.
			const summaryTextNode = productDetails?.querySelector( 'strong' );
			if ( summaryTextNode ) {
				summaryTextNode.textContent = checkoutData.price_summary;
			}

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
			// The iframe's content window is a different page context (the checkout page
			// template) that exposes its own `newspackBlocksModalCheckout` global; that
			// page's script isn't part of this build, so its window type is narrowed here
			// rather than merged into this script's own ambient `Window`.
			const iframeWindow = iframe.contentWindow as ( Window & { newspackBlocksModalCheckout?: { checkout_nonce?: string | null } } ) | null;
			if ( iframeWindow?.newspackBlocksModalCheckout?.checkout_nonce ) {
				// Store the checkout nonce for later use.
				// We store the nonce from the iframe content window to ensure the nonce was generated for a logged in session
				modalCheckout.checkout_nonce = iframeWindow.newspackBlocksModalCheckout.checkout_nonce;
			}
			setModalReady( container );
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
			a11y.trapFocus( modalCheckout.querySelector< HTMLElement >( `.${ MODAL_CLASS_PREFIX }` )! );

			hideProcessingPaymentScreen();
		} );

		// Resubmit modal checkout form if an unrecoverable error is encountered.
		const refreshCheckout = ( form: HTMLFormElement | null ) => {
			if ( ! form ) {
				return;
			}
			closeCheckout();
			spinner.style.display = 'none';
			modalCheckout.initialErrors = newspackBlocksModal.labels.critical_error;
			form.requestSubmit( form.querySelector< HTMLElement >( 'button[type="submit"]' ) );
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
	const generateCart = ( checkoutData: CheckoutData ): Promise< string > => {
		return new Promise< string >( ( resolve, reject ) => {
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
		body.append( '_wpnonce', modalCheckout.checkout_nonce as string );
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

	/**
	 * Handle checkout form submit.
	 *
	 * @param {Event} ev
	 */
	const handleCheckoutFormSubmit = ( ev: SubmitEvent ) => {
		const isModalCheckout = ! newspackBlocksModal.has_unsupported_payment_gateway;
		if ( ! isModalCheckout ) {
			ev.preventDefault();
		}
		const form = ev.target as HTMLFormElement;
		form.classList.add( 'modal-processing' );

		const checkoutData = getCheckoutData( form );

		const isDonateBlock = checkoutData.newspack_donate;
		if ( isDonateBlock ) {
			const frequency = checkoutData.donation_frequency;
			const donationTiers = [
				...form.querySelectorAll< HTMLElement >( `.donation-tier__${ frequency }, .donation-frequency__${ frequency }` ),
			];
			const donationTierIndex = checkoutData.donation_tier_index;
			let donationContainer, customAmount;
			if ( donationTierIndex ) {
				donationContainer = donationTiers[ Number( donationTierIndex ) ];
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
		const variationModals = document.querySelectorAll< HTMLElement >( `.${ VARIATON_MODAL_CLASS_PREFIX }` );
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
				variationModal.querySelectorAll< HTMLElement >( `form[target="${ IFRAME_NAME }"]` ).forEach( singleVariationForm => {
					// Fill in the hidden params in the variation modal.
					[
						'after_success_behavior',
						'after_success_url',
						'after_success_button_label',
						'gate_post_id',
						'newspack_popup_id',
						'prompt_title',
					].forEach( hiddenParam => {
						const existingInputs = singleVariationForm.querySelectorAll( 'input[name="' + hiddenParam + '"]' );
						if ( 0 === existingInputs.length ) {
							singleVariationForm.prepend( createHiddenInput( hiddenParam, checkoutData[ hiddenParam ] ) );
						}
					} );

					// Append the product data hidden inputs.
					const data = readCheckoutData( singleVariationForm );
					if ( data ) {
						Object.keys( data ).forEach( key => {
							const existingInputs = singleVariationForm.querySelectorAll( 'input[name="' + key + '"]' );
							if ( 0 === existingInputs.length ) {
								singleVariationForm.prepend( createHiddenInput( key, data[ key ] as string | null ) );
							}
						} );
					}
				} );

				// Open the variations modal.
				ev.preventDefault();
				form.classList.remove( 'modal-processing' );
				openModal( variationModal );
				a11y.trapFocus( variationModal, false );

				// For the variation modal we will not set `inCheckoutIntent = true` and
				// let the `opened` event get triggered once the user selects a
				// variation so we track the selection.
				if ( ! inCheckoutIntent ) {
					manageOpened( checkoutData );
				}

				// Append product data info to the modal itself, so we can grab it for manageDismissed:
				document.getElementById( 'newspack_modal_checkout' )!.setAttribute( 'data-checkout', JSON.stringify( checkoutData ) );
				return;
			}
		}

		// Populate cart and redirect to checkout if there is an unsupported payment gateway.
		if ( ! isModalCheckout && ! shouldPromptRegistration() ) {
			generateCart( checkoutData ).then( url => {
				// Remove modal checkout query string and trailing question mark (if any).
				window.location.href = url;
			} );
			// Add some animation to the Checkout Button while the non-modal checkout is loading.
			// For now, don't do it when any popup opens, just when we go right to the checkout page.
			if ( ! ( checkoutData.is_variable && ! checkoutData.variation_id ) ) {
				const buttons = form.querySelectorAll( 'button[type=submit]:focus' );
				buttons.forEach( button => {
					button.classList.add( 'non-modal-checkout-loading' );
					const buttonText = button.innerHTML;
					button.innerHTML = '<span>' + buttonText + '</span>';
				} );
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

			const priceSummary = checkoutData.price_summary;
			const content = priceSummary
				? `<div class="order-details-summary ${ CLASS_PREFIX }__box ${ CLASS_PREFIX }__box--text-center"><p><strong>${ priceSummary }</strong></p></div>`
				: '';

			// Generate cart asynchroneously.
			const cartReq = generateCart( checkoutData );

			// Update pending checkout URL.
			cartReq.then( url => {
				window.newspackReaderActivation?.setPendingCheckout?.( url );
			} );
			// Initialize auth flow if reader is not authenticated. `shouldPromptRegistration()`
			// (checked above) already verifies `window.newspackReaderActivation.openAuthModal`
			// is present; TS can't carry that narrowing across the function-call boundary.
			window.newspackReaderActivation!.openAuthModal( {
				title: newspackBlocksModal.labels.auth_modal_title,
				onSuccess: ( message, authData ) => {
					cartReq
						.then( url => {
							// If registered and in a modal checkout, append the registration flag query param to the url.
							if ( authData?.registered && isModalCheckout ) {
								url += `&${ newspackBlocksModal.checkout_registration_flag }=1`;
							}
							// Populate cart and redirect to checkout if there is an unsupported payment gateway.
							if ( ! isModalCheckout ) {
								// Remove modal checkout query string, and trailing question mark (if any).
								// NOTE (pre-existing): this passes an already-evaluated value, not a
								// callback, to `.then()`. The assignment runs synchronously as this
								// expression is evaluated, so the redirect does not actually wait for
								// generateCart() to resolve, and `.then()` here is a no-op (calling it
								// with a non-function value is valid JS but never invokes it as a
								// handler). Preserved as-is since fixing it would change the redirect's
								// timing; flagged here rather than silently changed.
								generateCart( checkoutData ).then( ( window.location.href = url ) as any ); // eslint-disable-line @typescript-eslint/no-explicit-any
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
					document.getElementById( 'newspack_modal_checkout' )!.removeAttribute( 'data-checkout' );
				},
				skipSuccess: true,
				skipNewslettersSignup: true,
				labels: {
					signin: {
						title: newspackBlocksModal.labels.signin_modal_title,
					},
					register: {
						title: newspackBlocksModal.labels.register_modal_title,
					},
				},
				content,
				trigger: ev.submitter,
				closeOnSuccess: isModalCheckout,
			} );
		} else {
			// Otherwise initialize checkout.
			openCheckout();
			// Append product data info to the modal, so we can grab it for GA4 events outside of the iframe.
			document.getElementById( 'newspack_modal_checkout' )!.setAttribute( 'data-checkout', JSON.stringify( checkoutData ) );
		}
		activeCheckoutForm = form;
	};

	/**
	 * Generate checkout page form.
	 *
	 * A form that goes directly to checkout in case the cart has already been
	 * created.
	 */
	const generateCheckoutPageForm = ( checkoutUrl: string ) => {
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
			const headerHeight = modalCheckout.querySelector< HTMLElement >( `.${ MODAL_CLASS_PREFIX }__header` )?.offsetHeight || 0;
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
		const container = iframe?.contentDocument?.querySelector< CheckoutContainer >( `#${ IFRAME_CONTAINER_ID }` );
		const afterSuccessUrlInput = container?.querySelector( 'input[name="after_success_url"]' );
		const afterSuccessBehaviorInput = container?.querySelector( 'input[name="after_success_behavior"]' );
		const hasNewsletterPopup = document?.querySelector( '.newspack-newsletters-signup-modal' );

		const checkoutData = getCheckoutData( container?.querySelector< HTMLElement >( '#modal-checkout-product-details' ) );

		// Empty cart if checkout is not complete.
		if ( ! container?.checkoutComplete ) {
			emptyCart();
		}

		// Only close the modal if the iframe contentDocument is null, the checkout is not complete, or we are not redirecting.
		const shouldCloseModal = ! iframe.contentDocument || ! afterSuccessUrlInput || ! afterSuccessBehaviorInput || ! container?.checkoutComplete;
		if ( shouldCloseModal || hasNewsletterPopup ) {
			spinner.style.display = 'flex';
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

			document.querySelectorAll< HTMLElement >( `.${ MODAL_CLASS_PREFIX }-container` ).forEach( el => closeModal( el ) );

			if ( modalTrigger ) {
				modalTrigger.focus();
			}

			document.dispatchEvent( checkoutClosedEvent );
		}

		if ( container?.checkoutComplete ) {
			const handleCheckoutComplete = () => {
				if ( afterSuccessUrlInput && afterSuccessBehaviorInput ) {
					const afterSuccessUrl = afterSuccessUrlInput.getAttribute( 'value' );
					const afterSuccessBehavior = afterSuccessBehaviorInput.getAttribute( 'value' );

					if ( 'custom' === afterSuccessBehavior ) {
						window.location.href = afterSuccessUrl!;
					} else if ( 'referrer' === afterSuccessBehavior ) {
						window.history.back();
					}
				}
				window?.newspackReaderActivation?.setPendingCheckout?.();
				inCheckoutIntent = false;
			};

			if ( checkoutData.action_type !== 'subscription_switch' && window?.newspackReaderActivation?.openNewslettersSignupModal ) {
				window.newspackReaderActivation.openNewslettersSignupModal( {
					onSuccess: handleCheckoutComplete,
					onError: handleCheckoutComplete,
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
			document.getElementById( 'newspack_modal_checkout' )!.removeAttribute( 'data-checkout' );
		}
		document.removeEventListener( 'keydown', handleKeydown );
	};

	const openCheckout = ( url?: string ) => {
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

		a11y.trapFocus( modalCheckout, iframe );

		document.addEventListener( 'keydown', handleKeydown );
	};

	const openModal = ( el: ModalElement ) => {
		if ( window.newspackReaderActivation?.overlays ) {
			el.overlayId = window.newspackReaderActivation?.overlays.add();
		}
		el.setAttribute( 'data-state', 'open' );
		document.body.style.overflow = 'hidden';
	};

	/**
	 * Set the modal title.
	 *
	 * @param {string} title The title to set.
	 */
	const setModalTitle = ( title: string ) => {
		const modalTitle = modalCheckout.querySelector< HTMLElement >( `.${ MODAL_CLASS_PREFIX }__header h2` );
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
	modalCheckout.querySelectorAll( `.${ MODAL_CLASS_PREFIX }__close` ).forEach( button => {
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
	 * Escape key handler to close the modal checkout.
	 */
	const handleKeydown = ( ev: KeyboardEvent ) => {
		if ( ev.key === 'Escape' ) {
			closeCheckout();
		}
	};

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
	 * Handle donation form triggers.
	 *
	 * @param {string}      layout    The donation layout.
	 * @param {string}      frequency The donation frequency.
	 * @param {string}      amount    The donation amount.
	 * @param {string|null} other     Optional. The custom amount when other is selected.
	 */
	const triggerDonationForm = ( layout: string, frequency: string, amount: string, other: string | null = null ) => {
		let form: HTMLFormElement | undefined;
		document.querySelectorAll< HTMLFormElement >( '.wpbnbd.wpbnbd--platform-wc form' ).forEach( donationForm => {
			const frequencyInput = donationForm.querySelector< HTMLInputElement >( `input[name="donation_frequency"][value="${ frequency }"]` );
			if ( ! frequencyInput ) {
				return;
			}
			if ( layout === 'tiered' ) {
				const frequencyButton = document.querySelector< HTMLElement >( `button[data-frequency-slug="${ frequency }"]` );
				if ( ! frequencyButton ) {
					return;
				}
				frequencyButton.click();
				const submitButton = donationForm.querySelector< HTMLElement >(
					`button[type="submit"][name="donation_value_${ frequency }"][value="${ amount }"]`
				);
				if ( ! submitButton ) {
					return;
				}
				submitButton.click();
			} else {
				const amountInput =
					layout === 'untiered'
						? donationForm.querySelector< HTMLInputElement >( `input[name="donation_value_${ frequency }_untiered"]` )
						: donationForm.querySelector< HTMLInputElement >( `input[name="donation_value_${ frequency }"][value="${ amount }"]` );
				if ( frequencyInput && amountInput ) {
					frequencyInput.checked = true;
					if ( layout === 'untiered' ) {
						amountInput.value = amount;
					} else if ( amount === 'other' ) {
						amountInput.click();
						const otherInput = donationForm.querySelector< HTMLInputElement >( `input[name="donation_value_${ frequency }_other"]` );
						if ( otherInput && other ) {
							otherInput.value = other;
						}
					} else {
						amountInput.checked = true;
					}
					form = donationForm;
				}
			}
		} );
		if ( form ) {
			triggerFormSubmit( form );
		}
	};

	/**
	 * Handle checkout button URL triggers.
	 *
	 * @param {string}      productId   The product ID.
	 * @param {string|null} variationId Optional. The variation ID.
	 *
	 * @return {boolean} Whether a matching form was submitted.
	 */
	const triggerCheckoutButtonForm = ( productId: string, variationId: string | null = null ) => {
		const form = resolveCheckoutButtonForm( document, productId, variationId, {
			variationModalClassPrefix: VARIATON_MODAL_CLASS_PREFIX,
			iframeName: IFRAME_NAME,
		} );
		if ( form ) {
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
		// Default to stripping the params after handling. The checkout button
		// trigger overrides this so a link that matches no form stays visible
		// and diagnosable rather than being silently dropped.
		let shouldStripParams = true;
		const type = urlParams.get( 'type' );
		if ( type === 'donate' ) {
			const layout = urlParams.get( 'layout' );
			const frequency = urlParams.get( 'frequency' );
			const amount = urlParams.get( 'amount' );
			const other = urlParams.get( 'other' );
			if ( layout && frequency && amount ) {
				triggerDonationForm( layout, frequency, amount, other );
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
		// Remove the URL params to prevent re-triggering, but only when the
		// trigger succeeded.
		if ( shouldStripParams ) {
			// The second ("unused"/title) argument is vestigial and ignored by all browsers;
			// an empty string is the standard placeholder (see MDN), typed as `string` unlike
			// the `null` this originally passed.
			window.history.replaceState( null, '', window.location.pathname );
		}
	};
	handleModalCheckoutUrlParams();

	/**
	 * Open the modal checkout.
	 *
	 * @param {Object}   options                    Modal checkout options object.
	 * @param {string}   options.url                The URL to open the modal checkout on.
	 *                                              If not provided, the default checkout URL will be used.
	 * @param {string}   options.title              The title to set for the modal.
	 * @param {string}   options.actionType         The action type to set for the modal.
	 * @param {Object}   options.afterSuccess       The after success configuration object.
	 * @param {Function} options.onCheckoutComplete The callback to call when the checkout is complete.
	 * @param {Function} options.onClose            The callback to call when the modal is closed.
	 */
	window.newspackOpenModalCheckout = ( {
		url = null,
		title = null,
		actionType = null,
		afterSuccess = {},
		// eslint-disable-next-line @typescript-eslint/no-shadow
		onCheckoutComplete = null,
		onClose = null,
	} ) => {
		/**
		 * Title configuration.
		 */
		checkoutTitle = title || newspackBlocksModal.labels.checkout_modal_title;
		// Set the modal title early, even though it may be overridden by the modal content.
		setModalTitle( checkoutTitle );

		/**
		 * Start with the default checkout URL.
		 *
		 * A separate variable from the `url` option: that one is `string | null`, this
		 * is the parsed `URL` built from it.
		 */
		const checkoutUrl = new URL( url || newspackBlocksModal.checkout_url );

		/**
		 * For integration purposes, remove `my_account_checkout` from the URL if it exists.
		 */
		if ( checkoutUrl.searchParams.has( 'my_account_checkout' ) ) {
			checkoutUrl.searchParams.delete( 'my_account_checkout' );
		}

		/**
		 * Add `modal_checkout` to the URL if it doesn't exist.
		 */
		if ( ! checkoutUrl.searchParams.has( 'modal_checkout' ) ) {
			checkoutUrl.searchParams.set( 'modal_checkout', '1' );
		}

		/**
		 * Custom action type configuration.
		 */
		if ( actionType ) {
			checkoutUrl.searchParams.set( 'action_type', actionType );
		}

		/**
		 * After success parameters.
		 */
		if ( afterSuccess?.url ) {
			checkoutUrl.searchParams.set( 'after_success_url', afterSuccess.url );
		}
		if ( afterSuccess?.behavior || afterSuccess?.url ) {
			checkoutUrl.searchParams.set( 'after_success_behavior', afterSuccess.behavior || 'custom' );
		}
		if ( afterSuccess?.buttonLabel ) {
			checkoutUrl.searchParams.set( 'after_success_button_label', afterSuccess.buttonLabel );
		}

		/**
		 * On checkout complete callback.
		 */
		if ( onCheckoutComplete ) {
			const handleCheckoutComplete = ( { detail: { action, data } }: RASActivity ) => {
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
		openCheckout( checkoutUrl.toString() );
	};

	/**
	 * Close the modal checkout.
	 */
	window.newspackCloseModalCheckout = closeCheckout;
} );
