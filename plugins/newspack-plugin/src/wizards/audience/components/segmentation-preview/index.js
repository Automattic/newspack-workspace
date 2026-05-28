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
		if ( iframeEl ) {
			[ ...iframeEl.contentWindow.document.querySelectorAll( 'a' ) ].forEach( anchor => {
				const href = anchor.getAttribute( 'href' );
				if ( href.indexOf( frontendUrl ) === 0 ) {
					anchor.setAttribute( 'href', decorateUrl( href ) );
				}
			} );
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
