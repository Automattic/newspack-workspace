/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { compose } from '@wordpress/compose';
import { withSelect, withDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * External dependencies
 */
import { once } from 'lodash';

/**
 * Internal dependencies
 */
import Sender from './sender';
import SendTo from './send-to';
import { getServiceProvider } from '../../service-providers';
import withApiHandler from '../../components/with-api-handler';
import { fetchNewsletterData, updateNewsletterData, useIsRetrieving, useNewsletterData, useNewsletterDataError } from '../store';
import { isSupportedESP } from '../utils';
import './style.scss';

const Sidebar = ( {
	isConnected,
	oauthUrl,
	onAuthorize,
	inFlight,
	errors,
	editPost,
	title,
	meta,
	senderEmail,
	senderName,
	status,
	campaignName,
	previewText,
	savePost,
	stringifiedCampaignDefaults,
	postId,
} ) => {
	const [ plainTextTitle, setPlainTextTitle ] = useState( null );
	const isRetrieving = useIsRetrieving();
	const { newsletterData } = useNewsletterData();
	const newsletterDataError = useNewsletterDataError();
	const campaign = newsletterData?.campaign;
	const updateMeta = toUpdate => editPost( { meta: toUpdate } );
	const entityConverter = useRef( null );

	// Create a temp textarea element that we can use to convert HTML entities like &amp; to unicode characters.
	useEffect( () => {
		if ( entityConverter.current ) {
		} else {
			entityConverter.current = document.createElement( 'textarea' );
		}
		return () => entityConverter?.current?.remove && entityConverter.current.remove(); // Clean up temp element from DOM on unmount.
	}, [] );

	// Decode HTML entities in title.
	useEffect( () => {
		entityConverter.current.innerHTML = title;
		setPlainTextTitle( entityConverter.current.value );
	}, [ title ] );

	// Encode HTML entities in title.
	useEffect( () => {
		if ( null !== plainTextTitle ) {
			entityConverter.current.innerText = plainTextTitle;
			editPost( { title: entityConverter.current.innerHTML } );
		}
	}, [ plainTextTitle ] );

	// Reconcile stored campaign data with data fetched from ESP.
	useEffect( () => {
		const updatedMeta = {};
		const updatedNewsletterData = { ...newsletterData };

		if ( newsletterData?.senderEmail ) {
			updatedMeta.senderEmail = newsletterData.senderEmail;
			delete updatedNewsletterData.senderEmail;
		}
		if ( newsletterData?.senderName ) {
			updatedMeta.senderName = newsletterData.senderName;
			delete updatedNewsletterData.senderName;
		}
		if ( newsletterData?.send_list_id ) {
			updatedMeta.send_list_id = newsletterData.send_list_id;
			delete updatedNewsletterData.send_list_id;
		}
		if ( newsletterData?.send_sublist_id ) {
			updatedMeta.send_sublist_id = newsletterData.send_sublist_id;
			delete updatedNewsletterData.send_sublist_id;
		}
		if ( Object.keys( updatedMeta ).length ) {
			updateMeta( updatedMeta );
		}
		if ( Object.keys( updatedNewsletterData ).length ) {
			updateNewsletterData( updatedNewsletterData );
		}
	}, [ newsletterData?.senderEmail, newsletterData?.senderName, newsletterData?.send_list_id, newsletterData?.send_sublist_id ] );

	useEffect( () => {
		if ( stringifiedCampaignDefaults ) {
			const campaignDefaults =
				'string' === typeof stringifiedCampaignDefaults ? JSON.parse( stringifiedCampaignDefaults ) : stringifiedCampaignDefaults;
			const updatedMeta = {};
			if ( campaignDefaults?.senderEmail ) {
				updatedMeta.senderEmail = campaignDefaults.senderEmail;
			}
			if ( campaignDefaults?.senderName ) {
				updatedMeta.senderName = campaignDefaults.senderName;
			}
			if ( campaignDefaults?.send_list_id ) {
				updatedMeta.send_list_id = campaignDefaults.send_list_id;
			}
			if ( campaignDefaults?.send_sublist_id ) {
				updatedMeta.send_sublist_id = campaignDefaults.send_sublist_id;
			}
			if ( Object.keys( updatedMeta ).length ) {
				updatedMeta.stringifiedCampaignDefaults = '';
				updateMeta( updatedMeta );
				savePost();
			}
		}
	}, [ stringifiedCampaignDefaults ] );

	const getCampaignName = () => {
		if ( typeof campaignName === 'string' ) {
			return campaignName;
		}
		return 'Newspack Newsletter (' + postId + ')';
	};

	if ( false === isConnected ) {
		return (
			<>
				<p>{ __( 'You must authorize your account before publishing your newsletter.', 'newspack-newsletters' ) }</p>
				<Button
					variant="primary"
					disabled={ inFlight }
					onClick={ () => {
						const authWindow = window.open( oauthUrl, 'esp_oauth', 'width=500,height=600' );
						authWindow.opener = { verify: once( onAuthorize ) };
					} }
				>
					{ __( 'Authorize', 'newspack-newsletter' ) }
				</Button>
			</>
		);
	}

	if ( ! campaign && newsletterDataError?.message ) {
		return (
			<div className="newspack-newsletters__sidebar">
				<Notice status="error" isDismissible={ false }>
					{ __( 'There was an error retrieving campaign data. Please try again.', 'newspack-newsletters' ) }
				</Notice>
				<Button
					variant="primary"
					disabled={ inFlight || isRetrieving }
					onClick={ () => {
						fetchNewsletterData( postId );
					} }
				>
					{ isRetrieving
						? __( 'Retrieving campaign data…', 'newspack-newsletter' )
						: __( 'Retrieve campaign data', 'newspack-newsletter' ) }
				</Button>
			</div>
		);
	}

	if ( ! campaign && ! newsletterDataError?.message ) {
		return (
			<div className="newspack-newsletters__loading-data">
				{ __( 'Retrieving campaign data…', 'newspack-newsletters' ) }
				<Spinner />
			</div>
		);
	}

	const { ProviderSidebar = () => null, isCampaignSent } = getServiceProvider();
	const campaignIsSent = ! inFlight && newsletterData && isCampaignSent && isCampaignSent( newsletterData, status );

	if ( campaignIsSent ) {
		return (
			<Notice status="success" isDismissible={ false }>
				{ __( 'Campaign has been sent.', 'newspack-newsletters' ) }
			</Notice>
		);
	}

	return (
		<div className="newspack-newsletters__sidebar">
			<VStack spacing={ 4 }>
				<TextControl
					label={ __( 'Campaign Name', 'newspack-newsletters' ) }
					className="newspack-newsletters__campaign-name-textcontrol"
					value={ getCampaignName() }
					placeholder={ 'Newspack Newsletter (' + postId + ')' }
					disabled={ inFlight }
					onChange={ value => updateMeta( { campaign_name: value } ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Subject', 'newspack-newsletters' ) }
					className="newspack-newsletters__subject-textcontrol"
					value={ plainTextTitle }
					disabled={ inFlight }
					onChange={ setPlainTextTitle }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<TextareaControl
					label={ __( 'Preview text', 'newspack-newsletters' ) }
					className="newspack-newsletters__preview-textcontrol"
					value={ previewText }
					disabled={ inFlight }
					onChange={ value => updateMeta( { preview_text: value } ) }
					__nextHasNoMarginBottom
				/>
				<ProviderSidebar inFlight={ inFlight } postId={ postId } meta={ meta } updateMeta={ updateMeta } />
				<hr />
				<Sender errors={ errors } senderEmail={ senderEmail } senderName={ senderName } updateMeta={ updateMeta } postStatus={ status } />
				{ isSupportedESP() && <SendTo /> }
			</VStack>
		</div>
	);
};

export default compose( [
	withApiHandler(),
	withSelect( select => {
		const { getCurrentPostAttribute, getCurrentPostId, getEditedPostAttribute } = select( 'core/editor' );
		const meta = getEditedPostAttribute( 'meta' );
		return {
			title: getEditedPostAttribute( 'title' ),
			postId: getCurrentPostId(),
			meta,
			senderEmail: meta.senderEmail,
			senderName: meta.senderName,
			campaignName: meta.campaign_name,
			previewText: meta.preview_text || '',
			status: getCurrentPostAttribute( 'status' ),
			stringifiedCampaignDefaults: meta.stringifiedCampaignDefaults || {},
		};
	} ),
	withDispatch( dispatch => {
		const { editPost, savePost } = dispatch( 'core/editor' );
		const { createErrorNotice } = dispatch( 'core/notices' );
		return { editPost, savePost, createErrorNotice };
	} ),
] )( Sidebar );
