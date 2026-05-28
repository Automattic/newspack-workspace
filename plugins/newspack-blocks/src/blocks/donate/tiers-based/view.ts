import { setupSlider } from './slider';
import { parseTiersBasedConfig } from './utils';

const BUTTON_ACTIVE_CLASSNAME = 'wpbnbd__button--active';

const getSelectedAmountSelector = ( frequency: string ) => `.wpbnbd__tiers__amount [data-frequency-slug="${ frequency }"]`;

type SelectedFrequency = 'month' | 'year';

export default ( parentEl: HTMLElement ) => {
	const config = parseTiersBasedConfig( parentEl.getAttribute( 'data-tiers-based-config' ) || '' );
	if ( ! config ) {
		return;
	}
	const frequencyButtonsEls = parentEl.querySelectorAll( '.wpbnbd__tiers__selection button' );
	const amountsEls: NodeListOf< HTMLElement > = parentEl.querySelectorAll( '.wpbnbd__tiers__amount [data-frequency-slug]' );
	const frequencyInputEl = parentEl.querySelector( `input[name="${ config.params.frequency }"]` );
	const initFormEl = parentEl.querySelector( 'form[data-is-init-form]' );
	if ( ! frequencyButtonsEls || ! amountsEls || ! frequencyInputEl || ! initFormEl ) {
		return;
	}

	setupSlider( parentEl, config );

	let selectedFrequency: SelectedFrequency = config.initialFrequency;

	// Frequency choosing interaction.
	frequencyButtonsEls.forEach( buttonEl => {
		buttonEl.addEventListener( 'click', () => {
			const maybeSelectedFrequency = buttonEl.getAttribute( 'data-frequency-slug' ) as SelectedFrequency | null;
			if ( ! maybeSelectedFrequency ) {
				return;
			}

			selectedFrequency = maybeSelectedFrequency;

			// Set hidden input value.
			frequencyInputEl.setAttribute( 'value', selectedFrequency );

			// Toggle the amount & frequency UI.
			amountsEls.forEach( amountEl => ( amountEl.style.display = 'none' ) );
			const selectedAmountEls: NodeListOf< HTMLElement > = parentEl.querySelectorAll( getSelectedAmountSelector( selectedFrequency ) );
			selectedAmountEls.forEach( el => ( el.style.display = 'inline' ) );

			// Toggle the frequency buttons' active states.
			frequencyButtonsEls.forEach( el => {
				if ( selectedFrequency === el.getAttribute( 'data-frequency-slug' ) ) {
					el.classList.add( BUTTON_ACTIVE_CLASSNAME );
				} else {
					el.classList.remove( BUTTON_ACTIVE_CLASSNAME );
				}
			} );

			// Update the value attributes in the form's submit buttons.
			const submitButtonsEls = parentEl.querySelectorAll( 'button[type="submit"]' );
			submitButtonsEls.forEach( ( _buttonEl: Element ) => {
				if ( ! selectedFrequency ) {
					return;
				}
				_buttonEl.setAttribute( 'name', `${ config.params.tierPrefix }${ selectedFrequency }` );
				const buttonTierIndex = _buttonEl.getAttribute( 'data-tier-index' );
				const amountEl = document.querySelector(
					`${ getSelectedAmountSelector( selectedFrequency ) }[data-tier-index="${ buttonTierIndex }"]`
				);
				if ( ! amountEl ) {
					return;
				}
				const value = amountEl.getAttribute( 'data-amount' );
				if ( value ) {
					_buttonEl.setAttribute( 'value', value );
				}
			} );
		} );
	} );

	// View transitions.
	const backButton = parentEl.querySelector( 'button.wpbnbd__tiers__back-button' ) as HTMLElement;
	const tierSelectionButtonsEls = parentEl.querySelectorAll( '.wpbnbd__tiers__tier button' );
	const viewEls = parentEl.querySelectorAll( '.wpbnbd__tiers__view' );
	const toggleView = () => viewEls.forEach( viewEl => viewEl.classList.toggle( 'wpbnbd__tiers__view--hidden' ) );

	tierSelectionButtonsEls.forEach( buttonEl => {
		buttonEl.addEventListener( 'click', () => {
			const tierIndex = parseInt( buttonEl.getAttribute( 'data-tier-index' ) || '' );

			// Remove hidden index and value inputs from tiers form.
			const hiddenIndexInputEl = initFormEl.querySelector( 'input[name="donation_tier_index"]' );
			const hiddenValueInputEl = initFormEl.querySelector( `input[name="donation_value_${ selectedFrequency }"]` );
			if ( hiddenIndexInputEl ) {
				hiddenIndexInputEl.remove();
			}
			if ( hiddenValueInputEl ) {
				hiddenValueInputEl.remove();
			}

			// Append hidden index input to tiers form.
			const indexInputEl = document.createElement( 'input' );
			const valueInputEl = document.createElement( 'input' );
			indexInputEl.setAttribute( 'type', 'hidden' );
			valueInputEl.setAttribute( 'type', 'hidden' );
			indexInputEl.setAttribute( 'name', 'donation_tier_index' );
			valueInputEl.setAttribute( 'name', `donation_value_${ selectedFrequency }` );
			indexInputEl.setAttribute( 'value', tierIndex.toString() );
			valueInputEl.setAttribute( 'value', config.amounts[ selectedFrequency ][ tierIndex ] );
			initFormEl.appendChild( indexInputEl );
			initFormEl.appendChild( valueInputEl );

			const tierHeadingEl: HTMLElement | null = parentEl.querySelector( '.wpbnbd__tiers__tier-tile h2' );
			const tierAmountEl: HTMLElement | null = parentEl.querySelector( '.wpbnbd__tiers__tier-tile span[data-amount]' );
			const tierFrequencyLabelEl: HTMLElement | null = parentEl.querySelector( '.wpbnbd__tiers__tier-tile span[data-frequency]' );
			const renewsOnEl: HTMLElement | null = parentEl.querySelector( '.wpbnbd__tiers__tier-tile span[data-renews-date]' );
			if ( isNaN( tierIndex ) || ! tierHeadingEl || ! tierAmountEl || ! tierFrequencyLabelEl || ! renewsOnEl ) {
				return;
			}

			const tierOption = config.tiersBasedOptions[ tierIndex ];
			const frequencyLabel = parentEl.querySelector( `[data-frequency-slug="${ selectedFrequency }"]` )?.getAttribute( 'data-frequency-label' );
			tierHeadingEl.textContent = tierOption.heading;
			tierFrequencyLabelEl.textContent = frequencyLabel || '';
			tierAmountEl.textContent = config.amounts[ selectedFrequency ][ tierIndex ];
			renewsOnEl.textContent = config.renewsAt[ selectedFrequency ];

			toggleView();
		} );
	} );

	window.addEventListener( `newspackPaymentFlowComplete-${ parentEl.id }`, () => {
		if ( backButton ) {
			backButton.style.display = 'none';
		}
	} );
};
