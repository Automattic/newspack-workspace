import { SnackbarList } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Component for displaying notices using SnackbarList.
 *
 * @return JSX.Element The Notices component.
 */
export const Notices = () => {
	const { removeNotice } = useDispatch( noticesStore );
	const notices = useSelect(
		( select ) => select( noticesStore ).getNotices(),
		[]
	);

	if ( notices.length === 0 ) {
		return null;
	}

	return (
		<SnackbarList
			notices={ notices }
			className="bottom-4 start-4"
			onRemove={ ( notice ) => {
				removeNotice( notice );
			} }
		/>
	);
};
