import { createContext, useContext, useState } from '@wordpress/element';

const ViewContext = createContext< {
	viewId: string;
	setViewId: React.Dispatch< React.SetStateAction< string > >;
} >( {
	viewId: '',
	setViewId: () => {},
} );

/**
 * ViewContext provider component.
 * It manages the current view identifier within the profile context.
 * Based on the viewId, different components can be rendered like routing w/o URL changes.
 *
 * @param root0
 * @param root0.children - The child components.
 *
 * @return JSX.Element The ViewContextProvider component.
 */
export const ViewContextProvider = ( {
	children,
}: {
	children: React.ReactNode;
} ) => {
	const [ viewId, setViewId ] = useState< string >(
		window.NewspackProfilesSettingsConfig?.initialView === 'add'
			? 'profile-collection/create'
			: 'profile-collection/list'
	);

	return (
		<ViewContext.Provider value={ { viewId, setViewId } }>
			{ children }
		</ViewContext.Provider>
	);
};

/**
 * Custom hook to access the ViewContext.
 *
 * @return {Object} The current view context value.
 */
export const useViewContext = () => {
	return useContext( ViewContext );
};
