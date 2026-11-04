/**
 * Whether a core/post-date block shows the post's modified date.
 *
 * Mirrors the detection in Post_Date::filter_post_date_block() so the editor
 * labels the same blocks the front end does.
 *
 * @param {Object} attributes Block attributes.
 * @return {boolean} True for a modified date block.
 */
export const isModifiedDate = ( attributes = {} ) =>
	'modified' === attributes.metadata?.bindings?.datetime?.args?.field ||
	'modified' === attributes.displayType ||
	( attributes.className || '' ).split( ' ' ).includes( 'wp-block-post-date__modified-date' );

/**
 * Split the "Updated %s" label into the text before and after the date, so
 * translations that put the date first still read correctly.
 *
 * @param {string} label Translated label containing one %s.
 * @return {{before: string, after: string}} Text around the date.
 */
export const getLabelParts = label => {
	const [ before = '', after = '' ] = label.split( '%s' );
	return { before, after };
};
