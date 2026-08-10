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

	return (
		<HeaderActionsProvider>
			<div className="newspack-newsletters-admin">
				{ isBundled ? (
					// Bundled: newspack-plugin's admin-header <Page> renders the canonical <h1>.
					<PageHeader />
				) : (
					<div className="newspack-newsletters-admin__header">
						<h1 className="newspack-newsletters-admin__title">{ label }</h1>
						<PageHeader />
					</div>
				) }
				<main className="newspack-newsletters-admin__main">
					<Screen label={ label } />
				</main>
				<ShellNotices />
			</div>
		</HeaderActionsProvider>
	);
}
