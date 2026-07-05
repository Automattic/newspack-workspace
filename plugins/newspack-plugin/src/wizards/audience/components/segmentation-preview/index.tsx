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

/**
 * External dependencies.
 */
import type { ComponentProps } from 'react';

type SegmentationPreviewProps = Omit< Partial< ComponentProps< typeof WebPreview > >, 'onLoad' > & {
	/** Campaign (group) id to restrict the previewed prompts to, or false for all. */
	campaign?: number | false;
	/** Called with the iframe element once the previewed page has loaded. */
	onLoad?: ( iframeEl: HTMLIFrameElement | null ) => void;
	/** Segment id to preview as (empty string previews as "everyone"). */
	segment?: string;
	/** Whether to show unpublished prompts (when previewing a specific campaign). */
	showUnpublished?: boolean;
};

const SegmentationPreview = ( props: SegmentationPreviewProps ) => {
	const [ decoratedUrl, setDecoratedUrl ] = useState< string | null >( null );
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

	const decorateUrl = ( urlToDecorate: string ) => {
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

	const onWebPreviewLoad = ( iframeEl: HTMLIFrameElement | null ) => {
		if ( iframeEl ) {
			// The iframe has loaded, so its content window is available.
			[ ...iframeEl.contentWindow!.document.querySelectorAll( 'a' ) ].forEach( anchor => {
				// Content links always carry an href.
				const href = anchor.getAttribute( 'href' )!;
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
			// Decorated on mount, before the preview can be opened.
			url={ decoratedUrl! }
		/>
	);
};

export default SegmentationPreview;
