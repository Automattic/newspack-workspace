import { useSelect } from '@wordpress/data';
import { StepIndicator } from './StepIndicator';
import { SourceSelection } from './steps/SourceSelection';
import { PatternSelection } from './steps/PatternSelection';
import { URLAndSEO } from './steps/URLandSEO';

import { store as onboardingStore } from '../stores/onboarding';
import { ProfileOnboardingControls } from './ProfileOnboardingControls';
import { FieldMapping } from './steps/FieldMapping';

/**
 * Component that manages and displays the onboarding steps for profile collections.
 *
 * @return JSX.Element The ProfileOnboardingSteps component.
 */
export const ProfileOnboardingSteps = () => {
	const currentStep = useSelect( ( select ) => {
		return select( onboardingStore ).getCurrentStep();
	}, [] );

	return (
		<div className="grid md:grid-cols-[200px_1fr] bg-white z-1">
			<div className="hidden md:block border-e border-gray-300 p-8">
				<StepIndicator currentStep={ currentStep } />
			</div>
			<div className="flex flex-col justify-between">
				{ currentStep === 1 && <SourceSelection /> }
				{ currentStep === 2 && <FieldMapping /> }
				{ currentStep === 3 && <PatternSelection /> }
				{ currentStep === 4 && <URLAndSEO /> }
				<ProfileOnboardingControls />
			</div>
		</div>
	);
};
