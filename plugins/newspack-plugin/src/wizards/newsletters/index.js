import '../../shared/js/public-path';

/**
 * Advertising
 */

/**
 * WordPress dependencies.
 */
import { Component, render, Fragment, createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { withWizard } from '../../../packages/components/src';
import Router from '../../../packages/components/src/proxied-imports/router';
import { Settings, Tracking } from './views';

const { HashRouter, Redirect, Route, Switch } = Router;

class NewslettersWizard extends Component {
	/**
	 * Render
	 */
	render() {
		const { pluginRequirements } = this.props;
		const tabs = [
			{
				label: __( 'Settings', 'newspack-plugin' ),
				path: '/',
			},
			{
				label: __( 'Ads Tracking', 'newspack-plugin' ),
				path: '/tracking',
			},
		];
		return (
			<Fragment>
				<HashRouter hashType="slash">
					<Switch>
						{ pluginRequirements }
						<Route
							path="/"
							exact
							render={ () => <Settings headerText={ __( 'Newsletters / Settings', 'newspack-plugin' ) } tabbedNavigation={ tabs } /> }
						/>
						<Route
							path="/tracking"
							render={ () => <Tracking headerText={ __( 'Newsletters / Tracking', 'newspack-plugin' ) } tabbedNavigation={ tabs } /> }
						/>
						<Redirect to="/" />
					</Switch>
				</HashRouter>
			</Fragment>
		);
	}
}
render( createElement( withWizard( NewslettersWizard, [ 'newspack-newsletters' ] ) ), document.getElementById( 'newspack-newsletters' ) );
