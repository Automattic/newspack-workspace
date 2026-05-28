/**
 * WordPress dependencies.
 */
import { ENTER } from '@wordpress/keycodes';

/**
 * Internal dependencies
 */
import { addToolbarBackButton } from './editor-toolbar-back-button';

const InteractiveDiv = ( { style = {}, ...props } ) => (
	<div
		tabIndex="0"
		role="button"
		onKeyDown={ event => ENTER === event.keyCode && props.onClick() }
		style={ { cursor: 'pointer', ...style } }
		{ ...props }
	/>
);

const confirmAction = message => {
	// eslint-disable-next-line no-alert
	if ( confirm( message ) ) {
		return true;
	}
	return false;
};

export default {
	InteractiveDiv,
	confirmAction,
	addToolbarBackButton,
};
