/**
 * EmptyState
 */

/**
 * Internal dependencies.
 */
import Actions from './actions';
import Header from './header';
import Root from './root';

// A namespace object, matching Drawer. See ./README.md.
export const EmptyState = {
	Root,
	Header,
	Actions,
};

Object.entries( EmptyState ).forEach( ( [ name, part ] ) => {
	( part as { displayName?: string } ).displayName = `EmptyState.${ name }`;
} );

export default EmptyState;
