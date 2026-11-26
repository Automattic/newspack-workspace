// eslint-disable-next-line import/no-extraneous-dependencies
import debounce from 'lodash/debounce';
import { prefersReducedMotion } from 'newspack-motion';

import type { Configuration } from './types';

const getScrollBehavior = (): ScrollBehavior => ( prefersReducedMotion() ? 'auto' : 'smooth' );

const findClosestIndex = ( arr: number[], num: number ) => {
	const closest =
		arr.reduce( ( acc, val ) => {
			if ( Math.abs( val - num ) < Math.abs( acc ) ) {
				return val - num;
			}
			return acc;
		}, Infinity ) + num;
	return arr.indexOf( closest );
};

export const setupSlider = ( parentEl: HTMLElement, configuration: Configuration ) => {
	const optionsEl = parentEl.querySelector( '.wpbnbd__tiers__options' );
	const optionsDotsEl = parentEl.querySelector( '.wpbnbd__tiers__options__dots' );
	if ( ! optionsEl || ! optionsDotsEl || ! configuration ) {
		return;
	}
	const getOptions = () =>
		Array.from( optionsEl.children ).map( ( optionEl: Element ) => ( {
			el: optionEl,
			width: optionEl.clientWidth,
		} ) );
	let options: { el: Element; width: number }[] = getOptions();
	const initialOptionIndex = Math.round( options.length / 2 ) - 1;
	const getOptionWidth = () => options[ initialOptionIndex ].width;
	const getOptionsOffsets = () => options.map( ( option, index ) => optionWidth * index );

	let optionWidth = getOptionWidth();
	let optionsOffsets = getOptionsOffsets();
	const initialScrollOffset = initialOptionIndex * optionWidth;

	let currentIndex = initialOptionIndex;
	let viewportWidth = window.innerWidth;

	window.addEventListener( 'resize', () => {
		if ( window.innerWidth === viewportWidth ) {
			return;
		}
		viewportWidth = window.innerWidth;
		options = getOptions();
		optionWidth = getOptionWidth();
		optionsOffsets = getOptionsOffsets();
		optionsEl.scrollTo( optionsOffsets[ currentIndex ], 0 );
	} );

	const optionsDots = Array.from( optionsDotsEl.children ) as HTMLElement[];

	const paintDots = ( activeIndex: number ) => {
		optionsDots.forEach( ( dotEl, index ) => {
			const isActive = index === activeIndex;
			dotEl.classList.toggle( 'is-active', isActive && ! configuration.buttonColor );
			dotEl.style.backgroundColor = isActive ? configuration.buttonColor : '';
		} );
	};
	const updateDots = () => {
		currentIndex = findClosestIndex( optionsOffsets, optionsEl.scrollLeft );
		paintDots( currentIndex );
	};
	if ( 'onscrollend' in window ) {
		optionsEl.addEventListener( 'scrollend', updateDots );
	} else {
		optionsEl.addEventListener( 'scroll', debounce( updateDots, 100 ) );
	}

	optionsDots.forEach( ( dotEl, index ) => {
		dotEl.addEventListener( 'click', () => {
			currentIndex = index;
			paintDots( index );
			optionsEl.scrollTo( {
				left: index * optionWidth,
				behavior: getScrollBehavior(),
			} );
		} );
	} );

	optionsEl.scrollTo( initialScrollOffset, 0 );
};
