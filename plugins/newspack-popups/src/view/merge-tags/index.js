import { domReady } from '../utils';
import { getCriteria } from '../../criteria/utils';

window.newspackRAS = window.newspackRAS || [];
window.newspackRAS.push( ras => {
	function attachCriteria( mergeTag ) {
		const criteria = getCriteria( mergeTag.dataset.criteria );
		if ( ! criteria ) {
			return;
		}
		mergeTag.innerHTML = criteria.getValue( ras );
		ras.on( 'data', () => {
			mergeTag.innerHTML = criteria.getValue( ras );
		} );
	}

	domReady( () => {
		const mergeTags = document.querySelectorAll( '.merge-tag' );
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
