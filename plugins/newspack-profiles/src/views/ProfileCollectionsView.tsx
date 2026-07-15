import { Notices } from '../components/Notices';
import { Header } from '../components/Header';
import { ProfileCollections } from '../components/ProfileCollections';
import './views.scss';

/**
 * Main view component for displaying profiles.
 *
 * @return JSX.Element The ProfileCollectionsView component.
 */
export const ProfileCollectionsView = () => {
	return (
		<div className="newspack-profiles__view newspack-profiles__view--list">
			<Header />
			<ProfileCollections />
			<Notices />
		</div>
	);
};
