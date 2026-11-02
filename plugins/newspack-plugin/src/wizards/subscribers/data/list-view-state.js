/**
 * The subscriber list's last view, kept for the page session.
 *
 * Opening a profile unmounts the list, so a view held only in component state
 * resets its filters, search, sort and page on the way back. Held here instead,
 * the view survives every route back to the list: the back button, the
 * profile's back link, or an edited URL, since a hash route change never
 * reloads the page. A full page load starts again from the default view.
 */

let savedView = null;

/**
 * The view the list last reported, or null if none has been saved.
 *
 * @return {Object|null} The saved DataViews view.
 */
export const getSavedListView = () => savedView;

/**
 * Remember the list's current view.
 *
 * @param {Object} view The DataViews view.
 */
export const saveListView = view => {
	savedView = view;
};

/**
 * Forget the saved view. For tests, which share this module's state.
 */
export const resetSavedListView = () => {
	savedView = null;
};
