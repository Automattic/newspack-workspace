/* global newspackBrandSiteEditorNotices */

/**
 * Shows which brands the menu or template part open in the Site Editor affects.
 */

/**
 * WordPress dependencies
 */
import { dispatch, select, subscribe } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';

const NOTICE_ID = 'newspack-brand-notice';
const { notices, backUrl, backLabel } = newspackBrandSiteEditorNotices;

let last = null;

subscribe( () => {
	const editor = select( editorStore );
	const key = `${ editor.getCurrentPostType() }:${ editor.getCurrentPostId() }`;
	if ( key === last ) {
		return;
	}
	last = key;
	dispatch( noticesStore ).removeNotice( NOTICE_ID );
	const notice = notices[ key ];
	if ( notice ) {
		dispatch( noticesStore ).createNotice( notice.status, notice.text, {
			id: NOTICE_ID,
			isDismissible: false,
			speak: false,
			actions: [ { label: backLabel, url: backUrl } ],
		} );
	}
}, editorStore );
