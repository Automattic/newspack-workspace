/* globals newspackBlocksModal, newspack_ras_config */

/**
 * Style dependencies
 */
import './modal.scss';
import * as a11y from './accessibility.js';

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
let activeCheckoutForm = null;

// Close the modal.
const closeModal = el => {
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
		document.querySelectorAll( `.${ MODAL_CLASS_PREFIX }-container` ).forEach( el => closeModal( el ) );
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
			if ( iframe.contentWindow?.newspackBlocksModalCheckout?.checkout_nonce ) {
				// Store the checkout nonce for later use.
				// We store the nonce from the iframe content window to ensure the nonce was generated for a logged in session
				modalCheckout.checkout_nonce = iframe.contentWindow.newspackBlocksModalCheckout.checkout_nonce;
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
			a11y.trapFocus( modalCheckout.querySelector( `.${ MODAL_CLASS_PREFIX }` ) );

			hideProcessingPaymentScreen();
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
		form.classList.add( 'modal-processing' );

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
					const variationData = singleVariationForm.dataset.checkout;
					if ( variationData ) {
						const data = JSON.parse( variationData );
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
				a11y.trapFocus( variationModal, false );

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
			// Initialize auth flow if reader is not authenticated.
			window.newspackReaderActivation.openAuthModal( {
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
			const handleCheckoutComplete = () => {
				if ( afterSuccessUrlInput && afterSuccessBehaviorInput ) {
					const afterSuccessUrl = afterSuccessUrlInput.getAttribute( 'value' );
					const afterSuccessBehavior = afterSuccessBehaviorInput.getAttribute( 'value' );

					if ( 'custom' === afterSuccessBehavior ) {
						window.location.href = afterSuccessUrl;
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
			document.getElementById( 'newspack_modal_checkout' ).removeAttribute( 'data-checkout' );
		}
		document.removeEventListener( 'keydown', handleKeydown );
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

		a11y.trapFocus( modalCheckout, iframe );

		document.addEventListener( 'keydown', handleKeydown );
	};

	const openModal = el => {
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
	const handleKeydown = ev => {
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
	const triggerDonationForm = ( layout, frequency, amount, other = null ) => {
		let form;
		document.querySelectorAll( '.wpbnbd.wpbnbd--platform-wc form' ).forEach( donationForm => {
			const frequencyInput = donationForm.querySelector( `input[name="donation_frequency"][value="${ frequency }"]` );
			if ( ! frequencyInput ) {
				return;
			}
			if ( layout === 'tiered' ) {
				const frequencyButton = document.querySelector( `button[data-frequency-slug="${ frequency }"]` );
				if ( ! frequencyButton ) {
					return;
				}
				frequencyButton.click();
				const submitButton = donationForm.querySelector( `button[type="submit"][name="donation_value_${ frequency }"][value="${ amount }"]` );
				if ( ! submitButton ) {
					return;
				}
				submitButton.click();
			} else {
				const amountInput =
					layout === 'untiered'
						? donationForm.querySelector( `input[name="donation_value_${ frequency }_untiered"]` )
						: donationForm.querySelector( `input[name="donation_value_${ frequency }"][value="${ amount }"]` );
				if ( frequencyInput && amountInput ) {
					frequencyInput.checked = true;
					if ( layout === 'untiered' ) {
						amountInput.value = amount;
					} else if ( amount === 'other' ) {
						amountInput.click();
						const otherInput = donationForm.querySelector( `input[name="donation_value_${ frequency }_other"]` );
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
	 * Handle checkout button form triggers.
	 *
	 * @param {number}      productId   The product ID.
	 * @param {number|null} variationId Optional. The variation ID.
	 */
	const triggerCheckoutButtonForm = ( productId, variationId = null ) => {
		let form;
		if ( variationId && variationId !== productId ) {
			const variationModals = document.querySelectorAll( `.${ VARIATON_MODAL_CLASS_PREFIX }` );
			const variationModal = [ ...variationModals ].find( modal => modal.dataset.productId === productId );
			if ( variationModal ) {
				const forms = variationModal.querySelectorAll( `form[target="${ IFRAME_NAME }"]` );
				forms.forEach( variationForm => {
					const productData = JSON.parse( variationForm.dataset.checkout );
					if ( productData?.variation_id === Number( variationId ) ) {
						form = variationForm;
					}
				} );
			}
		} else {
			const checkoutButtons = document.querySelectorAll( '.wp-block-newspack-blocks-checkout-button' );
			checkoutButtons.forEach( button => {
				const checkoutButtonForm = button.querySelector( 'form' );
				if ( ! checkoutButtonForm ) {
					return;
				}
				const productData = JSON.parse( checkoutButtonForm.dataset.checkout );
				if ( productData?.product_id === productId ) {
					form = checkoutButtonForm;
				}
			} );
		}
		if ( form ) {
			triggerFormSubmit( form );
		}
	};

	/**
	 * Handle modal checkout url param triggers.
	 */
	const handleModalCheckoutUrlParams = () => {
		const urlParams = new URLSearchParams( window.location.search );
		if ( ! urlParams.has( 'checkout' ) ) {
			return;
		}
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
				triggerCheckoutButtonForm( productId, variationId );
			}
		} else {
			const url = window.newspackReaderActivation?.getPendingCheckout?.();
			if ( url ) {
				const form = generateCheckoutPageForm( url );
				triggerFormSubmit( form );
			}
		}
		// Remove the URL param to prevent re-triggering.
		window.history.replaceState( null, null, window.location.pathname );
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
