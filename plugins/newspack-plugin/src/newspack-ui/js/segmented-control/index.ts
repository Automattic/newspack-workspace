import { domReady, setupTabController } from '../utils';

domReady( function () {
	[ ...document.querySelectorAll( '.newspack-ui__segmented-control' ) ].forEach( element =>
		setupTabController( element, {
			list: 'newspack-ui__segmented-control__tabs',
			content: 'newspack-ui__segmented-control__content',
		} )
	);
} );
