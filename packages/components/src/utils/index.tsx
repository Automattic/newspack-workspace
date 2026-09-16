/**
 * WordPress dependencies.
 */
import { ENTER } from '@wordpress/keycodes';

/**
 * Internal dependencies
 */
import { addToolbarBackButton } from './editor-toolbar-back-button';

type InteractiveDivProps = {
	/** Inline styles for the element. */
	style?: React.CSSProperties;
	/** Called on click, and on Enter keypresses. */
	onClick: () => void;
} & Omit< React.HTMLAttributes< HTMLDivElement >, 'onClick' | 'style' >;

const InteractiveDiv = ( { style = {}, ...props }: InteractiveDivProps ) => (
	<div
		tabIndex={ 0 }
		role="button"
		onKeyDown={ event => ENTER === event.keyCode && props.onClick() }
		style={ { cursor: 'pointer', ...style } }
		{ ...props }
	/>
);

const confirmAction = ( message?: string ) => {
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
