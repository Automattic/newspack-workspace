/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * External dependencies.
 */
import { createRoot } from 'react-dom/client';
import { HashRouter, useLocation, useParams, Switch, Route, Redirect } from 'react-router-dom';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { Modal, Button, SlotFillProvider, Snackbar } from '@wordpress/components';
import { store as noticesStore } from '@wordpress/notices';
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies.
 */
import { TabbedNavigation } from 'newspack-components';

import AppHeader, { AppHeaderActions } from '../components/app-header';
import Stories from '../components/stories';
import Story from '../components/story';
import Budgets from '../components/budgets';
import CreateNewStory from '../components/create-new-story';
import CreateBudgetModal from '../components/create-budget-modal';
import Sites from '../components/sites';
import AuthorizingSite from '../components/authorizing-site';
import SitesNav from '../components/sites-nav';

import { NOTICE_CONTEXT, NAMESPACE as storeNamespace } from '../store/constants';
import { isAuthorizingSite, getCurrentSiteName } from '../utils/sites';
import { isBudgetStories } from '../utils/budgets';

import '../style.scss';

const ModalPage = ( { children, name, closeHref, size = 'large', ...props } ) => {
	const className = name ? `newspack-story-budget__modal-page__${ name }` : '';
	return (
		<Modal
			onRequestClose={ () => ( closeHref ? ( window.location.href = closeHref ) : window.history.back() ) }
			size={ size }
			className={ `newspack-story-budget__modal-page ${ className }` }
			{ ...props }
			shouldCloseOnClickOutside={ false }
		>
			{ children }
		</Modal>
	);
};

const StoryPage = () => {
	const { id } = useParams();
	return (
		<ModalPage name="story" size="fill" __experimentalHideHeader>
			<Story storyId={ id } onCancel={ () => ( window.location.hash = '' ) } />
		</ModalPage>
	);
};

const StoryBudget = () => {
	const location = useLocation();

	const { notices, budgetStoryMeta } = useSelect( select => ( {
		notices: select( noticesStore ).getNotices( NOTICE_CONTEXT ),
		budgetStoryMeta: select( storeNamespace ).getBudgetStoryMeta(),
	} ) );

	const canManage = useSelect( select => select( storeNamespace ).canManage() );

	const navigationItems = [ { label: __( 'Stories', 'newspack-story-budget' ), path: '/stories' } ];

	if ( canManage ) {
		navigationItems.push( {
			label: __( 'Budgets', 'newspack-story-budget' ),
			path: '/budgets',
		} );
	}

	const currentNavItem = navigationItems.find( item => location.pathname.indexOf( item.path ) === 0 );

	const getHeaderText = () => {
		const parts = [ __( 'Story Budget', 'newspack-story-budget' ) ];

		const siteName = getCurrentSiteName();
		if ( siteName ) {
			parts.push( siteName );
		}

		if ( currentNavItem ) {
			parts.push( currentNavItem.label );
		}

		if ( isBudgetStories() && budgetStoryMeta?.name ) {
			parts.push( budgetStoryMeta.name );
		}

		return parts.join( ' / ' );
	};

	if ( isAuthorizingSite() ) {
		return <AuthorizingSite />;
	}

	return (
		<div className="wrap">
			<AppHeader headerText={ getHeaderText() } />
			<TabbedNavigation items={ navigationItems } />
			<div className="newspack-story-budget__content">
				<Switch>
					<Route path="/stories">
						<AppHeaderActions>
							{ canManage && (
								<Button variant="primary" href="#/stories/new">
									{ __( 'Add New Story', 'newspack-story-budget' ) }
								</Button>
							) }
							<SitesNav />
						</AppHeaderActions>
						<Stories />
						<Switch>
							<Route path="/stories/sites" exact>
								<ModalPage
									title={ __( 'Connect to remote site', 'newspack-story-budget' ) }
									closeHref="#/stories"
									name={ 'sites' }
									size="medium"
								>
									<Sites />
								</ModalPage>
							</Route>
							<Route path="/stories/new" exact>
								<ModalPage title={ __( 'Add New Story', 'newspack-story-budget' ) } closeHref="#/stories" name={ 'create-new-story' }>
									<CreateNewStory onClose={ () => ( window.location.href = '#/stories' ) } />
								</ModalPage>
							</Route>
							<Route path="/stories/:id">
								<StoryPage />
							</Route>
						</Switch>
					</Route>
					<Route path="/budgets">
						<AppHeaderActions>
							<Button variant="primary" href="#/budgets/new">
								{ __( 'Add New Budget', 'newspack-story-budget' ) }
							</Button>
							<SitesNav />
						</AppHeaderActions>
						<Budgets />
						<Switch>
							<Route path="/budgets/new">
								<ModalPage title={ __( 'Add New Budget', 'newspack-story-budget' ) } closeHref="#/budgets" name={ 'create-budget' }>
									<CreateBudgetModal onClose={ () => ( window.location.href = '#/budgets' ) } />
								</ModalPage>
							</Route>
						</Switch>
					</Route>
					<Redirect to="/stories" />
				</Switch>
			</div>
			<div className="newspack-story-budget__notices">
				{ notices.map( notice => (
					<Snackbar key={ notice.id } onDismiss={ notice.onDismiss }>
						{ notice.content }
					</Snackbar>
				) ) }
			</div>
		</div>
	);
};

createRoot( document.getElementById( 'newspack-story-budget-app' ) ).render(
	<HashRouter>
		<SlotFillProvider>
			<StoryBudget />
		</SlotFillProvider>
	</HashRouter>
);
