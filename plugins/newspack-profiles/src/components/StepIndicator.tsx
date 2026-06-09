import classNames from 'classnames';
import { __ } from '@wordpress/i18n';

const STEPS = [
	__( 'Source', 'newspack-profiles' ),
	__( 'Fields', 'newspack-profiles' ),
	__( 'Pattern', 'newspack-profiles' ),
	__( 'URL & SEO', 'newspack-profiles' ),
];

/**
 * Component for displaying the step indicator in the onboarding process.
 *
 * @param {Object} props             - Component props.
 * @param {number} props.currentStep - The current step number.
 *
 * @return JSX.Element The StepIndicator component.
 */
export const StepIndicator = ( { currentStep }: { currentStep: number } ) => {
	return (
		<ol className="newspack-profile-onboarding-steps-list sticky top-30">
			{ STEPS.map( ( stepLabel, index ) => (
				<li
					key={ stepLabel }
					className={ classNames(
						'newspack-profile-onboarding-steps-item',
						{
							completed: index + 1 < currentStep,
							active: index + 1 === currentStep,
							upcoming: index + 1 > currentStep,
						}
					) }
				>
					{ stepLabel }
				</li>
			) ) }
		</ol>
	);
};
