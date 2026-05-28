/**
 * Internal dependencies
 */
import isFunction from 'lodash/isFunction';
import isObject from 'lodash/isObject';
import isString from 'lodash/isString';

/**
 * Creates button props based on an action
 */
export default function buttonProps( action ) {
	const props = {};
	if ( isFunction( action ) ) {
		props.onClick = action;
	}
	if ( isString( action ) ) {
		props.href = action;
	}
	if ( isObject( action ) ) {
		if ( action.handoff ) {
			props.plugin = action.handoff;
			if ( action.editLink ) {
				props.editLink = action.editLink;
			}
		}
		if ( action.onClick ) {
			props.onClick = action.onClick;
		}
		if ( action.href ) {
			props.href = action.href;
		}
	}
	return props;
}
