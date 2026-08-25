/**
 * Segmentation Preview component.
 * Extension of WebPreview with support for "view-as-segment" functionality.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies.
 */
import { WebPreview } from '../../../../../packages/components/src';

const SegmentationPreview = props => {
	const [ decoratedUrl, setDecoratedUrl ] = useState( null );
	const [ isOpen, setIsOpen ] = useState( false );
	const [ sessionId, setSessionId ] = useState( Math.floor( Math.random() * 9999 ) ); // A random ID that can be used to tie together all pageviews in a single preview session.
	const postPreviewLink = window?.newspackAudienceCampaigns?.preview_post;
	const frontendUrl = window?.newspackAudienceCampaigns?.frontend_url || '/';

	const { campaign = false, onLoad = () => {}, segment = '', showUnpublished = false, url = postPreviewLink || frontendUrl } = props;

	useEffect( () => {
		if ( ! isOpen ) {
			setDecoratedUrl( decorateUrl( url ) );
		}
	}, [ isOpen ] );

	const decorateUrl = urlToDecorate => {
		const view_as = segment.length ? [ `segment:${ segment }` ] : [ 'segment:everyone' ];

		if ( showUnpublished ) {
			view_as.push( 'show_unpublished:true' );
		}

		// If passed campaign ID, get only prompts matching that campaign. Otherwise, get all prompts.
		if ( campaign ) {
			view_as.push( `campaign:${ campaign }` );
		} else {
			view_as.push( 'all' );
		}

		view_as.push( 'session_id:' + sessionId );

		return addQueryArgs( urlToDecorate, { view_as: view_as.join( ';' ) } );
	};

	const onWebPreviewLoad = iframeEl => {
		if ( ! iframeEl ) {
			return;
		}

		// Reaching into the frame can throw — a genuinely cross-origin setup today,
		// and cross-origin isolation if WordPress ever extends it past the
		// post-editor screens it currently covers. The `finally` keeps the original
		// ordering while guaranteeing the state update still runs: without it a
		// throw left `isOpen` false, so the effect that recomputes the decorated URL
		// never re-ran and every reopened preview reused the first session ID.
		try {
			const frameDoc = iframeEl.contentWindow.document;
			// Loop-invariant: both operands are fixed for the whole pass, and a
			// preview page can easily carry a few hundred anchors.
			const referenceOrigin = new URL( frontendUrl, frameDoc.baseURI ).origin;
			[ ...frameDoc.querySelectorAll( 'a[href]' ) ].forEach( anchor => {
				const href = anchor.getAttribute( 'href' );
				if ( href.startsWith( '#' ) ) {
					return;
				}
				let target;
				try {
					target = new URL( href, frameDoc.baseURI );
				} catch ( e ) {
					return;
				}
				// Compare origins rather than prefix-matching `frontendUrl`, which
				// falls back to '/' — under that fallback a protocol-relative
				// off-site href like //example.com also "starts with" it, and would
				// carry the segment and session IDs to a third party.
				if ( target.origin !== referenceOrigin ) {
					return;
				}
				anchor.setAttribute( 'href', decorateUrl( target.toString() ) );
			} );
		} catch ( e ) {
			// eslint-disable-next-line no-console
			console.warn( 'Segmentation preview: could not rewrite in-iframe links.', e );
		} finally {
			setIsOpen( true );
			onLoad( iframeEl );
		}
	};

	return (
		<WebPreview
			{ ...props }
			onLoad={ onWebPreviewLoad }
			onClose={ () => {
				setSessionId( Math.floor( Math.random() * 9999 ) ); // Reset session ID when the preview is closed.
				setIsOpen( false );
			} }
			url={ decoratedUrl }
		/>
	);
};

export default SegmentationPreview;
