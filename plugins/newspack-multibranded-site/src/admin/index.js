import React from 'react';
import { HashRouter as Router } from 'react-router-dom';

import { render, createElement, Component } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

import './index.scss';
import Brands from './views/brands';

class App extends Component {
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
	render( createElement( App ), document.getElementById( 'root' ) );
} );
