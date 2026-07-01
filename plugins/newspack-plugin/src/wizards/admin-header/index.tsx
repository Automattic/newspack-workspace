import { render } from '@wordpress/element';
import { Page } from '../../../packages/components/src';
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
		<Page breadcrumbItems={ breadcrumbs } actions={ <div id="newspack-wizards-admin-header-actions" /> }>
			{ tabs.length > 0 && (
				<div className="newspack-tabbed-navigation">
					<ul>
						{ tabs.map( ( tab, i ) => {
							const selected = tab.forceSelected ? true : window.location.href === tab.href;
							return (
								<li key={ `${ tab.textContent }:${ i }` }>
									<a href={ tab.href } className={ selected ? 'selected' : '' }>
										{ tab.textContent }
									</a>
								</li>
							);
						} ) }
					</ul>
				</div>
			) }
		</Page>
	);
}

render(
	<WizardsAdminHeader breadcrumbs={ window.newspackWizardsAdminHeader.breadcrumbs } tabs={ window.newspackWizardsAdminHeader.tabs } />,
	document.getElementById( 'newspack-wizards-admin-header' )
);
