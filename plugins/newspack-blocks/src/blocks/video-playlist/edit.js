/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Component, Fragment } from '@wordpress/element';
import { Notice, Placeholder, Spinner, PanelBody } from '@wordpress/components';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { addQueryArgs } from '@wordpress/url';
import { decodeEntities } from '@wordpress/html-entities';

class Edit extends Component {
	constructor( props ) {
		super( props );
		this.state = {
			isLoading: false,
			error: '',
			embed: '',
			interactive: false,
		};
	}

	/**
	 * Refresh preview on load.
	 */
	componentDidMount() {
		this.refreshPreview();
	}

	/**
	 * Refresh preview if something changes.
	 *
	 * @param {Object} prevProps Previous object props.
	 */
	componentDidUpdate( prevProps ) {
		const { attributes } = this.props;
		const { categories, videosToShow } = attributes;
		const { attributes: prevAttributes } = prevProps;
		const { categories: prevCategories, videosToShow: prevVideosToShow } = prevAttributes;

		if ( categories !== prevCategories || videosToShow !== prevVideosToShow ) {
			this.refreshPreview();
		}
	}

	/**
	 * Bring the overlay back up when the block is un-selected.
	 * The overlay handling is the same as the core embed blocks.
	 *
	 * @see https://github.com/WordPress/gutenberg/blob/8e7f7b1a388829ac46fb6917351c7d064905af9a/packages/block-library/src/embed/embed-preview.js#L27-L111
	 * @param {Object} nextProps Next object props.
	 * @param {Object} state     Object state.
	 * @return {Object} State or null.
	 */
	static getDerivedStateFromProps( nextProps, state ) {
		if ( ! nextProps.isSelected && state.interactive ) {
			return { interactive: false };
		}

		return null;
	}

	/**
	 * Fetch the latest preview for the current attributes.
	 */
	refreshPreview() {
		const { attributes } = this.props;
		const { categories, videosToShow } = attributes;

		const basePath = '/newspack-blocks/v1/video-playlist';
		const path = addQueryArgs( basePath, {
			categories,
			videosToShow,
		} );

		this.setState( { isLoading: true }, () => {
			apiFetch( {
				path,
			} )
				.then( response => {
					const { html } = response;
					this.setState( {
						embed: html,
						isLoading: false,
					} );
				} )
				.catch( error => {
					this.setState( {
						isLoading: false,
						error: error.message,
					} );
				} );
		} );
	}

	/**
	 * Render an informational placeholder for loading and errors.
	 */
	renderPlaceholder() {
		const { isLoading, error, embed } = this.state;

		if ( isLoading ) {
			return <Placeholder icon={ <Spinner /> } className="component-placeholder__align-center" />;
		}

		if ( error.length ) {
			return <Placeholder icon="warning" label={ __( 'Error', 'newspack-blocks' ) } instructions={ error } />;
		}

		if ( ! embed.length ) {
			return (
				<Placeholder icon="warning" label={ __( 'Error', 'newspack-blocks' ) } instructions={ __( 'No videos found', 'newspack-blocks' ) } />
			);
		}

		return null;
	}

	/**
	 * Fetch and decode Category autocomplete options.
	 *
	 * @param {string} search The text input into the field so far.
	 * @return {Array} Autocomplete suggestion tokens.
	 */
	fetchCategorySuggestions = search => {
		return apiFetch( {
			path: addQueryArgs( '/wp/v2/categories', {
				search,
				per_page: 20,
				_fields: 'id,name',
				orderby: 'count',
				order: 'desc',
			} ),
		} ).then( function ( categories ) {
			return categories.map( category => ( {
				value: category.id,
				label: decodeEntities( category.name ) || __( '(no title)', 'newspack-blocks' ),
			} ) );
		} );
	};

	/**
	 * Convert saved Category IDs into tokens.
	 *
	 * @param {Array} categoryIDs array of Category IDs.
	 * @return {Array} Tokens.
	 */
	fetchSavedCategories = categoryIDs => {
		return apiFetch( {
			path: addQueryArgs( '/wp/v2/categories', {
				per_page: 100,
				_fields: 'id,name',
				include: categoryIDs.join( ',' ),
			} ),
		} ).then( function ( categories ) {
			return categories.map( category => ( {
				value: category.id,
				label: decodeEntities( category.name ) || __( '(no title)', 'newspack-blocks' ),
			} ) );
		} );
	};

	/**
	 * Render.
	 */
	render() {
		const { embed, isLoading } = this.state;

		const Warning = () => (
			<>
				<h2>{ __( 'The YouTube Video Playlist block is deprecated', 'newspack-plugin' ) }</h2>
				<p
					dangerouslySetInnerHTML={ {
						__html: sprintf(
							// translators: %1$s is the link to Google's help doc on creating YouTube playlists. %2$s is the link to the help doc on embedding playlists.
							__(
								'Consider using <a href="%1$s">YouTube Playlists</a> instead, which can be <a href="%2$s">embedded</a> into post or page content.',
								'newspack-blocks'
							),
							'https://support.google.com/youtube/answer/57792',
							'https://support.google.com/youtube/answer/171780'
						),
					} }
				/>
			</>
		);

		return (
			<Fragment>
				<InspectorControls>
					<PanelBody title={ __( 'Settings', 'newspack-blocks' ) } initialOpen={ true }>
						<Notice status="warning" isDismissible={ false }>
							<Warning />
						</Notice>
					</PanelBody>
				</InspectorControls>
				<div { ...this.props.blockProps }>
					<div>
						{ ( isLoading || '' === embed ) && this.renderPlaceholder() }
						{ ! ( isLoading || '' === embed ) && (
							<div className="wpbnbvp-preview">
								<div dangerouslySetInnerHTML={ { __html: embed } } />
							</div>
						) }
						{ ! isLoading && (
							<div className="wpbnbvp__overlay">
								<Warning />
							</div>
						) }
					</div>
				</div>
			</Fragment>
		);
	}
}

const EditWithBlockProps = props => {
	const blockProps = useBlockProps();
	return <Edit { ...props } blockProps={ blockProps } />;
};

export default EditWithBlockProps;
