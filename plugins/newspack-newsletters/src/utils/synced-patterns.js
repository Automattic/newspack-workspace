/**
 * WordPress dependencies
 */
import { dispatch, select } from '@wordpress/data';

/**
 * Save every synced pattern that has unsaved edits.
 *
 * The email is rendered on the server from each pattern's stored content, and
 * `savePost()` saves the newsletter alone. An author can leave a pattern
 * edited but unsaved by going back from "Edit original", so anything that
 * renders or sends the email calls this first. Otherwise the email carries the
 * pattern as last saved while the canvas shows the edit.
 *
 * @return {Promise<void>} Rejects if a pattern fails to save, so the caller can stop before sending.
 */
export const saveEditedSyncedPatterns = async () => {
	const editedPatterns = select( 'core' )
		.__experimentalGetDirtyEntityRecords()
		.filter( ( { kind, name } ) => 'postType' === kind && 'wp_block' === name );
	await Promise.all(
		editedPatterns.map( ( { key } ) => dispatch( 'core' ).saveEditedEntityRecord( 'postType', 'wp_block', key, { throwOnError: true } ) )
	);
};
