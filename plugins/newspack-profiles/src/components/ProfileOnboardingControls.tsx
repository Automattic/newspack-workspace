import { Button } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import { store as onboardingStore } from '../stores/onboarding';
import { store as profileCollectionsStore } from '../stores/profile-collections';
import { useViewContext } from '../context/ViewContext';
import { useEditContext } from '../context/EditContext';

/**
 * Component for rendering onboarding controls for profiles.
 *
 * @return JSX.Element The ProfileOnboardingControls component.
 */
export const ProfileOnboardingControls = () => {
	const { setViewId } = useViewContext();
	const isEdit = useEditContext();

	const {
		hasNextStep,
		hasPreviousStep,
		canProceedToNextStep,
		profileCollectionPayload,
	} = useSelect( ( select ) => {
		return {
			currentStep: select( onboardingStore ).getCurrentStep(),
			hasNextStep: select( onboardingStore ).hasNextStep(),
			hasPreviousStep: select( onboardingStore ).hasPreviousStep(),
			canProceedToNextStep:
				select( onboardingStore ).canProceedToNextStep(),
			profileCollectionPayload:
				select( onboardingStore ).getProfileCollectionPayload(),
		};
	}, [] );

	const { nextStep, previousStep, resetOnboarding } =
		useDispatch( onboardingStore );
	const { invalidateResolution } = useDispatch( profileCollectionsStore );
	const {
		createInfoNotice,
		createSuccessNotice,
		createErrorNotice,
		removeNotice,
	} = useDispatch( noticesStore );

	const handleCreateProfileCollection = async () => {
		const creatingNoticeId = 'creating-profile-collection';

		try {
			createInfoNotice( __( 'Creating profile…', 'newspack-profiles' ), {
				type: 'snackbar',
				id: creatingNoticeId,
			} );

			await apiFetch( {
				method: 'POST',
				path: '/newspack-profiles/v1/profile-collections',
				data: {
					collection: profileCollectionPayload,
				},
			} );

			removeNotice( creatingNoticeId );

			window.location.href =
				window.NewspackProfilesSettingsConfig.profileCollectionsListURL;
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( 'Failed to create profile', error );

			removeNotice( creatingNoticeId );

			createErrorNotice(
				__( 'Failed to create profile.', 'newspack-profiles' ),
				{ type: 'snackbar' }
			);
		}
	};

	const handleUpdateProfileCollection = async () => {
		const updatingNoticeId = 'updating-profile-collection';

		try {
			setViewId( 'profile-collection/list' );

			createInfoNotice( __( 'Updating profile…', 'newspack-profiles' ), {
				type: 'snackbar',
				id: updatingNoticeId,
			} );

			await apiFetch( {
				method: 'PUT',
				path: '/newspack-profiles/v1/profile-collections',
				data: {
					collection: profileCollectionPayload,
				},
			} );

			resetOnboarding();

			invalidateResolution( 'getCollections', [] );

			removeNotice( updatingNoticeId );

			createSuccessNotice(
				__( 'Profile updated.', 'newspack-profiles' ),
				{ type: 'snackbar' }
			);
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( 'Failed to update profile', error );

			removeNotice( updatingNoticeId );

			createErrorNotice(
				__( 'Failed to update profile.', 'newspack-profiles' ),
				{ type: 'snackbar' }
			);
		}
	};

	const controls: {
		key: string;
		variant: 'primary' | 'secondary';
		disabled: boolean;
		onClick: () => void;
		label: string;
	}[] = [
		{
			key: 'previous',
			variant: 'secondary',
			disabled: ! hasPreviousStep,
			onClick: () => previousStep(),
			label: __( 'Previous', 'newspack-profiles' ),
		},
	];

	if ( hasNextStep ) {
		controls.push( {
			key: 'next',
			variant: 'primary',
			disabled: ! canProceedToNextStep,
			onClick: () => nextStep(),
			label: __( 'Next', 'newspack-profiles' ),
		} );
	}

	if ( ! hasNextStep && ! isEdit ) {
		controls.push( {
			key: 'create',
			variant: 'primary',
			disabled: ! canProceedToNextStep,
			onClick: handleCreateProfileCollection,
			label: __( 'Create', 'newspack-profiles' ),
		} );
	}

	if ( ! hasNextStep && isEdit ) {
		controls.push( {
			key: 'update',
			variant: 'primary',
			disabled: ! canProceedToNextStep,
			onClick: handleUpdateProfileCollection,
			label: __( 'Update', 'newspack-profiles' ),
		} );
	}

	return (
		<div className="newspack-profiles__wizard-footer">
			<div className="newspack-profiles__wizard-footer__actions">
				{ controls.map( ( step ) => (
					<Button
						key={ step.key }
						variant={ step.variant }
						className="newspack-profiles__wizard-footer-button"
						disabled={ step.disabled }
						onClick={ step.onClick }
					>
						{ step.label }
					</Button>
				) ) }
			</div>
		</div>
	);
};
