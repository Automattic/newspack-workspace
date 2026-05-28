/* global newspack_popups_data */

/**
 * Popup Custom Post Type
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { compose } from '@wordpress/compose';
import { withSelect, withDispatch, useSelect, useDispatch } from '@wordpress/data';
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel, PluginPostStatusInfo } from '@wordpress/edit-post';
import { ExternalLink, Flex } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { promptEditorPropsSelector, isOverlayPlacement } from './utils';
import Sidebar from './Sidebar';
import StylesSidebar from './StylesSidebar';
import FrequencySidebar from './FrequencySidebar';
import ColorsSidebar from './ColorsSidebar';
import AdvancedSidebar from './AdvancedSidebar';
import Preview from './Preview';
import Duplicate from './Duplicate';
import EditorAdditions from './EditorAdditions';
import PostTypesPanel from './PostTypesPanel';
import ExpirationPanel from './ExpirationPanel';
import MergeTagsBlockControl from './MergeTagsBlockControl';
import './style.scss';

const EMPTY_ARRAY = [];

const ADMIN_URL = newspack_popups_data.segments_admin_url;

const TAXONOMY_SLUG = newspack_popups_data.segments_taxonomy;

// Action dispatchers for the sidebar components.
const mapDispatchToProps = dispatch => {
	const { createNotice, removeNotice } = dispatch( 'core/notices' );
	return {
		onMetaFieldChange: ( metaToUpdate = {} ) => {
			if ( 0 < Object.keys( metaToUpdate ).length ) {
				dispatch( 'core/editor' ).editPost( { meta: metaToUpdate } );
			}
		},
		createNotice,
		removeNotice,
	};
};

const connectData = compose( [ withSelect( promptEditorPropsSelector ), withDispatch( mapDispatchToProps ) ] );

// Connect data to components.
const SidebarWithData = connectData( Sidebar );
const StylesSidebarWithData = connectData( StylesSidebar );
const FrequencySidebarWithData = connectData( FrequencySidebar );
const ColorsSidebarWithData = connectData( ColorsSidebar );
const PostTypesPanelWithData = connectData( PostTypesPanel );
const ExpirationPanelWithData = connectData( ExpirationPanel );
const AdvancedSidebarWithData = connectData( AdvancedSidebar );

// Register components.
registerPlugin( 'newspack-popups-styles', {
	render: () => (
		<PluginDocumentSettingPanel name="popup-styles-panel" title={ __( 'Styles', 'newspack-popups' ) }>
			<StylesSidebarWithData />
		</PluginDocumentSettingPanel>
	),
	icon: null,
} );

registerPlugin( 'newspack-popups', {
	render: () => (
		<PluginDocumentSettingPanel name="popup-settings-panel" title={ __( 'Settings', 'newspack-popups' ) }>
			<SidebarWithData />
		</PluginDocumentSettingPanel>
	),
	icon: null,
} );

if ( window?.newspack_popups_data?.segmentation_enabled ) {
	registerPlugin( 'newspack-popups-frequency', {
		render: () => (
			<PluginDocumentSettingPanel name="-frequency-panel" title={ __( 'Frequency', 'newspack-popups' ) }>
				<FrequencySidebarWithData />
			</PluginDocumentSettingPanel>
		),
		icon: null,
	} );
}

registerPlugin( 'newspack-popups-colors', {
	render: () => (
		<PluginDocumentSettingPanel name="popup-colors-panel" title={ __( 'Color', 'newspack-popups' ) }>
			<ColorsSidebarWithData />
		</PluginDocumentSettingPanel>
	),
	icon: null,
} );

registerPlugin( 'newspack-popups-post-types', {
	render: () => (
		<PluginDocumentSettingPanel name="post-types-panel" title={ __( 'Post Types', 'newspack-popups' ) }>
			<PostTypesPanelWithData />
		</PluginDocumentSettingPanel>
	),
	icon: null,
} );

registerPlugin( 'newspack-popups-expiration', {
	render: () => (
		<PluginDocumentSettingPanel name="expiration-panel" title={ __( 'Expiration', 'newspack-popups' ) }>
			<ExpirationPanelWithData />
		</PluginDocumentSettingPanel>
	),
	icon: null,
} );

if ( window.newspack_popups_merge_tags?.tags?.length ) {
	wp.hooks.addFilter( 'editor.BlockEdit', 'newspack-popups/merge-tags-block-control', BlockEdit => props => {
		const blocks = [ 'core/paragraph', 'core/heading', 'core/list-item', 'core/quote', 'core/pullquote', 'core/verse', 'core/preformatted' ];
		if ( blocks.includes( props.name ) ) {
			return (
				<>
					<BlockEdit { ...props } />
					<MergeTagsBlockControl tags={ window.newspack_popups_merge_tags.tags } { ...props } />
				</>
			);
		}
		return <BlockEdit { ...props } />;
	} );
}

registerPlugin( 'newspack-popups-advanced', {
	render: () => (
		<PluginDocumentSettingPanel name="popup-advanced-panel" title={ __( 'Advanced Settings', 'newspack-popups' ) }>
			<AdvancedSidebarWithData />
		</PluginDocumentSettingPanel>
	),
	icon: null,
} );

registerPlugin( 'newspack-popups-editor', {
	render: EditorAdditions,
	icon: null,
} );

// Hide Newspack's Homepage Posts block deduplication toggle when the popup is an overlay.
registerPlugin( 'newspack-popups-disable-newspack-blocks-deduplication', {
	render: function HideDeduplicationToggle() {
		const { placement } = useSelect( select => {
			const { getEditedPostAttribute } = select( 'core/editor' );
			return {
				placement: getEditedPostAttribute( 'meta' )?.placement,
			};
		} );
		if ( ! isOverlayPlacement( placement ) ) {
			return null;
		}
		return <style>{ '.newspack-blocks-deduplication-toggle {display: none;}' }</style>;
	},
	icon: null,
} );

// Add a button in post status section
const PluginPostStatusInfoTest = () => (
	<PluginPostStatusInfo className="newspack-popups__status-options">
		<Preview />
		<Duplicate />
	</PluginPostStatusInfo>
);
registerPlugin( 'newspack-popups-preview', { render: PluginPostStatusInfoTest } );

let updatedWithGetParam = false;

/**
 * Adds a help message to the Segment selector
 */
const NewspackPopupsSegmentsHelper = ( { slug } ) => {
	const { editPost } = useDispatch( editorStore );
	const { terms, taxonomy } = useSelect(
		select => {
			const { getEditedPostAttribute } = select( 'core/editor' );
			const { getTaxonomy } = select( coreStore );
			const _taxonomy = getTaxonomy( slug );

			return {
				terms: _taxonomy ? getEditedPostAttribute( _taxonomy.rest_base ) : EMPTY_ARRAY,
				taxonomy: _taxonomy,
			};
		},
		[ slug ]
	);

	// Auto-fill the Segment selector if the segment is passed in the URL.
	useEffect( () => {
		const currentURL = new URL( window.location );
		const searchParams = currentURL.searchParams;
		const initialSegment = searchParams.get( 'segment' );
		if ( ! updatedWithGetParam && initialSegment ) {
			editPost( { [ taxonomy.rest_base ]: [ parseInt( initialSegment ) ] } );
			updatedWithGetParam = true;
		}
	}, [] );

	return (
		<Flex direction="column" gap="4">
			<div className="newspack-popups-segments-tax-control-helper">
				{ terms.length === 0 && <p>{ __( 'The prompt will be shown to all readers.', 'newspack-popups' ) }</p> }
				{ terms.length === 1 && (
					<p>{ __( 'The prompt will be shown only to readers who match the selected segment.', 'newspack-popups' ) }</p>
				) }
				{ terms.length > 1 && (
					<p>{ __( 'The prompt will be shown only to readers who match the selected segments.', 'newspack-popups' ) }</p>
				) }
			</div>

			<ExternalLink href={ ADMIN_URL } key="segmentation-link">
				{ __( 'Manage segments', 'newspack-popups' ) }
			</ExternalLink>
		</Flex>
	);
};

function customizeSelector( OriginalComponent ) {
	return function NewComponent( props ) {
		if ( props.slug === TAXONOMY_SLUG ) {
			return (
				<div className="newspack-popups-segments-tax-control">
					<OriginalComponent { ...props } />
					<NewspackPopupsSegmentsHelper { ...props } />
				</div>
			);
		}
		return <OriginalComponent { ...props } />;
	};
}

wp.hooks.addFilter( 'editor.PostTaxonomyType', 'newspack/multibranded-site/brand-selector-filter', customizeSelector );
