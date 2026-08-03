import { domReady } from '../utils';
import { queuePageReload } from './utils';

function handleCheckoutClose( completed ) {
	if ( ! completed ) {
		return;
	}
	queuePageReload();
}

window.newspackRAS = window.newspackRAS || [];

export default function init() {
	domReady( () => {
		const params = new URLSearchParams( window.location.search );
		const isSwitchingSubscription = params.get( 'upgrade-subscription' ) || params.get( 'switch' );

		// Remove modal query params from the URL.
		if ( params.get( 'upgrade-subscription' ) || params.get( 'tiers-modal' ) || params.get( 'switch' ) ) {
			const newParams = new URLSearchParams( params );
			newParams.delete( 'upgrade-subscription' );
			newParams.delete( 'tiers-modal' );
			newParams.delete( 'switch' );
			const newQueryString = newParams.toString() ? '?' + newParams.toString() : '';
			window.history.replaceState( {}, '', window.location.pathname + newQueryString );
		}

		// Handle authentication flow for switching subscriptions.
		window.newspackRAS.push( ras => {
			const reader = ras.getReader();
			if ( isSwitchingSubscription && ! reader?.authenticated ) {
				ras.openAuthModal( {
					labels: {
						signin: {
							title: window.newspack_reader_activation_labels.sign_in_to_upgrade,
						},
						register: {
							title: window.newspack_reader_activation_labels.register_to_upgrade,
						},
					},
					skipSuccess: true,
					skipNewslettersSignup: true,
					closeOnSuccess: false,
					onSuccess: () => {
						window.location.href = window.location.pathname + '?' + params.toString();
					},
				} );
			}
		} );

		const forms = document.querySelectorAll( '.newspack__subscription-tiers__form' );
		if ( ! forms.length ) {
			return;
		}

		[ ...forms ].forEach( form => {
			const modal = form.closest( '.newspack-ui__modal-container' );
			const submitButton = form.querySelector( 'button[type="submit"]' );
			const cancelButton = form.querySelector( '.newspack-ui__modal__cancel' );
			const isNYP = form.classList.contains( 'nyp' );
			const originalSubmitButtonText = submitButton.textContent;

			let isFormValid = false;

			const attachInputListeners = () => {
				const inputs = form.querySelectorAll( 'input[type="radio"], input[type="number"], select' );
				inputs.forEach( input => {
					input.addEventListener( 'input', handleChange );
					input.addEventListener( 'change', handleChange );
				} );
				handleChange();
			};

			const handleChange = () => {
				// Update submit label.
				if ( isNYP ) {
					const amountInput = form.querySelector( '#nyp_amount' );
					if ( amountInput?.value ) {
						const amountText = parseFloat( amountInput.value ).toLocaleString( document.documentElement.lang, {
							style: 'currency',
							currency: amountInput.dataset.currency,
							currencyDisplay: 'narrowSymbol',
						} );
						submitButton.textContent = originalSubmitButtonText + ': ' + amountText + ' / ' + amountInput.dataset.frequency;
					} else {
						submitButton.textContent = originalSubmitButtonText;
					}
				}

				// Validate inputs.
				if ( isNYP ) {
					const amountInput = form.querySelector( '#nyp_amount.current' );
					if ( amountInput && ( ! amountInput.value || amountInput.value === amountInput.dataset.originalValue ) ) {
						form.querySelector( 'button[type="submit"]' ).disabled = true;
						isFormValid = false;
					} else {
						form.querySelector( 'button[type="submit"]' ).disabled = false;
						isFormValid = true;
					}
				} else {
					const selected = form.querySelector( '.current input[type="radio"]:checked' );
					if ( selected ) {
						form.querySelector( 'button[type="submit"]' ).disabled = true;
						isFormValid = false;
					} else {
						form.querySelector( 'button[type="submit"]' ).disabled = false;
						isFormValid = true;
					}
				}
			};

			const control = form.querySelector( '.newspack-ui__segmented-control' );
			if ( control ) {
				control.addEventListener( 'content-selected', attachInputListeners );
			}
			attachInputListeners();

			handleChange();

			if ( modal ) {
				cancelButton.addEventListener( 'click', () => {
					modal?.setAttribute( 'data-state', 'closed' );
				} );
			} else {
				cancelButton.style.display = 'none';
			}

			form.addEventListener( 'submit', ev => {
				if ( ! isFormValid ) {
					ev.preventDefault();
					return;
				}

				// Bail if this is a variation modal from the Checkout Button block,
				// as it has its own form submission logic.
				if ( modal?.classList.contains( 'newspack-blocks__modal-variation' ) ) {
					return;
				}
				// Bail if the modal checkout API is not available.
				if ( ! window.newspackOpenModalCheckout ) {
					return;
				}
				ev.preventDefault();
				let completed = false;
				const formData = new FormData( form );
				modal?.setAttribute( 'data-state', 'closed' );
				const url = new URL( form.action );
				for ( const param of formData.entries() ) {
					url.searchParams.append( param[ 0 ], param[ 1 ] );
				}
				window.newspackOpenModalCheckout( {
					url: url.toString(),
					title: form.dataset.title,
					onCheckoutComplete: () => {
						completed = true;
					},
					onClose: () => handleCheckoutClose( completed ),
				} );
			} );

			const signinLink = form.querySelector( '.signin-link' );
			if ( signinLink ) {
				signinLink.addEventListener( 'click', ev => {
					ev.preventDefault();
					if ( modal ) {
						modal.setAttribute( 'data-state', 'closed' );
					}
					window.newspackRAS.push( ras => {
						ras.openAuthModal( {
							skipSuccess: true,
							skipNewslettersSignup: true,
							onSuccess: () => {
								// Append the 'tiers-modal' query param to the URL.
								const urlParams = new URLSearchParams( window.location.search );
								urlParams.set( 'tiers-modal', form.dataset.productId || '' );
								if ( isSwitchingSubscription ) {
									urlParams.set( 'switch', '1' );
								}
								window.location.href = window.location.pathname + '?' + urlParams.toString();
							},
							onDismiss: () => {
								if ( modal ) {
									modal.setAttribute( 'data-state', 'open' );
								}
							},
						} );
					} );
				} );
			}
		} );
	} );
}
