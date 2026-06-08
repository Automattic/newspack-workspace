import { Notices } from '../components/Notices';
import { Header } from '../components/Header';
import { ProfileCollections } from '../components/ProfileCollections';

/**
 * Main view component for displaying profile collections.
 *
 * @return JSX.Element The ProfileCollectionsView component.
 */
export const ProfileCollectionsView = () => {
	return (
		<div className="h-[calc(100vh-46px)] md:h-[calc(100vh-32px)] relative grid grid-rows-[auto_1fr_auto]">
			<Header />
			<ProfileCollections />
			<Notices />
		</div>
	);
};
