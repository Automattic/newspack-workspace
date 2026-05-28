/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { ToggleControl, DatePicker } from '@wordpress/components';
import { isInTheFuture } from '@wordpress/date';
import { useEffect, useState, useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { convertDateToString } from './utils';

const ExpirationPanel = ( { expiration_date = null, postStatus, onMetaFieldChange, createNotice, removeNotice } ) => {
	const [ noticeId, setNoticeId ] = useState( false );
	useEffect( () => {
		if ( expiration_date && ! isInTheFuture( expiration_date ) ) {
			if ( noticeId ) {
				return;
			}
			createNotice(
				'warning',
				sprintf(
					/* translators: %s is the expiration date */
					__(
						'This prompt has expired on %s and has been reverted to draft. Publishing it will reset the expiration date.',
						'newspack-plugin'
					),
					new Date( expiration_date ).toLocaleDateString()
				),
				{
					id: 'newspack-popups__expired',
					isDismissible: false,
				}
			).then( ( { notice } ) => {
				setNoticeId( notice.id );
			} );
		}
	}, [ expiration_date ] );

	useEffect( () => {
		if ( postStatus === 'publish' && noticeId ) {
			removeNotice( noticeId );
			createNotice( 'info', __( 'This prompt has been published. The expiration date has been removed.', 'newspack-plugin' ), {
				id: 'newspack-popups__expiration-date-removed',
			} );
			// This is just for quicked feedback, the actual meta field deletion will
			// happen on the backend.
			onMetaFieldChange( { expiration_date: null } );
		}
	}, [ postStatus ] );

	const defaultExpirationDate = useMemo( () => {
		const date = new Date();
		date.setHours( date.getHours() + 24 );
		return convertDateToString( date );
	}, [] );

	return (
		<>
			<ToggleControl
				label={ __( 'Expiration Date', 'newspack-newsletters' ) }
				checked={ !! expiration_date }
				onChange={ () => {
					onMetaFieldChange( { expiration_date: expiration_date ? null : defaultExpirationDate } );
				} }
				help={ __( 'If set, the prompt will be automatically unpublished at midnight on this date.', 'newspack-popups' ) }
			/>
			{ expiration_date ? (
				<DatePicker
					currentDate={ expiration_date }
					onChange={ value => onMetaFieldChange( { expiration_date: convertDateToString( new Date( value ) ) } ) }
					isInvalidDate={ date => ! isInTheFuture( date ) }
				/>
			) : null }
		</>
	);
};

export default ExpirationPanel;
