/**
 * URL-driven initial view state for the Layouts list.
 *
 * Mirrors the other lists' shape so URL-shareable filter / sort
 * state is supported, even though the layouts CPT has no legacy
 * admin URL to redirect from.
 */

import { makeGetInitialView } from '../../utils/initial-view';

const ORDERBY_TO_SORT_FIELD = {
	title: 'title',
	modified: 'modified',
	date: 'date',
};

export const { getInitialView } = makeGetInitialView( {
	orderbyMap: ORDERBY_TO_SORT_FIELD,
} );
