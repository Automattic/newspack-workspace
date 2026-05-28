/**
 * Component for displaying attachment information.
 */

import { __ } from '@wordpress/i18n';
import { Button, Spinner, ExternalLink, Dashicon } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import PropTypes from 'prop-types';

const attachmentCache = new Map();

const CollectionMetaAttachmentInfo = ( { attachmentId, onRemove } ) => {
	const [ attachmentInfo, setAttachmentInfo ] = useState( () => attachmentCache.get( attachmentId ) || null );
	const [ loading, setLoading ] = useState( false );

	useEffect( () => {
		if ( ! attachmentId || attachmentInfo ) {
			return;
		}

		setLoading( true );
		wp.apiFetch( { path: `/wp/v2/media/${ attachmentId }` } )
			.then( media => {
				const info = {
					url: media.source_url || media.url || '',
					title: media?.title?.rendered || 'file',
				};
				attachmentCache.set( attachmentId, info );
				setAttachmentInfo( info );
			} )
			.catch( () => setAttachmentInfo( null ) )
			.finally( () => setLoading( false ) );
	}, [ attachmentId, attachmentInfo ] );

	if ( loading ) {
		return <Spinner />;
	}

	if ( ! attachmentInfo?.url || ! attachmentInfo?.title ) {
		return <span>File info unavailable</span>;
	}

	return (
		<div className="attachment-info">
			<Dashicon icon="pdf" />
			<ExternalLink className="attachment-name" href={ attachmentInfo.url }>
				{ attachmentInfo.title }
			</ExternalLink>
			{ onRemove && <Button isSmall isDestructive onClick={ onRemove } icon="no-alt" label={ __( 'Remove attachment', 'newspack-plugin' ) } /> }
		</div>
	);
};

CollectionMetaAttachmentInfo.propTypes = {
	attachmentId: PropTypes.oneOfType( [ PropTypes.string, PropTypes.number ] ).isRequired,
	onRemove: PropTypes.func,
};

export default CollectionMetaAttachmentInfo;
export { attachmentCache };
