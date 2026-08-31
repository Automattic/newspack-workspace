/**
 * WordPress dependencies.
 */
import { createContext, useContext } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import type { DrawerTitleInfo } from './types';

type DrawerContextValue = {
	requestClose: () => void;
	title: DrawerTitleInfo | null;
	setTitle: ( title: DrawerTitleInfo | null, forId?: string ) => void;
};

export const DrawerContext = createContext< DrawerContextValue | null >( null );

export const useDrawerContext = (): DrawerContextValue => {
	const context = useContext( DrawerContext );
	if ( ! context ) {
		throw new Error( 'Drawer subcomponents must be rendered inside Drawer.Root.' );
	}
	return context;
};
