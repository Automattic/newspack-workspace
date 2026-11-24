export const IGNORED_TARGETS = 'input, textarea, select, video, audio, [contenteditable]:not([contenteditable="false"]), [role="listbox"], [role="menu"], [role="dialog"], [aria-modal="true"], [data-radix-popper-content-wrapper]';
export const ACTIVATABLE_TARGETS = 'button, a[href], summary';

type Box = { top: number; bottom: number };
type Boxed = { getBoundingClientRect(): Box };
type DeckScroller = Boxed & { clientHeight: number };

export type DeckStep = { type: 'scroll'; top: number } | { type: 'slide'; index: number };

export function isIgnoredTarget( target: { closest( selector: string ): unknown } | null ) {
	return Boolean( target?.closest( IGNORED_TARGETS ) );
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
