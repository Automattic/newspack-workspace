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

			// Seat bounds belong to the tier being bought, and each tier's radio
			// publishes its own. The single seats field follows whichever one is
			// checked: shown and bounded for a per-seat tier, hidden and disabled
			// otherwise — and a disabled input submits nothing, so a flat tier sends no
			// seat count at all. Without that, its price would be billed per seat.
			const seatsField = form.querySelector( '.newspack__subscription-tiers__seats' );
			// By name, not by id: one page can hold several switch modals, each with
			// its own seats field, and the id is unique per form for that reason.
			const seatsInput = seatsField?.querySelector( 'input[name="quantity"]' );
			let seatsTierId = null;
			const syncSeatsToTier = () => {
				if ( ! seatsField || ! seatsInput ) {
					return;
				}
				const tier = form.querySelector( 'input[name="product_id"]:checked' );
				const tierChanged = ( tier?.value || '' ) !== seatsTierId;
				seatsTierId = tier?.value || '';
				if ( ! tier?.dataset.perSeat ) {
					seatsField.hidden = true;
					seatsInput.disabled = true;
					return;
				}
				seatsField.hidden = false;
				seatsInput.disabled = false;
				// The plan's own minimum, raised to the seats already in use: a group can
				// never shrink below the people in it, and the server refuses it either
				// way. The floor belongs to the group, so it survives a tier change.
				const floor = parseInt( seatsField.dataset.seatsFloor, 10 ) || 0;
				const min = Math.max( parseInt( tier.dataset.seatsMin, 10 ) || 1, floor );
				const max = parseInt( tier.dataset.seatsMax, 10 ) || 0;
				seatsInput.min = min;
				if ( max > 0 ) {
					seatsInput.max = max;
				} else {
					seatsInput.removeAttribute( 'max' );
				}
				// Only a tier change may rewrite the value: clamping on every keystroke
				// would fight the reader as they type. A count the newly chosen tier
				// can't sell would otherwise only be rejected server-side.
				const value = parseInt( seatsInput.value, 10 );
				if ( ! tierChanged || Number.isNaN( value ) ) {
					return;
				}
				if ( value < min ) {
					seatsInput.value = min;
				} else if ( max > 0 && value > max ) {
					seatsInput.value = max;
				}
			};

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
					// Compare numerically in minor units, matching the server-side guard:
					// a string comparison would treat "10.00" over an original "10" as a
					// change and let the submission through only to be rejected later.
					// The factor is sized by the store's price decimals (passed from
					// wc_get_price_decimals(), like the server side) so zero- and
					// three-decimal currencies keep a correct smallest step.
					const decimals = parseInt( amountInput?.dataset.priceDecimals, 10 );
					const factor = Math.pow( 10, Number.isNaN( decimals ) ? 2 : decimals );
					const toCents = value => Math.round( parseFloat( value ) * factor );
					if ( amountInput && ( ! amountInput.value || toCents( amountInput.value ) === toCents( amountInput.dataset.originalValue ) ) ) {
						form.querySelector( 'button[type="submit"]' ).disabled = true;
						isFormValid = false;
					} else {
						form.querySelector( 'button[type="submit"]' ).disabled = false;
						isFormValid = true;
					}
				} else {
					syncSeatsToTier();
					// Buying more or fewer seats on the tier the reader already has is a
					// real change, so it can't count as re-selecting the same plan. A
					// disabled or empty field is not a change: like the amount above, it
					// would only be rejected server-side.
					const seats = seatsInput && ! seatsInput.disabled ? seatsInput : null;
					const seatsValue = seats ? parseInt( seats.value, 10 ) : NaN;
					const seatsChanged =
						! Number.isNaN( seatsValue ) && !! seats.dataset.originalValue && seatsValue !== parseInt( seats.dataset.originalValue, 10 );
					const selected = form.querySelector( '.current input[type="radio"]:checked' );
					const isNoOp = !! selected && ! seatsChanged;
					form.querySelector( 'button[type="submit"]' ).disabled = isNoOp;
					isFormValid = ! isNoOp;
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
