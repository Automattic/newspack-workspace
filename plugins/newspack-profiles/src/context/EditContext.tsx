import { createContext, useContext } from '@wordpress/element';

const EditContext = createContext< boolean >( false );

/**
 * EditContext provider component.
 * It helps to determine if the current context is in edit mode or add mode.
 * Used across various components to adjust behavior based on the mode.
 *
 * @param root0
 * @param root0.children - The child components.
 * @param root0.isEdit   - Whether the current context is in edit mode.
 *
 * @return JSX.Element The EditContextProvider component.
 */
export const EditContextProvider = ( {
	children,
	isEdit,
}: {
	children: React.ReactNode;
	isEdit: boolean;
} ) => {
	return (
		<EditContext.Provider value={ isEdit }>
			{ children }
		</EditContext.Provider>
	);
};

/**
 * Custom hook to access the EditContext.
 *
 * @return {boolean} The current edit mode status.
 */
export const useEditContext = () => {
	return useContext( EditContext );
};
