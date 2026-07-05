import React from 'react';
import { HashRouter as Router } from 'react-router-dom';

import { render, createElement, Component } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

import './index.scss';
import Brands from './views/brands';

class App extends Component< Record< string, never > > {
	render() {
		return (
			<React.StrictMode>
				<Router>
					<Brands />
				</Router>
			</React.StrictMode>
		);
	}
}

domReady( () => {
	// The root element is rendered server-side by the admin page template, so it's always present.
	render( createElement( App ), document.getElementById( 'root' ) as HTMLElement );
} );
