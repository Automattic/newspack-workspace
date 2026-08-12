/**
 * EmptyState
 */

/**
 * Internal dependencies.
 */
import Root from './root';

// A namespace object, matching Drawer. See the README.
export const EmptyState = {
	Root,
};

Object.entries( EmptyState ).forEach( ( [ name, part ] ) => {
	( part as { displayName?: string } ).displayName = `EmptyState.${ name }`;
} );

export default EmptyState;
