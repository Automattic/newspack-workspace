/* global newspack_popups_admin */

/**
 * Internal dependencies
 */
import './admin.scss';

const toggle = document.querySelectorAll< HTMLAnchorElement >( '.newspack-campaigns-preview-toggle a' );
const { label_visible: labelVisible, label_hidden: labelHidden } = newspack_popups_admin;
// `isHidden` starts as a `number` (from `parseInt()`) and later becomes a `boolean` (see the
// toggle handler below) -- JS allows this freely since it's only ever used in truthy/falsy or
// ternary checks below, never arithmetic, so the union type reflects that without changing behavior.
let isHidden: number | boolean = parseInt( localStorage.getItem( 'newspackPopupsHide' )! );

if ( isHidden ) {
	document.body.classList.add( 'newspack-popups-hide-prompts' );
}

const toggleHandler = ( e: MouseEvent ) => {
	e.preventDefault();
	isHidden = ! isHidden;
	document.body.classList.toggle( 'newspack-popups-hide-prompts' );
	// `String( ... )` mirrors the implicit number-to-string coercion `localStorage.setItem()`
	// already performs at runtime.
	localStorage.setItem( 'newspackPopupsHide', String( isHidden ? 1 : 0 ) );

	( e.currentTarget as HTMLAnchorElement ).textContent = isHidden ? labelHidden : labelVisible;
};

for ( let i = 0; i < toggle.length; i++ ) {
	const thisToggle = toggle[ i ];
	thisToggle.addEventListener( 'click', toggleHandler );

	if ( isHidden ) {
		thisToggle.textContent = labelHidden;
	}
}
