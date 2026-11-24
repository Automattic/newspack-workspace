export const IGNORED_TARGETS = 'input, textarea, select, video, audio, [contenteditable]:not([contenteditable="false"]), [role="listbox"], [role="menu"], [role="dialog"], [aria-modal="true"], [data-radix-popper-content-wrapper]';
export const ACTIVATABLE_TARGETS = 'button, a[href], summary';

type Box = { top: number; bottom: number };
type Boxed = { getBoundingClientRect(): Box };
type DeckScroller = Boxed & { clientHeight: number };

export type DeckStep = { type: 'scroll'; top: number } | { type: 'slide'; index: number };

type Closest = { closest( selector: string ): unknown };
type DeckKey = { key: string; shiftKey: boolean; ctrlKey: boolean; metaKey: boolean; altKey: boolean; defaultPrevented: boolean };

export type DeckKeyAction = { type: 'step'; direction: 1 | -1 } | { type: 'jump'; to: 'first' | 'last' } | { type: 'cancel' } | null;

const NEXT_KEYS = [ 'ArrowDown', 'ArrowRight', 'PageDown' ];
const PREVIOUS_KEYS = [ 'ArrowUp', 'ArrowLeft', 'PageUp' ];
export const SCROLL_KEYS = [ ...NEXT_KEYS, ...PREVIOUS_KEYS, 'Home', 'End', ' ' ];

export function isIgnoredTarget( target: Closest | null ) {
	return Boolean( target?.closest( IGNORED_TARGETS ) );
}

/**
 * What a key press does to the deck. `cancel` is a scrolling key the deck leaves to the browser,
 * which stops a scripted scroll so the two never fight.
 */
export function deckKeyAction( event: DeckKey, target: Closest | null ): DeckKeyAction {
	if ( event.defaultPrevented || ! SCROLL_KEYS.includes( event.key ) || isIgnoredTarget( target ) ) {
		return null;
	}
	const space = event.key === ' ';
	if ( space && target?.closest( ACTIVATABLE_TARGETS ) ) {
		return null;
	}
	if ( event.ctrlKey || event.metaKey || event.altKey || ( event.shiftKey && ! space ) ) {
		return { type: 'cancel' };
	}
	if ( space ) {
		return { type: 'step', direction: event.shiftKey ? -1 : 1 };
	}
	if ( NEXT_KEYS.includes( event.key ) ) {
		return { type: 'step', direction: 1 };
	}
	if ( PREVIOUS_KEYS.includes( event.key ) ) {
		return { type: 'step', direction: -1 };
	}
	return { type: 'jump', to: event.key === 'Home' ? 'first' : 'last' };
}

/**
 * The scroll position that puts `slide` at the top of `scroller`. Stays correct mid-scroll.
 */
export function slideScrollTop( scroller: Boxed & { scrollTop: number }, slide: Boxed ) {
	return scroller.scrollTop + slide.getBoundingClientRect().top - scroller.getBoundingClientRect().top;
}

/**
 * `box` as it will sit once a scroll still `offset` pixels from its destination arrives.
 */
export function atDestination< T extends Boxed >( box: T, offset: number ): Boxed {
	return {
		getBoundingClientRect: () => {
			const rect = box.getBoundingClientRect();
			return { top: rect.top - offset, bottom: rect.bottom - offset };
		},
	};
}

export function currentDeckIndex( scroller: Boxed, slides: Boxed[] ) {
	const top = scroller.getBoundingClientRect().top;
	let current = 0;
	slides.forEach( ( slide, index ) => {
		if ( slide.getBoundingClientRect().top <= top + 1 ) {
			current = index;
		}
	} );
	return current;
}

/**
 * For each dot's vertical center, whether the slide behind it is blue.
 */
export function dotTones( centers: number[], slides: Array< { top: number; bottom: number; inverse: boolean } > ) {
	return centers.map( y => slides.find( slide => slide.top <= y && y < slide.bottom )?.inverse ?? false );
}

export function planDeckStep( scroller: DeckScroller, slides: Boxed[], direction: 1 | -1, pending: number | null = null ): DeckStep | null {
	const index = pending ?? currentDeckIndex( scroller, slides );
	if ( pending === null ) {
		const bounds = scroller.getBoundingClientRect();
		const rect = slides[ index ].getBoundingClientRect();
		const page = scroller.clientHeight - scroller.clientHeight / 10;
		if ( direction === 1 && rect.bottom > bounds.bottom + 1 ) {
			return { type: 'scroll', top: Math.min( page, rect.bottom - bounds.bottom ) };
		}
		if ( direction === -1 && rect.top < bounds.top - 1 ) {
			return { type: 'scroll', top: -Math.min( page, bounds.top - rect.top ) };
		}
	}
	const target = index + direction;
	if ( target < 0 || target > slides.length - 1 ) {
		return null;
	}
	return { type: 'slide', index: target };
}

export function encodeDeckHash( id: string ) {
	return `#${ encodeURIComponent( id ) }`;
}

export function decodeDeckHash( hash: string ) {
	try {
		return decodeURIComponent( hash.replace( /^#/, '' ) );
	} catch {
		return '';
	}
}
