import { EditContextProvider } from './context/EditContext';
import { useViewContext } from './context/ViewContext';
import { AddProfileCollectionView } from './views/AddProfileCollectionView';
import { ProfileCollectionsView } from './views/ProfileCollectionsView';

/**
 * Main application component for managing profile collections.
 *
 * @return JSX.Element The App component.
 */
export const App = () => {
	const { viewId } = useViewContext();

	switch ( viewId ) {
		case 'profile-collection/create':
			return (
				<EditContextProvider isEdit={ false }>
					<AddProfileCollectionView />
				</EditContextProvider>
			);
		case 'profile-collection/edit':
			return (
				<EditContextProvider isEdit={ true }>
					<AddProfileCollectionView />
				</EditContextProvider>
			);
		default:
			return <ProfileCollectionsView />;
	}
};
