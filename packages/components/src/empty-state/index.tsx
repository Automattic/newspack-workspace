/**
 * EmptyState
 */

/**
 * Internal dependencies.
 */
import Header from './header';
import Root from './root';

// A namespace object, matching Drawer. See ./README.md.
export const EmptyState = {
	Root,
	Header,
};

Object.entries( EmptyState ).forEach( ( [ name, part ] ) => {
	( part as { displayName?: string } ).displayName = `EmptyState.${ name }`;
} );

export default EmptyState;
