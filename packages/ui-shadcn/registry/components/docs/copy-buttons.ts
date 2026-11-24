const timers = new WeakMap< HTMLButtonElement, number >();

function textToCopy( button: HTMLButtonElement ) {
	if ( button.dataset.copy === 'anchor' ) {
		const heading = button.closest( '[id]' );
		if ( ! heading ) {
			return null;
		}
		const url = new URL( window.location.href );
		url.hash = heading.id;
		return url.href;
	}
	const pre = button.closest( 'figure' )?.querySelector( 'pre' );
	if ( ! pre ) {
		return null;
	}
	const clone = pre.cloneNode( true ) as HTMLElement;
	clone.querySelectorAll( '.nd-copy-ignore' ).forEach( ( node ) => node.replaceWith( '\n' ) );
	return clone.textContent ?? '';
}

function setChecked( button: HTMLButtonElement, checked: boolean ) {
	const label = button.querySelector( '.sr-only' );
	if ( label ) {
		button.dataset.idleLabel ??= label.textContent ?? '';
		label.textContent = checked ? button.dataset.copiedLabel ?? '' : button.dataset.idleLabel;
	}
	button.toggleAttribute( 'data-checked', checked );
}

document.addEventListener( 'click', async ( event ) => {
	const button = event.target instanceof Element ? event.target.closest< HTMLButtonElement >( 'button[data-copy]' ) : null;
	const text = button ? textToCopy( button ) : null;
	if ( ! button || text === null ) {
		return;
	}
	await navigator.clipboard.writeText( text );
	window.clearTimeout( timers.get( button ) );
	setChecked( button, true );
	timers.set( button, window.setTimeout( () => setChecked( button, false ), 1500 ) );
} );
