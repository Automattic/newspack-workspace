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
 * External dependencies
 */
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import { promptEditorPropsSelector, isOverlayPlacement } from './utils';
import type { PromptEditorDispatchProps, PromptMeta, CreateNotice } from './utils';
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

/**
 * The `wp.hooks` global, as exposed by the `wp-hooks` script dependency
 * (loaded as a plain script, not an ES module, so this filter registration
 * uses the global rather than importing `@wordpress/hooks` directly).
 */
declare const wp: { hooks: { addFilter: typeof import('@wordpress/hooks').addFilter } };

const EMPTY_ARRAY: never[] = [];

const ADMIN_URL = newspack_popups_data.segments_admin_url;

const TAXONOMY_SLUG = newspack_popups_data.segments_taxonomy;

/**
 * The subset of `dispatch( 'core/editor' )` used by `mapDispatchToProps`.
 * A `type` alias (not `interface`) so the `as` cast below -- from
 * `withDispatch`'s `Record<string, (...args: unknown[]) => unknown>` -- is
 * checked structurally against a plain object type (TS treats `interface`
 * casts from an index-signature type more conservatively).
 */
type CoreEditorActions = {
	editPost: ( edits: { meta: Partial< PromptMeta > } ) => void;
};

/** The subset of `dispatch( 'core/notices' )` used by `mapDispatchToProps`. See `CoreEditorActions` for why this is a `type`, not an `interface`. */
type CoreNoticesActions = {
	createNotice: CreateNotice;
	removeNotice: ( id: string ) => void;
};

// Action dispatchers for the sidebar components. Returns specifically-typed action
// props; withDispatch's own signature widens the mapper to an unknown-arg index, so
// the mapper itself is cast to that at the `withDispatch()` call site below.
const mapDispatchToProps = ( dispatch: ( store: string ) => Record< string, ( ...args: unknown[] ) => unknown > ): PromptEditorDispatchProps => {
	const { createNotice, removeNotice } = dispatch( 'core/notices' ) as CoreNoticesActions;
	return {
		onMetaFieldChange: ( metaToUpdate: Partial< PromptMeta > = {} ) => {
			if ( 0 < Object.keys( metaToUpdate ).length ) {
				( dispatch( 'core/editor' ) as CoreEditorActions ).editPost( { meta: metaToUpdate } );
			}
		},
		createNotice,
		removeNotice,
	};
};

// compose() is loosely typed (its result takes and returns `unknown`), so each
// `XxxWithData` component below is asserted as ComponentType. Passed as separate
// arguments (rather than the original single-array form) to match compose()'s
// declared variadic signature -- its real implementation `.flat()`s its arguments
// either way, so this is not a behavior change.
const connectData = compose( withSelect( promptEditorPropsSelector ), withDispatch( mapDispatchToProps as Parameters< typeof withDispatch >[ 0 ] ) );

// Connect data to components.
const SidebarWithData = connectData( Sidebar ) as ComponentType;
const StylesSidebarWithData = connectData( StylesSidebar ) as ComponentType;
const FrequencySidebarWithData = connectData( FrequencySidebar ) as ComponentType;
const ColorsSidebarWithData = connectData( ColorsSidebar ) as ComponentType;
const PostTypesPanelWithData = connectData( PostTypesPanel ) as ComponentType;
const ExpirationPanelWithData = connectData( ExpirationPanel ) as ComponentType;
const AdvancedSidebarWithData = connectData( AdvancedSidebar ) as ComponentType;

// Register components.
registerPlugin( 'newspack-popups-styles', {
	render: () => (
		<PluginDocumentSettingPanel name="popup-styles-panel" title={ __( 'Styles', 'newspack-popups' ) }>
			<StylesSidebarWithData />
		</PluginDocumentSettingPanel>
	),
	icon: undefined, // `null` (falsy) satisfies `icon?: IconType` as `undefined`; same falsy override at runtime.
} );

registerPlugin( 'newspack-popups', {
	render: () => (
		<PluginDocumentSettingPanel name="popup-settings-panel" title={ __( 'Settings', 'newspack-popups' ) }>
			<SidebarWithData />
		</PluginDocumentSettingPanel>
	),
	icon: undefined, // `null` (falsy) satisfies `icon?: IconType` as `undefined`; same falsy override at runtime.
} );

if ( window?.newspack_popups_data?.segmentation_enabled ) {
	registerPlugin( 'newspack-popups-frequency', {
		render: () => (
			<PluginDocumentSettingPanel name="-frequency-panel" title={ __( 'Frequency', 'newspack-popups' ) }>
				<FrequencySidebarWithData />
			</PluginDocumentSettingPanel>
		),
		icon: undefined, // `null` (falsy) satisfies `icon?: IconType` as `undefined`; same falsy override at runtime.
	} );
}

registerPlugin( 'newspack-popups-colors', {
	render: () => (
		<PluginDocumentSettingPanel name="popup-colors-panel" title={ __( 'Color', 'newspack-popups' ) }>
			<ColorsSidebarWithData />
		</PluginDocumentSettingPanel>
	),
	icon: undefined, // `null` (falsy) satisfies `icon?: IconType` as `undefined`; same falsy override at runtime.
} );

registerPlugin( 'newspack-popups-post-types', {
	render: () => (
		<PluginDocumentSettingPanel name="post-types-panel" title={ __( 'Post Types', 'newspack-popups' ) }>
			<PostTypesPanelWithData />
		</PluginDocumentSettingPanel>
	),
	icon: undefined, // `null` (falsy) satisfies `icon?: IconType` as `undefined`; same falsy override at runtime.
} );

registerPlugin( 'newspack-popups-expiration', {
	render: () => (
		<PluginDocumentSettingPanel name="expiration-panel" title={ __( 'Expiration', 'newspack-popups' ) }>
			<ExpirationPanelWithData />
		</PluginDocumentSettingPanel>
	),
	icon: undefined, // `null` (falsy) satisfies `icon?: IconType` as `undefined`; same falsy override at runtime.
} );

/**
 * A block's `BlockEdit` props, as passed through the `editor.BlockEdit`
 * filter. Only the members `MergeTagsBlockControl` needs are declared.
 */
interface BlockEditProps {
	name: string;
	attributes: { content: string; [ key: string ]: unknown };
	setAttributes: ( attributes: { content?: string; [ key: string ]: unknown } ) => void;
	[ key: string ]: unknown;
}

if ( window.newspack_popups_merge_tags?.tags?.length ) {
	wp.hooks.addFilter(
		'editor.BlockEdit',
		'newspack-popups/merge-tags-block-control',
		( BlockEdit: ComponentType< BlockEditProps > ) =>
			function ( props: BlockEditProps ) {
				const blocks = [
					'core/paragraph',
					'core/heading',
					'core/list-item',
					'core/quote',
					'core/pullquote',
					'core/verse',
					'core/preformatted',
				];
				if ( blocks.includes( props.name ) ) {
					return (
						<>
							<BlockEdit { ...props } />
							<MergeTagsBlockControl tags={ window.newspack_popups_merge_tags!.tags } { ...props } />
						</>
					);
				}
				return <BlockEdit { ...props } />;
			}
	);
}

registerPlugin( 'newspack-popups-advanced', {
	render: () => (
		<PluginDocumentSettingPanel name="popup-advanced-panel" title={ __( 'Advanced Settings', 'newspack-popups' ) }>
			<AdvancedSidebarWithData />
		</PluginDocumentSettingPanel>
	),
	icon: undefined, // `null` (falsy) satisfies `icon?: IconType` as `undefined`; same falsy override at runtime.
} );

registerPlugin( 'newspack-popups-editor', {
	render: EditorAdditions,
	icon: undefined, // `null` (falsy) satisfies `icon?: IconType` as `undefined`; same falsy override at runtime.
} );

// Hide Newspack's Homepage Posts block deduplication toggle when the popup is an overlay.
registerPlugin( 'newspack-popups-disable-newspack-blocks-deduplication', {
	render: function HideDeduplicationToggle() {
		const { placement } = useSelect( select => {
			const { getEditedPostAttribute } = select( 'core/editor' ) as { getEditedPostAttribute: ( attribute: string ) => unknown };
			return {
				placement: ( getEditedPostAttribute( 'meta' ) as PromptMeta | undefined )?.placement,
			};
		} );
		if ( ! isOverlayPlacement( placement || '' ) ) {
			return null;
		}
		return <style>{ '.newspack-blocks-deduplication-toggle {display: none;}' }</style>;
	},
	icon: undefined, // `null` (falsy) satisfies `icon?: IconType` as `undefined`; same falsy override at runtime.
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

interface NewspackPopupsSegmentsHelperProps {
	slug: string;
}

/**
 * Adds a help message to the Segment selector
 */
const NewspackPopupsSegmentsHelper = ( { slug }: NewspackPopupsSegmentsHelperProps ) => {
	const { editPost } = useDispatch( editorStore ) as { editPost: ( edits: Record< string, unknown > ) => void };
	const { terms, taxonomy } = useSelect(
		select => {
			const { getEditedPostAttribute } = select( 'core/editor' ) as { getEditedPostAttribute: ( attribute: string ) => unknown };
			const { getTaxonomy } = select( coreStore ) as { getTaxonomy: ( slug: string ) => { rest_base: string } | null };
			const _taxonomy = getTaxonomy( slug );

			return {
				terms: ( _taxonomy ? getEditedPostAttribute( _taxonomy.rest_base ) : EMPTY_ARRAY ) as unknown[],
				taxonomy: _taxonomy,
			};
		},
		[ slug ]
	);

	// Auto-fill the Segment selector if the segment is passed in the URL.
	useEffect( () => {
		const currentURL = new URL( window.location.href );
		const searchParams = currentURL.searchParams;
		const initialSegment = searchParams.get( 'segment' );
		if ( ! updatedWithGetParam && initialSegment && taxonomy ) {
			editPost( { [ taxonomy.rest_base ]: [ parseInt( initialSegment ) ] } );
			updatedWithGetParam = true;
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

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

interface PostTaxonomyTypeProps {
	slug: string;
	[ key: string ]: unknown;
}

function customizeSelector( OriginalComponent: ComponentType< PostTaxonomyTypeProps > ) {
	return function NewComponent( props: PostTaxonomyTypeProps ) {
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
