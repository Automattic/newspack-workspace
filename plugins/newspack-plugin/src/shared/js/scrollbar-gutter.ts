const COUNT_KEY = 'newspackGutterLocks';
const ORIGINAL_KEY = 'newspackGutterOriginal';

/**
 * Reserve the scrollbar's space while a surface locks page scrolling, so the
 * page doesn't shift sideways. The state lives on `<html>` so surfaces from
 * separate bundles share one count, and the original value is restored only
 * when the last open surface releases.
 */
export const lockScrollbarGutter = () => {
	const root = document.documentElement;
	const count = Number( root.dataset[ COUNT_KEY ] || 0 );
	if ( count === 0 ) {
		root.dataset[ ORIGINAL_KEY ] = root.style.scrollbarGutter;
		if ( window.innerWidth > root.clientWidth ) {
			root.style.scrollbarGutter = 'stable';
		}
	}
	root.dataset[ COUNT_KEY ] = String( count + 1 );
};

/**
 * Release one lock taken with {@link lockScrollbarGutter}.
 */
export const unlockScrollbarGutter = () => {
	const root = document.documentElement;
	const count = Number( root.dataset[ COUNT_KEY ] || 0 );
	if ( count <= 1 ) {
		root.style.scrollbarGutter = root.dataset[ ORIGINAL_KEY ] || '';
		delete root.dataset[ COUNT_KEY ];
		delete root.dataset[ ORIGINAL_KEY ];
		return;
	}
	root.dataset[ COUNT_KEY ] = String( count - 1 );
};
