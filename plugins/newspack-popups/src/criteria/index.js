/* globals newspackPopupsCriteria */

import { registerCriteria } from './utils';

// Default criteria matching strategy.
import './default';

// Initialize the criteria object.
newspackPopupsCriteria.criteria = {};

// Register criteria from the global newspackPopupsCriteria object.
if ( newspackPopupsCriteria?.config ) {
	for ( const id in newspackPopupsCriteria.config ) {
		registerCriteria( id, newspackPopupsCriteria.config[ id ] );
	}
}
