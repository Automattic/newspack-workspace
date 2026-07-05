/**
 * Component for displaying attachment information.
 */

import { __ } from '@wordpress/i18n';
import { Button, Spinner, ExternalLink, Dashicon } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import PropTypes from 'prop-types';

/** Resolved attachment display info. */
export type AttachmentInfo = {
	url: string;
	title: string;
};

/** Subset of the REST API media object consumed here. */
type MediaResponse = {
	source_url?: string;
	url?: string;
	title?: {
		rendered?: string;
	};
};

const attachmentCache = new Map< number | string, AttachmentInfo >();

type CollectionMetaAttachmentInfoProps = {
	attachmentId: number | string;
	onRemove?: () => void;
};

const CollectionMetaAttachmentInfo = ( { attachmentId, onRemove }: CollectionMetaAttachmentInfoProps ) => {
	const [ attachmentInfo, setAttachmentInfo ] = useState< AttachmentInfo | null >( () => attachmentCache.get( attachmentId ) || null );
	const [ loading, setLoading ] = useState( false );

	useEffect( () => {
		if ( ! attachmentId || attachmentInfo ) {
			return;
		}

		setLoading( true );
		apiFetch< MediaResponse >( { path: `/wp/v2/media/${ attachmentId }` } )
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
