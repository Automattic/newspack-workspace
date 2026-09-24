/**
 * Drawer
 */

/**
 * Internal dependencies.
 */
import Action from './action';
import CloseIcon from './close-icon';
import Content from './content';
import Footer from './footer';
import Header from './header';
import Root from './root';
import Divider from './divider';
import Title from './title';

// A namespace object, not this package's usual flat exports. See the README.
export const Drawer = {
	Root,
	Header,
	Title,
	CloseIcon,
	Content,
	Divider,
	Footer,
	Action,
};

Object.entries( Drawer ).forEach( ( [ name, part ] ) => {
	( part as { displayName?: string } ).displayName = `Drawer.${ name }`;
} );

export default Drawer;
