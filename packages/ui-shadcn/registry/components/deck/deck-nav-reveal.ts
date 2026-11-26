export const REVEAL_EDGE = 80;
export const HIDE_DELAY = 1000;
export const ALWAYS_VISIBLE_QUERY = 'not all and (hover: hover) and (pointer: fine)';

export type RevealTimers = {
	setTimeout: ( callback: () => void, ms: number ) => unknown;
	clearTimeout: ( id: unknown ) => void;
};

export function nearEdge( x: number, width: number, edge = REVEAL_EDGE ) {
	return x >= width - edge;
}

/**
 * Shows and hides the dot navigation like the macOS Dock: shown while the mouse is within
 * REVEAL_EDGE px of the right edge or over the nav, while keyboard focus is inside it, or always
 * on a device without a fine hovering pointer, and hidden HIDE_DELAY ms after the last of those
 * ends. `start` shows it on load and `touch` on a tap, each then tucking it away after the same
 * delay. `setVisible` is called only when the state changes.
 */
export function createNavReveal( setVisible: ( visible: boolean ) => void, timers: RevealTimers ) {
	let visible = true;
	let focused = false;
	let always = false;
	let near = false;
	let timer: unknown = null;

	const set = ( next: boolean ) => {
		if ( next !== visible ) {
			visible = next;
			setVisible( next );
		}
	};
	const cancel = () => {
		if ( timer !== null ) {
			timers.clearTimeout( timer );
			timer = null;
		}
	};
	const show = () => {
		cancel();
		set( true );
	};
	const hideLater = () => {
		if ( focused || always || near || ! visible || timer !== null ) {
			return;
		}
		timer = timers.setTimeout( () => {
			timer = null;
			set( false );
		}, HIDE_DELAY );
	};

	return {
		get visible() {
			return visible;
		},
		start: hideLater,
		pointer( x: number, width: number, overNav: boolean ) {
			near = overNav || nearEdge( x, width );
			if ( near ) {
				show();
			} else {
				hideLater();
			}
		},
		leave() {
			near = false;
			hideLater();
		},
		touch() {
			show();
			hideLater();
		},
		pin() {
			focused = true;
			show();
		},
		unpin() {
			focused = false;
			hideLater();
		},
		setAlways( on: boolean ) {
			always = on;
			if ( on ) {
				show();
			} else {
				hideLater();
			}
		},
		destroy: cancel,
	};
}
