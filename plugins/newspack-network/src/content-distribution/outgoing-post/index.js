/* eslint @wordpress/valid-sprintf: 0 */

/* globals newspack_network_outgoing_post */

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { sprintf, __, _n } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	CheckboxControl,
	TextControl,
	Button,
	Notice,
} from '@wordpress/components';
import { broadcast } from '../../icons';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies.
 */
import ContentDistributionPanel from '../content-distribution-panel';
import PostStatus from '../../components/post-status';

const defaultStatus = newspack_network_outgoing_post.default_status;
const networkSites = newspack_network_outgoing_post.network_sites;
const distributedMetaKey = newspack_network_outgoing_post.distributed_meta;
const postTypeLabel = newspack_network_outgoing_post.post_type_label;

function OutgoingPost() {
	const [ search, setSearch ] = useState( '' );
	const [ isDistributing, setIsDistributing ] = useState( false );
	const [ distribution, setDistribution ] = useState( [] );
	const [ siteSelection, setSiteSelection ] = useState( [] );
	const [ statusOnPublish, setStatusOnPublish ] = useState( defaultStatus );

	const {
		postId,
		postStatus,
		savedUrls,
		hasChangedContent,
		isSavingPost,
		isCleanNewPost,
	} = useSelect( ( select ) => {
		const {
			getCurrentPostId,
			getCurrentPostAttribute,
			hasChangedContent: _hasChangedContent,
			isSavingPost: _isSavingPost,
			isCleanNewPost: _isCleanNewPost,
		} = select( 'core/editor' );
		return {
			postId: getCurrentPostId(),
			postStatus: getCurrentPostAttribute( 'status' ),
			savedUrls:
				getCurrentPostAttribute( 'meta' )?.[ distributedMetaKey ] || [],
			hasChangedContent: _hasChangedContent(),
			isSavingPost: _isSavingPost(),
			isCleanNewPost: _isCleanNewPost(),
		};
	} );

	useEffect( () => {
		setSiteSelection( [] );
	}, [ postId ] );

	useEffect( () => {
		setDistribution( savedUrls );
		// Create notice if the post has been distributed.
		if ( savedUrls.length > 0 ) {
			createNotice(
				'warning',
				sprintf(
					// translators: %s: post type; %d: number of network sites
					_n(
						'This %s is distributed to one network site.',
						'This %s is distributed to %d network sites.',
						savedUrls.length,
						'newspack-network'
					),
					postTypeLabel.toLowerCase(),
					savedUrls.length
				),
				{
					id: 'newspack-network-distributed-notice',
				}
			);
		}
	}, [ savedUrls ] );

	const { savePost } = useDispatch( 'core/editor' );
	const { createNotice } = useDispatch( 'core/notices' );

	const sites = networkSites.filter( ( url ) => url.includes( search ) );

	const selectableSites = networkSites.filter(
		( url ) => ! distribution.includes( url )
	);

	const isUnpublished = postStatus !== 'publish';

	const isAutoDraft = postStatus === 'auto-draft';

	const isDisabled =
		isSavingPost || isDistributing || isCleanNewPost || isAutoDraft;

	const getFormattedSite = ( site ) => {
		const url = new URL( site );
		return url.hostname;
	};

	const distribute = () => {
		setIsDistributing( true );
		apiFetch( {
			path: `newspack-network/v1/content-distribution/distribute/${ postId }`,
			method: 'POST',
			data: {
				urls: siteSelection,
				status_on_publish: statusOnPublish,
			},
		} )
			.then( ( urls ) => {
				setDistribution( urls );
				setSiteSelection( [] );
				createNotice(
					'info',
					sprintf(
						// translators: %s: post type; %d: number of network sites
						_n(
							'%s distributed to one network site.',
							'%s distributed to %d network sites.',
							urls.length,
							'newspack-network'
						),
						postTypeLabel,
						urls.length
					),
					{
						type: 'snackbar',
						isDismissible: true,
					}
				);
			} )
			.catch( ( error ) => {
				createNotice( 'error', error.message );
			} )
			.finally( () => {
				setIsDistributing( false );
			} );
	};

	return (
		<ContentDistributionPanel
			header={
				<>
					{ isAutoDraft && (
						<>
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'Save the post at least once before distributing it.',
									'newspack-network'
								) }
							</Notice>
							<hr />
						</>
					) }
					{ ! distribution.length ? (
						<p>
							{ networkSites.length === 1
								? sprintf(
										// translators: %s: post type
										__(
											'This %s has not been distributed to your network site yet.',
											'newspack-network'
										),
										postTypeLabel.toLowerCase()
								  )
								: sprintf(
										// translators: %s: post type
										__(
											'This %s has not been distributed to any network sites yet.',
											'newspack-network'
										),
										postTypeLabel.toLowerCase()
								  ) }
						</p>
					) : (
						<p>
							{ sprintf(
								// translators: %s: post type; %d: number of network sites
								_n(
									'This %s has been distributed to one network site.',
									'This %s has been distributed to %d network sites.',
									distribution.length,
									'newspack-network'
								),
								postTypeLabel.toLowerCase(),
								distribution.length
							) }
						</p>
					) }
					{ networkSites.length > 5 && (
						<TextControl
							__next40pxDefaultSize
							placeholder={ __(
								'Search available network sites',
								'newspack-network'
							) }
							value={ search }
							disabled={ isDisabled }
							onChange={ setSearch }
						/>
					) }
				</>
			}
			body={
				<>
					{ networkSites.length > 1 &&
						selectableSites.length !== 0 &&
						sites.length === networkSites.length && (
							<CheckboxControl
								name="select-all"
								label={ __( 'Select all', 'newspack-network' ) }
								disabled={ isDisabled }
								checked={
									siteSelection.length ===
									selectableSites.length
								}
								indeterminate={
									siteSelection.length > 0 &&
									siteSelection.length <
										selectableSites.length
								}
								onChange={ ( checked ) => {
									setSiteSelection(
										checked ? selectableSites : []
									);
								} }
							/>
						) }
					{ sites.map( ( siteUrl ) => (
						<CheckboxControl
							key={ siteUrl }
							label={ getFormattedSite( siteUrl ) }
							disabled={
								isDisabled || distribution.includes( siteUrl )
							} // Do not allow undistributing a site.
							checked={
								siteSelection.includes( siteUrl ) ||
								distribution.includes( siteUrl )
							}
							onChange={ ( checked ) => {
								const urls = checked
									? [ ...siteSelection, siteUrl ]
									: siteSelection.filter(
											( url ) => siteUrl !== url
									  );
								setSiteSelection( urls );
							} }
						/>
					) ) }
				</>
			}
			footer={
				<>
					{ siteSelection.length > 0 && (
						<p className="selected-sites">
							<span>
								{ sprintf(
									// translators: %d: number of network sites
									_n(
										'One network site selected.',
										'%d network sites selected.',
										siteSelection.length,
										'newspack-network'
									),
									siteSelection.length
								) }
							</span>
							<button onClick={ () => setSiteSelection( [] ) }>
								Clear
							</button>
						</p>
					) }
				</>
			}
			buttons={
				<>
					<PostStatus
						label={
							isUnpublished
								? __( 'Status on publish', 'newspack-network' )
								: null
						}
						description={
							isUnpublished
								? __(
										"Which status to set the post when it's published.",
										'newspack-network'
								  )
								: null
						}
						status={ statusOnPublish }
						onChange={ setStatusOnPublish }
						disabled={ isDisabled }
					/>
					<Button
						isBusy={ isDistributing }
						variant="primary"
						disabled={ isDisabled || siteSelection.length === 0 }
						onClick={ () => {
							if ( hasChangedContent || isCleanNewPost ) {
								savePost().then( distribute );
							} else {
								distribute();
							}
						} }
					>
						{ isDistributing
							? __( 'Distributing…', 'newspack-network' )
							: hasChangedContent || isCleanNewPost
							? __( 'Save & Distribute', 'newspack-network' )
							: __( 'Distribute', 'newspack-network' ) }
					</Button>
				</>
			}
		/>
	);
}

registerPlugin( 'newspack-network-outgoing-post', {
	render: OutgoingPost,
	icon: broadcast,
} );
