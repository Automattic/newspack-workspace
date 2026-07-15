import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import NewspackIcon from 'newspack-components/dist/esm/newspack-icon';
import { useViewContext } from '../context/ViewContext';
import { store as onboardingStore } from '../stores/onboarding';
import './Header.scss';

/**
 * Header component for the Newspack Profiles plugin.
 *
 * @return JSX.Element The Header component.
 */
export const Header = () => {
	const { setViewId } = useViewContext();
	const { resetOnboarding } = useDispatch( onboardingStore );

	return (
		<div className="newspack-profiles__header">
			<div className="newspack-profiles__header__title">
				<div className="newspack-profiles__header__brand">
					<NewspackIcon size={ 36 } />
				</div>
				<div className="newspack-profiles__header__heading">
					<h2>{ __( 'Profiles', 'newspack-profiles' ) }</h2>
				</div>
			</div>
			<Button
				className="newspack-profiles__header__add"
				onClick={ () => {
					resetOnboarding();
					setViewId( 'profile-collection/create' );
				} }
			>
				{ __( 'Add new profile', 'newspack-profiles' ) }
			</Button>
		</div>
	);
};
