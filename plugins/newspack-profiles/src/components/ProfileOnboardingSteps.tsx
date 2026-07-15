import { useSelect } from '@wordpress/data';
import { StepBreadcrumb } from './StepBreadcrumb';
import { SourceSelection } from './steps/SourceSelection';
import { PatternSelection } from './steps/PatternSelection';
import { URLAndSEO } from './steps/URLandSEO';

import { store as onboardingStore } from '../stores/onboarding';
import { ProfileOnboardingControls } from './ProfileOnboardingControls';
import { FieldMapping } from './steps/FieldMapping';
import '../views/views.scss';

/**
 * Component that manages and displays the onboarding steps for profiles.
 *
 * @return JSX.Element The ProfileOnboardingSteps component.
 */
export const ProfileOnboardingSteps = () => {
	const currentStep = useSelect( ( select ) => {
		return select( onboardingStore ).getCurrentStep();
	}, [] );

	return (
		<>
			<div className="newspack-profiles__onboarding">
				<div className="newspack-profiles__onboarding__content">
					<StepBreadcrumb currentStep={ currentStep } />
					{ currentStep === 1 && <SourceSelection /> }
					{ currentStep === 2 && <FieldMapping /> }
					{ currentStep === 3 && <PatternSelection /> }
					{ currentStep === 4 && <URLAndSEO /> }
				</div>
			</div>
			<ProfileOnboardingControls />
		</>
	);
};
