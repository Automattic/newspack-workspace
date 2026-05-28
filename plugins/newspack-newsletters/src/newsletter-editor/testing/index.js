/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { withSelect, withDispatch } from '@wordpress/data';
import { compose } from '@wordpress/compose';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	TextControl,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { hasValidEmail, usePrevious } from '../utils';

/**
 * Internal dependencies
 */
import withApiHandler from '../../components/with-api-handler';
import { useIsRefreshingHtml, useNewsletterData } from '../store';
import './style.scss';

const serviceProvider = window && window.newspack_newsletters_data && window.newspack_newsletters_data.service_provider;

export default compose( [
	withApiHandler(),
	withSelect( select => {
		const { getCurrentPostId } = select( 'core/editor' );
		return { postId: getCurrentPostId() };
	} ),
	withDispatch( dispatch => {
		const { savePost } = dispatch( 'core/editor' );
		return {
			savePost,
		};
	} ),
] )( ( { apiFetchWithErrorHandling, inFlight, postId, savePost, setInFlightForAsync, testEmail, onChangeEmail, disabled, inlineNotifications } ) => {
	const isRefreshingHtml = useIsRefreshingHtml();
	const wasRefreshingHtml = usePrevious( isRefreshingHtml );
	const [ shouldSendTest, setShouldSendTest ] = useState( false );
	const [ localInFlight, setLocalInFlight ] = useState( false );
	const [ localMessage, setLocalMessage ] = useState( '' );
	const { supports_multiple_test_recipients: supportsMultipleTestEmailRecipients } = useNewsletterData();

	useEffect( () => {
		if ( wasRefreshingHtml && ! isRefreshingHtml && shouldSendTest ) {
			sendTestEmail();
		}
	}, [ isRefreshingHtml ] );

	const sendTestEmail = async () => {
		const params = {
			path: `/newspack-newsletters/v1/${ serviceProvider }/${ postId }/test`,
			data: {
				test_email: testEmail,
			},
			method: 'POST',
		};
		if ( inlineNotifications ) {
			apiFetch( params )
				.then( res => {
					setLocalMessage( res?.message || __( 'Test email sent.', 'newspack-newsletters' ) );
				} )
				.catch( err => {
					setLocalMessage( err?.message || err?.data?.message || __( 'Error sending test email.', 'newspack-newsletters' ) );
				} )
				.finally( () => {
					setLocalInFlight( false );
					setShouldSendTest( false );
				} );
		} else {
			await apiFetchWithErrorHandling( params );
			setShouldSendTest( false );
		}
	};

	const triggerSave = async () => {
		if ( inlineNotifications ) {
			setLocalInFlight( true );
		} else {
			setInFlightForAsync();
		}
		await savePost();
		setShouldSendTest( true );
	};

	return (
		<VStack spacing={ 4 }>
			<TextControl
				label={ __( 'Send a test to', 'newspack-newsletters' ) }
				help={
					supportsMultipleTestEmailRecipients
						? __( 'Use commas to separate multiple emails. Any unsaved changes will be saved.', 'newspack-newsletters' )
						: __( 'Any unsaved changes will be saved.', 'newspack-newsletters' )
				}
				value={ testEmail }
				type="email"
				onChange={ onChangeEmail }
				disabled={ localInFlight || inFlight }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<div className="newspack-newsletters__testing-controls">
				<Button
					variant="secondary"
					onClick={ triggerSave }
					isBusy={ inFlight || localInFlight }
					disabled={ disabled || ! hasValidEmail( testEmail ) }
					__next40pxDefaultSize
				>
					{ inFlight || localInFlight
						? __( 'Sending test email…', 'newspack-newsletters' )
						: __( 'Send a test email', 'newspack-newsletters' ) }
				</Button>
			</div>
			{ localMessage ? <p className="newspack-newsletters__testing-message">{ localMessage }</p> : null }
		</VStack>
	);
} );
