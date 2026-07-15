import { Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { EditContextProvider } from './context/EditContext';
import { useViewContext } from './context/ViewContext';
import { AddProfileCollectionView } from './views/AddProfileCollectionView';
import { ProfileCollectionsView } from './views/ProfileCollectionsView';

/**
 * Main application component for managing profiles.
 *
 * @return JSX.Element The App component.
 */
export const App = () => {
	const { viewId, setViewId } = useViewContext();

	const isEdit = viewId === 'profile-collection/edit';
	const isWizardOpen = viewId === 'profile-collection/create' || isEdit;

	return (
		<>
			<ProfileCollectionsView />
			{ isWizardOpen && (
				<Modal
					isFullScreen
					title={
						isEdit
							? __( 'Edit profile', 'newspack-profiles' )
							: __( 'Add new profile', 'newspack-profiles' )
					}
					onRequestClose={ () =>
						setViewId( 'profile-collection/list' )
					}
					className="newspack-profiles__wizard-modal"
				>
					<EditContextProvider isEdit={ isEdit }>
						<AddProfileCollectionView />
					</EditContextProvider>
				</Modal>
			) }
		</>
	);
};
