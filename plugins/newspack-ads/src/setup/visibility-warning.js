/**
 * WordPress dependencies
 */
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';
import { __ } from '@wordpress/i18n';

const AD_BLOCK = 'newspack-ads/ad-unit';
const NOTICE_ID = 'newspack-ads/ad-visibility-warning';

const AdVisibilityWarning = () => {
	const hasHiddenAdContainer = useSelect( select => {
		const { getClientIdsWithDescendants, getBlockName, getBlockParents, getBlockAttributes } = select( 'core/block-editor' );
		const isHidden = clientId => {
			const viewport = getBlockAttributes( clientId )?.metadata?.blockVisibility?.viewport;
			return viewport && Object.values( viewport ).some( visible => visible === false );
		};
		return getClientIdsWithDescendants().some( id => {
			if ( getBlockName( id ) !== AD_BLOCK ) {
				return false;
			}
			return [ id, ...getBlockParents( id ) ].some( isHidden );
		} );
	}, [] );

	const { createWarningNotice, removeNotice } = useDispatch( 'core/notices' );

	useEffect( () => {
		if ( hasHiddenAdContainer ) {
			createWarningNotice(
				__(
					'A hidden block contains an ad unit. Ads and their containers must stay visible at all breakpoints to avoid penalties — use your ad provider settings to control ad visibility instead.',
					'newspack-ads'
				),
				{ id: NOTICE_ID, isDismissible: false }
			);
		} else {
			removeNotice( NOTICE_ID );
		}
	}, [ hasHiddenAdContainer, createWarningNotice, removeNotice ] );

	return null;
};

registerPlugin( 'newspack-ads-visibility-warning', { render: AdVisibilityWarning } );
