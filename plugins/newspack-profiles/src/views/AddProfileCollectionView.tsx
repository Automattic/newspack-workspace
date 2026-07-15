import { ProfileOnboardingSteps } from '../components/ProfileOnboardingSteps';
import { Notices } from '../components/Notices';
import './views.scss';

/**
 * View component for adding or editing a profile.
 *
 * @return JSX.Element The AddProfileCollectionView component.
 */
export const AddProfileCollectionView = () => {
	return (
		<div className="newspack-profiles__view newspack-profiles__view--add">
			<ProfileOnboardingSteps />
			<div className="newspack-profiles__notices-overlay">
				<Notices />
			</div>
		</div>
	);
};
