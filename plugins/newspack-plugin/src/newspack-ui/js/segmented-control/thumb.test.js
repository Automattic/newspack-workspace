import { setupThumb } from './thumb';

const render = () => {
	document.body.innerHTML = `
		<div class="newspack-ui__segmented-control__tabs">
			<button type="button" class="newspack-ui__button selected" aria-pressed="true">Monthly</button>
			<button type="button" class="newspack-ui__button" aria-pressed="false">Annually</button>
		</div>
	`;
	const tabs = document.querySelector( '.newspack-ui__segmented-control__tabs' );
	const [ first, second ] = tabs.children;
	[
		[ first, 4, 60 ],
		[ second, 68, 80 ],
	].forEach( ( [ button, left, width ] ) => {
		Object.defineProperty( button, 'offsetLeft', { value: left } );
		Object.defineProperty( button, 'offsetTop', { value: 4 } );
		Object.defineProperty( button, 'offsetWidth', { value: width } );
		Object.defineProperty( button, 'offsetHeight', { value: 32 } );
	} );
	return { tabs, first, second };
};

const select = ( from, to ) => {
	from.classList.remove( 'selected' );
	from.setAttribute( 'aria-pressed', 'false' );
	to.classList.add( 'selected' );
	to.setAttribute( 'aria-pressed', 'true' );
};

describe( 'segmented control thumb', () => {
	it( 'sits under the selected segment', () => {
		const { tabs } = render();
		setupThumb( tabs );
		expect( tabs.classList.contains( 'has-thumb' ) ).toBe( true );
		expect( tabs.classList.contains( 'is-measuring' ) ).toBe( false );
		expect( tabs.style.getPropertyValue( '--newspack-ui-segmented-thumb-x' ) ).toBe( '4px' );
		expect( tabs.style.getPropertyValue( '--newspack-ui-segmented-thumb-y' ) ).toBe( '4px' );
		expect( tabs.style.getPropertyValue( '--newspack-ui-segmented-thumb-width' ) ).toBe( '60px' );
		expect( tabs.style.getPropertyValue( '--newspack-ui-segmented-thumb-height' ) ).toBe( '32px' );
	} );

	it( 'follows the selection, whichever script moves it', async () => {
		const { tabs, first, second } = render();
		setupThumb( tabs );
		select( first, second );
		await new Promise( resolve => setTimeout( resolve ) );
		expect( tabs.style.getPropertyValue( '--newspack-ui-segmented-thumb-x' ) ).toBe( '68px' );
		expect( tabs.style.getPropertyValue( '--newspack-ui-segmented-thumb-width' ) ).toBe( '80px' );
	} );

	it( 'shows no thumb without a selection', () => {
		const { tabs, first } = render();
		first.classList.remove( 'selected' );
		first.setAttribute( 'aria-pressed', 'false' );
		setupThumb( tabs );
		expect( tabs.classList.contains( 'has-thumb' ) ).toBe( false );
	} );

	it( 'sets up each control once', () => {
		const { tabs } = render();
		const NativeObserver = window.MutationObserver;
		let observers = 0;
		window.MutationObserver = class extends NativeObserver {
			constructor( callback ) {
				super( callback );
				observers++;
			}
		};
		try {
			setupThumb( tabs );
			setupThumb( tabs );
		} finally {
			window.MutationObserver = NativeObserver;
		}
		expect( tabs.dataset.thumb ).toBe( 'true' );
		expect( observers ).toBe( 1 );
	} );
} );
