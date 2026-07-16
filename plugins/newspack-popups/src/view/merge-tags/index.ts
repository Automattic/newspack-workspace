import { domReady } from '../utils';
import { getCriteria } from '../../criteria/utils';

window.newspackRAS = window.newspackRAS || [];
window.newspackRAS.push( ras => {
	function attachCriteria( mergeTag: HTMLElement ) {
		const criteria = getCriteria( mergeTag.dataset.criteria! );
		if ( ! criteria ) {
			return;
		}
		// `criteria.getValue()` returns `unknown` (a criteria's value can be any matching-attribute
		// shape); `.innerHTML` assignment coerces to string either way, so narrow at this boundary.
		mergeTag.innerHTML = criteria.getValue( ras ) as string;
		ras.on( 'data', () => {
			mergeTag.innerHTML = criteria.getValue( ras ) as string;
		} );
	}

	domReady( () => {
		const mergeTags = document.querySelectorAll< HTMLElement >( '.merge-tag' );
		if ( ! mergeTags.length ) {
			return;
		}
		for ( const mergeTag of mergeTags ) {
			if ( ! mergeTag.dataset.criteria ) {
				continue;
			}
			attachCriteria( mergeTag );
		}
	} );
} );
