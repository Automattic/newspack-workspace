import classNames from 'classnames';
import { Fragment } from '@wordpress/element';
import { Icon } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { chevronRight } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { store as onboardingStore } from '../stores/onboarding';
import './StepBreadcrumb.scss';

const STEPS = [
	__( 'Source', 'newspack-profiles' ),
	__( 'Fields', 'newspack-profiles' ),
	__( 'Pattern', 'newspack-profiles' ),
	__( 'URL & SEO', 'newspack-profiles' ),
];

/**
 * Breadcrumb-style step indicator for the profile wizard.
 *
 * @param {Object} props             - Component props.
 * @param {number} props.currentStep - The current step number.
 *
 * @return JSX.Element The StepBreadcrumb component.
 */
export const StepBreadcrumb = ( { currentStep }: { currentStep: number } ) => {
	const { goToStep } = useDispatch( onboardingStore );

	return (
		<nav
			className="newspack-profiles__breadcrumb"
			aria-label={ __( 'Profile setup steps', 'newspack-profiles' ) }
		>
			{ STEPS.map( ( label, index ) => {
				const step = index + 1;
				const isActive = step === currentStep;
				const isCompleted = step < currentStep;
				const className = classNames(
					'newspack-profiles__breadcrumb__step',
					{
						'is-active': isActive,
						'is-completed': isCompleted,
					}
				);

				return (
					<Fragment key={ label }>
						{ index > 0 && (
							<Icon
								className="newspack-profiles__breadcrumb__separator"
								icon={ chevronRight }
								size={ 20 }
							/>
						) }
						{ isCompleted ? (
							<button
								type="button"
								className={ className }
								onClick={ () => goToStep( step ) }
							>
								{ label }
							</button>
						) : (
							<span
								className={ className }
								aria-current={ isActive ? 'step' : undefined }
							>
								{ label }
							</span>
						) }
					</Fragment>
				);
			} ) }
		</nav>
	);
};
