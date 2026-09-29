import { render } from '@wordpress/element';
import { Page, TabbedNavigation } from '../../../packages/components/src';
import './style.scss';

type Crumb = { label: string; url?: string };

export function WizardsAdminHeader( {
	breadcrumbs,
	tabs,
}: {
	breadcrumbs: Crumb[];
	tabs: Array< {
		textContent: string;
		href: string;
		forceSelected: boolean;
	} >;
} ) {
	return (
		// The `#newspack-wizards-admin-header-actions` node is a cross-root portal
		// target: the newsletters admin-shell (a separate React root) portals its
		// header actions into it via `page-header.js`. This works only because this
		// node is rendered once and never re-rendered or unmounted here — React
		// reconciliation on a re-render would wipe the portalled children. Keep it
		// a static, empty div; do not give this component state that could re-render.
		<Page
			breadcrumbItems={ breadcrumbs }
			actions={ <div id="newspack-wizards-admin-header-actions" /> }
			tabbedNavigation={
				tabs.length > 0 && (
					<TabbedNavigation
						items={ tabs.map( tab => ( {
							label: tab.textContent,
							href: tab.href,
							selected: tab.forceSelected,
						} ) ) }
					/>
				)
			}
		/>
	);
}

render(
	<WizardsAdminHeader breadcrumbs={ window.newspackWizardsAdminHeader.breadcrumbs } tabs={ window.newspackWizardsAdminHeader.tabs } />,
	document.getElementById( 'newspack-wizards-admin-header' )
);
