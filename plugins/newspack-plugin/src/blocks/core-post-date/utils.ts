/**
 * Attributes of a core/post-date block, limited to the members read when
 * detecting a modified-date block.
 */
export type PostDateAttributes = {
	metadata?: { bindings?: { datetime?: { args?: { field?: string } } } };
	displayType?: string;
	className?: string;
};

/**
 * Whether a core/post-date block shows the post's modified date.
 *
 * Mirrors the detection in Post_Date::filter_post_date_block() so the editor
 * labels the same blocks the front end does.
 *
 * @param attributes Block attributes.
 * @return True for a modified date block.
 */
export const isModifiedDate = ( attributes: PostDateAttributes = {} ): boolean =>
	'modified' === attributes.metadata?.bindings?.datetime?.args?.field ||
	'modified' === attributes.displayType ||
	( attributes.className || '' ).split( ' ' ).includes( 'wp-block-post-date__modified-date' );

/**
 * Split the "Updated %s" label into the text before and after the date, so
 * translations that put the date first still read correctly.
 *
 * @param label Translated label containing one %s.
 * @return Text around the date.
 */
export const getLabelParts = ( label: string ): { before: string; after: string } => {
	const [ before = '', after = '' ] = label.split( '%s' );
	return { before, after };
};
