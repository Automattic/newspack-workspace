import { domReady, setupTabController } from '../utils';

domReady( function () {
	[ ...document.querySelectorAll( '.newspack-ui__tabs' ) ].forEach( element =>
		setupTabController( element, {
			list: 'newspack-ui__tabs__list',
			content: 'newspack-ui__tabs__content',
		} )
	);
} );
