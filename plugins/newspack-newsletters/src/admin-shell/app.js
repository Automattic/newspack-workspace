import { SnackbarList } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

import { HeaderActionsProvider } from './header-actions-context';
import PageHeader from './page-header';

function ShellNotices() {
	const notices = useSelect( select => select( noticesStore ).getNotices(), [] );
	const { removeNotice } = useDispatch( noticesStore );
	const snackbarNotices = notices.filter( notice => notice.type === 'snackbar' );

	if ( ! snackbarNotices.length ) {
		return null;
	}

	return <SnackbarList className="newspack-newsletters-admin__notices" notices={ snackbarNotices } onRemove={ removeNotice } />;
}

export default function App( { label, Screen } ) {
	const isBundled = !! window.newspackNewslettersAdmin?.bundledMode;
	const titleClass = isBundled ? 'screen-reader-text' : 'newspack-newsletters-admin__title';

	const headerContent = (
		<>
			<h1 className={ titleClass }>{ label }</h1>
			<PageHeader />
		</>
	);

	return (
		<HeaderActionsProvider>
			<div className="newspack-newsletters-admin">
				{ isBundled ? headerContent : <div className="newspack-newsletters-admin__header">{ headerContent }</div> }
				<main className="newspack-newsletters-admin__main">
					<Screen label={ label } />
				</main>
				<ShellNotices />
			</div>
		</HeaderActionsProvider>
	);
}
