/**
 * Internal dependencies
 */
import isFunction from 'lodash/isFunction';
import isObject from 'lodash/isObject';
import isString from 'lodash/isString';

type ButtonActionObject = {
	handoff?: string;
	editLink?: string;
	onClick?: () => void;
	href?: string;
};

export type ButtonAction = string | ( () => void ) | ButtonActionObject;

type ButtonActionProps = {
	onClick?: () => void;
	href?: string;
	plugin?: string;
	editLink?: string;
};

/**
 * Creates button props based on an action
 */
export default function buttonProps( action: ButtonAction ): ButtonActionProps {
	const props: ButtonActionProps = {};
	if ( isFunction( action ) ) {
		props.onClick = action;
	}
	if ( isString( action ) ) {
		props.href = action;
	}
	if ( isObject( action ) ) {
		// A function action also satisfies isObject; reading the object-action keys off it
		// yields undefined, leaving the props from the isFunction branch untouched.
		const { handoff, editLink, onClick, href } = action as ButtonActionObject;
		if ( handoff ) {
			props.plugin = handoff;
			if ( editLink ) {
				props.editLink = editLink;
			}
		}
		if ( onClick ) {
			props.onClick = onClick;
		}
		if ( href ) {
			props.href = href;
		}
	}
	return props;
}
