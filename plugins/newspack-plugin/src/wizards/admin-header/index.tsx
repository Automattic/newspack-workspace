import { render, Fragment } from '@wordpress/element';
import { NewspackIcon } from '../../../packages/components/src';
import './style.scss';

export function WizardsAdminHeader( {
	title,
	tabs,
}: {
	title: string;
	tabs: Array< {
		textContent: string;
		href: string;
		forceSelected: boolean;
	} >;
} ) {
	return (
		<Fragment>
			<div className="newspack-wizard__header">
				<div className="newspack-wizard__header__inner">
					<div className="newspack-wizard__title">
						<NewspackIcon size={ 36 } />
						<div>
							<h2>{ title }</h2>
						</div>
					</div>
				</div>
			</div>
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
		</Fragment>
	);
}

render(
	<WizardsAdminHeader title={ window.newspackWizardsAdminHeader.title } tabs={ window.newspackWizardsAdminHeader.tabs } />,
	document.getElementById( 'newspack-wizards-admin-header' )
);
