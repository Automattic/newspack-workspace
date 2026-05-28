/**
 * Nextdoor post editor Plugin
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { Button, Spinner, Notice, Panel, PanelBody, PanelHeader, Flex, FlexItem, SVG } from '@wordpress/components';
import { PluginSidebar } from '@wordpress/editor';
import { registerPlugin } from '@wordpress/plugins';
import { dateI18n, getSettings } from '@wordpress/date';
import apiFetch from '@wordpress/api-fetch';

/**
 * Styles.
 */
import './style.scss';

/**
 * Possible ingestion statuses from Nextdoor.
 * There could be more, any comprehensive list is not available in the API docs.
 */
const INGESTION_STATUSES = {
	VALID: 'valid',
	INVALID: 'invalid',
	UNPROCESSED: 'unprocessed',
	DELETED: 'deleted',
};

/**
 * Component for Nextdoor publishing controls in the post editor sidebar.
 */
const NextdoorPostSidebar = ( { postId, postStatus } ) => {
	const [ isLoading, setIsLoading ] = useState( true );
	const [ action, setAction ] = useState( null );
	const [ nextdoorStatus, setNextdoorStatus ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ success, setSuccess ] = useState( null );

	/**
	 * Fetch Nextdoor status for the current post
	 */
	const fetchStatus = async () => {
		try {
			setIsLoading( true );
			setError( null );

			const response = await apiFetch( {
				path: `/newspack/v1/nextdoor/post-status/${ postId }`,
			} );

			setNextdoorStatus( response );
		} catch ( fetchError ) {
			setError( fetchError.message || __( 'Failed to load Nextdoor status.', 'newspack-plugin' ) );
		} finally {
			setIsLoading( false );
		}
	};

	const callApi = async ( path, method, messages ) => {
		try {
			setAction( method );
			setError( null );
			setSuccess( null );

			const response = await apiFetch( { path, method } );

			if ( response.success ) {
				setSuccess( response.message || messages.success );
				await fetchStatus();
			} else {
				setError( response.message || messages.error );
			}
		} catch ( err ) {
			setError( err.message || __( 'Failed to communicate with Nextdoor.', 'newspack-plugin' ) );
		} finally {
			setAction( null );
			clearMessages();
		}
	};

	/**
	 * Handle publishing post to Nextdoor
	 */
	const handlePublish = () => {
		callApi( `/newspack/v1/nextdoor/publish-post/${ postId }`, 'POST', {
			success: __( 'Published to Nextdoor.', 'newspack-plugin' ),
			error: __( 'Failed to publish.', 'newspack-plugin' ),
		} );
	};

	/**
	 * Handle updating post on Nextdoor
	 */
	const handleUpdate = () => {
		callApi( `/newspack/v1/nextdoor/update-post/${ postId }`, 'PUT', {
			success: __( 'Update sent to Nextdoor.', 'newspack-plugin' ),
			error: __( 'Failed to update.', 'newspack-plugin' ),
		} );
	};

	/**
	 * Handle deleting post from Nextdoor
	 */
	const handleDelete = () => {
		callApi( `/newspack/v1/nextdoor/delete-post/${ postId }`, 'DELETE', {
			success: __( 'Post removed from Nextdoor.', 'newspack-plugin' ),
			error: __( 'Failed to remove post.', 'newspack-plugin' ),
		} );
	};

	/**
	 * Clear messages after a delay
	 */
	const clearMessages = () => {
		setTimeout( () => {
			setError( null );
			setSuccess( null );
		}, 5000 );
	};

	/**
	 * Format date for display
	 */
	const formatDate = dateString => {
		if ( ! dateString ) {
			return '';
		}
		const dateFormat = getSettings().formats.datetimeAbbreviated || 'Y-m-d g:i a';
		return dateI18n( dateFormat, dateString );
	};

	// Load status on mount and when post ID changes
	useEffect( () => {
		if ( postId ) {
			fetchStatus();
		}
	}, [ postId ] );

	/**
	 * Render the main content
	 */
	const renderContent = () => {
		if ( isLoading ) {
			return (
				<Flex justify="center" className="nextdoor-sidebar__loading">
					<FlexItem>
						<Spinner />
					</FlexItem>
					<FlexItem>
						<p>{ __( 'Loading Nextdoor status…', 'newspack-plugin' ) }</p>
					</FlexItem>
				</Flex>
			);
		}

		if ( ! nextdoorStatus?.can_publish ) {
			return (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'You do not have permission to publish to Nextdoor. Please contact the site administrator.', 'newspack-plugin' ) }
				</Notice>
			);
		}

		if ( 'publish' !== postStatus ) {
			return (
				<Notice status="info" isDismissible={ false }>
					{ __( 'Post must be published before sharing to Nextdoor.', 'newspack-plugin' ) }
				</Notice>
			);
		}

		return (
			<>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

				{ success && (
					<Notice status="success" isDismissible={ false }>
						{ success }
					</Notice>
				) }

				{ nextdoorStatus?.is_shared ? (
					<Panel>
						<PanelHeader>
							<p className="nextdoor-sidebar__status-header">
								{ __( 'Status:', 'newspack-plugin' ) } { nextdoorStatus.ingestion_status }
							</p>
						</PanelHeader>
						<PanelBody>
							<div className="nextdoor-sidebar__status-text">
								{ INGESTION_STATUSES.VALID === nextdoorStatus.ingestion_status && (
									<p>{ __( 'This post is available in your Nextdoor community.', 'newspack-plugin' ) }</p>
								) }
								{ INGESTION_STATUSES.INVALID === nextdoorStatus.ingestion_status && nextdoorStatus.ingestion_errors?.length > 0 && (
									<>
										<p>{ __( 'This post could not be published on Nextdoor for the following reasons:', 'newspack-plugin' ) }</p>
										<ul className="nextdoor-sidebar__error-list">
											{ nextdoorStatus.ingestion_errors.map( ( msg, index ) => (
												<li key={ index }>{ msg }</li>
											) ) }
										</ul>
										<p>{ __( 'Please refer to the Publisher policy on Nextdoor for content guidelines.', 'newspack-plugin' ) }</p>
									</>
								) }
								{ INGESTION_STATUSES.UNPROCESSED === nextdoorStatus.ingestion_status && (
									<p>
										{ __(
											'This post is being processed by Nextdoor. It may take a while (~1 Hour) as Nextdoor runs ML models on it for its distribution and moderation before it starts appearing on the page profile.',
											'newspack-plugin'
										) }
									</p>
								) }
								{ INGESTION_STATUSES.DELETED === nextdoorStatus.ingestion_status && (
									<p>{ __( 'This post was removed from Nextdoor.', 'newspack-plugin' ) }</p>
								) }
							</div>

							{ nextdoorStatus.shared_at && (
								<p className="nextdoor-sidebar__status-text nextdoor-sidebar__status-text--default">
									<strong>{ __( 'Shared:', 'newspack-plugin' ) }</strong> { formatDate( nextdoorStatus.shared_at ) }
								</p>
							) }

							{ nextdoorStatus.updated_at && (
								<p className="nextdoor-sidebar__status-text nextdoor-sidebar__status-text--default">
									<strong>{ __( 'Updated:', 'newspack-plugin' ) }</strong> { formatDate( nextdoorStatus.updated_at ) }
								</p>
							) }

							{ INGESTION_STATUSES.DELETED !== nextdoorStatus.ingestion_status && (
								<div className="nextdoor-sidebar__actions">
									<Button
										variant="primary"
										onClick={ handleUpdate }
										isBusy={ 'update' === action }
										disabled={ 'update' === action || 'delete' === action }
										size="small"
									>
										{ 'update' === action ? __( 'Updating…', 'newspack-plugin' ) : __( 'Update', 'newspack-plugin' ) }
									</Button>
									<Button
										variant="secondary"
										isDestructive
										onClick={ handleDelete }
										isBusy={ 'delete' === action }
										disabled={ 'update' === action || 'delete' === action }
										size="small"
									>
										{ 'delete' === action ? __( 'Removing…', 'newspack-plugin' ) : __( 'Remove', 'newspack-plugin' ) }
									</Button>
								</div>
							) }
						</PanelBody>
					</Panel>
				) : (
					<Panel>
						<PanelBody>
							<p className="nextdoor-sidebar__description">
								{ __( 'Share this post to your Nextdoor community to engage local readers.', 'newspack-plugin' ) }
							</p>
							<Button variant="primary" onClick={ handlePublish } isBusy={ 'publish' === action } disabled={ 'publish' === action }>
								{ 'publish' === action ? __( 'Publishing…', 'newspack-plugin' ) : __( 'Publish on Nextdoor', 'newspack-plugin' ) }
							</Button>
						</PanelBody>
					</Panel>
				) }
			</>
		);
	};

	return (
		<PluginSidebar name="nextdoor-publish" title={ __( 'Nextdoor', 'newspack-plugin' ) } icon={ nextdoorIcon } className="nextdoor-post-plugin">
			{ renderContent() }
		</PluginSidebar>
	);
};

// Nextdoor Icon.
const nextdoorIcon = (
	<SVG xmlns="http://www.w3.org/2000/svg" enable-background="new 0 0 24 24" viewBox="0 0 24 24" id="nextdoor">
		<polygon points="19.879 21.5 19.879 11.703 22.039 13.014 24 9.821 12.001 2.5 7.88 5.017 7.88 2.5 4.122 2.5 4.122 7.305 0 9.821 1.962 13.014 4.123 11.703 4.123 21.5" />
	</SVG>
);

// Plugin wrapper.
const NextdoorPostSidebarPlugin = () => {
	const { postId, postStatus } = useSelect( select => {
		const { getCurrentPostId, getCurrentPostAttribute } = select( 'core/editor' );
		return {
			postId: getCurrentPostId(),
			postStatus: getCurrentPostAttribute( 'status' ),
		};
	}, [] );

	return <NextdoorPostSidebar postId={ postId } postStatus={ postStatus } />;
};

// Register the plugin.
registerPlugin( 'newspack-nextdoor-post-plugin', {
	render: NextdoorPostSidebarPlugin,
	icon: nextdoorIcon,
} );

export default NextdoorPostSidebarPlugin;
