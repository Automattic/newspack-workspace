/**
 * External dependencies
 */
import { find } from 'lodash';

/**
 * WordPress dependencies
 */
import { parse } from '@wordpress/blocks';
import { useDispatch } from '@wordpress/data';
import { DataViewsPicker } from '@wordpress/dataviews/wp';
import { Button, Icon, Spinner, __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { commentAuthorAvatar } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { BLANK_LAYOUT_ID } from '../../../../utils/consts';
import { isUserDefinedLayout } from '../../../../utils';
import { useLayoutsState } from '../../../../utils/hooks';
import { setPreventDeduplicationForPostsInserter } from '../../../../editor/blocks/posts-inserter/utils';
import NewsletterPreview from '../../../newsletter-preview';

const TABS = [
	{
		key: 'prebuilt',
		title: __( 'Prebuilt layouts', 'newspack-newsletters' ),
		filter: layout => layout.post_author === undefined,
	},
	{
		key: 'saved',
		title: __( 'Saved layouts', 'newspack-newsletters' ),
		filter: isUserDefinedLayout,
	},
];

// Sentinel `supportsBulk: false` action pins single-select; never rendered
// because free composition skips the picker's default footer.
const SINGLE_SELECT_ACTIONS = [ { id: '__single_select__', label: '', supportsBulk: false, callback: () => {} } ];

const PICKER_DEFAULT_LAYOUTS = { pickerGrid: {} };
const NOOP = () => {};

function getMetaForPreview( layout ) {
	const meta = layout?.meta || {};
	return {
		font_body: meta.font_body || '',
		font_header: meta.font_header || '',
		background_color: meta.background_color || '',
		text_color: meta.text_color || '',
		custom_css: meta.custom_css || '',
	};
}

// Mirrors `src/admin-shell/screens/layouts-list/fields.js`.
function renderAuthor( { item } ) {
	const author = item?._embedded?.author?.[ 0 ];
	if ( ! author ) {
		return null;
	}
	const avatarUrl = author.avatar_urls?.[ 48 ] || author.avatar_urls?.[ 24 ];
	return (
		<span className="newspack-newsletters-list__author">
			{ avatarUrl ? (
				<span className="newspack-newsletters-list__author-avatar">
					<img src={ avatarUrl } width={ 16 } height={ 16 } alt="" />
				</span>
			) : (
				<Icon className="newspack-newsletters-list__author-icon" icon={ commentAuthorAvatar } size={ 24 } />
			) }
			<span>{ author.name || '' }</span>
		</span>
	);
}

function PreviewCard( { item } ) {
	const content = item.post_content || '';
	const blocks = useMemo( () => {
		if ( ! content ) {
			return [];
		}
		// posts-inserter blocks inside a layout would otherwise dedupe
		// against the editor's post list when previewed.
		return setPreventDeduplicationForPostsInserter( parse( content ) );
	}, [ content ] );

	if ( ! content || ! blocks.length ) {
		return (
			<div className="newspack-newsletters-layouts-picker__preview newspack-newsletters-layouts-picker__preview--empty" aria-hidden="true" />
		);
	}

	return (
		<div className="newspack-newsletters-layouts-picker__preview">
			<NewsletterPreview layoutId={ item.ID } meta={ getMetaForPreview( item ) } blocks={ blocks } viewportWidth={ 848 } />
		</div>
	);
}

export default function LayoutPicker() {
	const { editPost, resetEditorBlocks } = useDispatch( 'core/editor' );
	const { layouts, isFetchingLayouts } = useLayoutsState();

	const [ activeTabKey, setActiveTabKey ] = useState( 'prebuilt' );
	const [ selection, setSelection ] = useState( [] );

	const hasSaved = useMemo( () => layouts.some( isUserDefinedLayout ), [ layouts ] );

	useEffect( () => {
		if ( hasSaved ) {
			setActiveTabKey( 'saved' );
		}
	}, [ hasSaved ] );

	const activeTab = TABS.find( t => t.key === activeTabKey );

	const data = useMemo( () => {
		const filtered = layouts.filter( activeTab.filter );
		if ( activeTabKey !== 'saved' ) {
			return filtered;
		}
		return [ ...filtered ].sort( ( a, b ) => ( b.post_modified || '' ).localeCompare( a.post_modified || '' ) );
	}, [ layouts, activeTab, activeTabKey ] );

	const showAuthor = useMemo( () => {
		if ( activeTabKey !== 'saved' ) {
			return false;
		}
		const authors = new Set(
			data
				.map( l => l.post_author )
				.filter( Boolean )
				.map( String )
		);
		return authors.size > 1;
	}, [ activeTabKey, data ] );

	const fields = useMemo( () => {
		const list = [
			{
				id: 'title',
				label: __( 'Title', 'newspack-newsletters' ),
				enableHiding: false,
				enableSorting: false,
				enableGlobalSearch: false,
				getValue: ( { item } ) => item.post_title || '',
				render: ( { item } ) => <strong>{ item.post_title || __( '(no title)', 'newspack-newsletters' ) }</strong>,
			},
			{
				id: 'preview',
				label: __( 'Preview', 'newspack-newsletters' ),
				enableHiding: false,
				enableSorting: false,
				enableGlobalSearch: false,
				getValue: () => '',
				render: PreviewCard,
			},
		];
		if ( activeTabKey === 'saved' ) {
			list.push( {
				id: 'author',
				label: __( 'Author', 'newspack-newsletters' ),
				enableHiding: false,
				enableSorting: false,
				enableGlobalSearch: false,
				getValue: ( { item } ) => item?._embedded?.author?.[ 0 ]?.name || '',
				render: renderAuthor,
			} );
		}
		return list;
	}, [ activeTabKey ] );

	const view = useMemo(
		() => ( {
			type: 'pickerGrid',
			mediaField: 'preview',
			titleField: 'title',
			fields: activeTabKey === 'saved' && showAuthor ? [ 'author' ] : [],
			page: 1,
			perPage: data.length || 1,
		} ),
		[ activeTabKey, showAuthor, data.length ]
	);

	const paginationInfo = useMemo( () => ( { totalItems: data.length, totalPages: 1 } ), [ data.length ] );

	const onTabChange = key => {
		setActiveTabKey( key );
		setSelection( [] );
	};

	const selectedLayoutId = selection[ 0 ] ? Number( selection[ 0 ] ) : null;

	const insertLayout = layoutId => {
		const layout = find( layouts, { ID: layoutId } ) || {};
		let post_content = layout.post_content || '';
		const meta = { ...( layout.meta || {} ) };
		if ( meta.campaign_defaults && 'string' === typeof meta.campaign_defaults ) {
			meta.stringifiedCampaignDefaults = meta.campaign_defaults;
		}

		// Append default Mailchimp footer if available. Only if "*|UNSUB|*" tag is not already present.
		if ( post_content && ! post_content.includes( '*|UNSUB|*' ) ) {
			post_content += window.newspack_newsletters_editor_data?.mailchimp_default_footer || '';
		}

		editPost( { meta: { template_id: layoutId, ...meta } } );
		resetEditorBlocks( post_content ? parse( post_content ) : [] );
	};

	return (
		<>
			<div className="newspack-newsletters-modal__content">
				<div className="newspack-newsletters-modal__content__sidebar">
					<div className="newspack-newsletters-modal__content__sidebar-wrapper">
						<p>{ __( 'Choose a layout or start with a blank newsletter.', 'newspack-newsletters' ) }</p>
						<div className="newspack-newsletters-modal__content__layout-buttons">
							{ TABS.map( ( { key, title } ) => (
								<Button
									key={ key }
									disabled={ isFetchingLayouts || ( key === 'saved' && ! hasSaved ) }
									variant={ ! isFetchingLayouts && key === activeTabKey ? 'primary' : 'tertiary' }
									onClick={ () => onTabChange( key ) }
								>
									{ title }
								</Button>
							) ) }
						</div>
					</div>
				</div>
				<div className="newspack-newsletters-modal__layouts">
					{ isFetchingLayouts && <Spinner /> }
					{ ! isFetchingLayouts && (
						<DataViewsPicker
							data={ data }
							fields={ fields }
							view={ view }
							onChangeView={ NOOP }
							actions={ SINGLE_SELECT_ACTIONS }
							paginationInfo={ paginationInfo }
							defaultLayouts={ PICKER_DEFAULT_LAYOUTS }
							selection={ selection }
							onChangeSelection={ setSelection }
							getItemId={ item => String( item.ID ) }
							itemListLabel={ activeTab.title }
						>
							<DataViewsPicker.Layout />
						</DataViewsPicker>
					) }
				</div>
			</div>
			<HStack align="center" className="newspack-newsletters-modal__action-buttons" justify="end">
				<Button variant="secondary" onClick={ () => insertLayout( BLANK_LAYOUT_ID ) }>
					{ __( 'Blank newsletter', 'newspack-newsletters' ) }
				</Button>
				<Button variant="primary" disabled={ isFetchingLayouts || ! selectedLayoutId } onClick={ () => insertLayout( selectedLayoutId ) }>
					{ __( 'Use selected layout', 'newspack-newsletters' ) }
				</Button>
			</HStack>
		</>
	);
}
