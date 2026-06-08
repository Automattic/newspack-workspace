import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { chevronLeft } from '@wordpress/icons';
import { useViewContext } from '../context/ViewContext';
import { ProfileOnboardingSteps } from '../components/ProfileOnboardingSteps';
import { useEditContext } from '../context/EditContext';
import { Notices } from '../components/Notices';

/**
 * View component for adding or editing a profile collection.
 *
 * @return JSX.Element The AddProfileCollectionView component.
 */
export const AddProfileCollectionView = () => {
	const { setViewId } = useViewContext();
	const isEdit = useEditContext();

	return (
		<div className="h-[calc(100vh-46px)] md:h-[calc(100vh-32px)] grid grid-rows-[auto_1fr] relative">
			<div className="flex items-center px-6 py-4 border-b border-gray-200 sticky top-8 bg-white z-10">
				<Button
					icon={ chevronLeft }
					onClick={ () => setViewId( 'profile-collection/list' ) }
				/>
				<h2 className="m-0">
					{ isEdit
						? __( 'Edit Profile Collection', 'remote-data-blocks' )
						: __( 'Add Profile Collection', 'remote-data-blocks' ) }
				</h2>
			</div>
			<ProfileOnboardingSteps />
			<div className="fixed bottom-0 left-40 right-0 z-50">
				<Notices />
			</div>
		</div>
	);
};
