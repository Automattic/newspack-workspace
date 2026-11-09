/**
 * Field definitions for the Newsletters list DataView.
 *
 * Status renders the consolidated `newspack_newsletters_status` REST
 * field so sent/scheduled is never re-derived client-side.
 */

import { ExternalLink, Tooltip, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { __ } from '@wordpress/i18n';
import { envelope, globe } from '@wordpress/icons';

import { CellList, formatSiteDateTime, StatusIndicator } from 'newspack-components';

import UserRow, { avatarPropsFromAuthor } from '../../../components/user-row';
import { useLockedPost } from '../../hooks/use-locked-posts';
import { getAdminUrl } from '../../admin-globals';
import { formatPostDate } from '../../utils/format-date';
import { termsForTaxonomy } from '../../utils/terms';
import { statusKindLabel, STATUS_KIND_LABELS } from './status-label';

export const STATUS_KIND_STATUSES = {
	sent: 'done',
	scheduled: 'scheduled',
	draft: 'draft',
	trash: 'trash',
};

const renderEmpty = () => <span className="newspack-newsletters-list__empty">&mdash;</span>;

const editUrl = item => `${ getAdminUrl() }post.php?post=${ item.id }&action=edit`;

// `title.rendered` is HTML-encoded by WP REST, so entities like `&amp;`
// or `&#8217;` would display literally in the DataView. Prefer
// `title.raw` (always present with `context=edit`, which we request) and
// fall back to the rendered value for safety. Reused by `getValue` so
// search / sort / display stay consistent.
const getTitle = item => item?.title?.raw ?? item?.title?.rendered ?? '';

// `lock` is a `wp_check_locked_posts()` payload: pre-translated text and
// avatar URLs for whoever holds the lock, mirroring the classic list table.
const renderLock = lock => (
	<UserRow
		avatarUrl={ lock.avatar_src }
		avatarSrcSet={ lock.avatar_src_2x ? `${ lock.avatar_src_2x } 2x` : undefined }
		label={ lock.text }
		className="newspack-newsletters-list__locked"
	/>
);

// A component, not a render callback: it reads the lock for its own row from
// context, so a lock change re-renders the affected cells instead of rebuilding
// the field definitions DataViews uses as each cell's element type.
const TitleCell = ( { item } ) => {
	const raw = getTitle( item );
	const title = raw || __( '(no subject)', 'newspack-newsletters' );
	const lock = useLockedPost( item?.id );
	// DataViews lays the title cell out as a nowrap flex row, so the lock
	// line needs its own column wrapper to sit under the subject.
	return (
		<VStack className="newspack-newsletters-list__title-cell" spacing={ 1 }>
			<a className="newspack-newsletters-list__title" href={ editUrl( item ) } onClickCapture={ event => event.stopPropagation() }>
				<strong>{ title }</strong>
			</a>
			{ lock && renderLock( lock ) }
		</VStack>
	);
};

const renderStatus = ( { item } ) => {
	const status = item?.newspack_newsletters_status || {};
	const kind = status.kind || 'draft';
	const statusName = STATUS_KIND_STATUSES[ kind ] || STATUS_KIND_STATUSES.draft;
	const indicator = <StatusIndicator status={ statusName }>{ statusKindLabel( kind ) }</StatusIndicator>;

	const timestamp = { sent: status.sent_at, scheduled: status.scheduled_at }[ kind ];
	if ( ! timestamp ) {
		return indicator;
	}
	return (
		<Tooltip text={ formatSiteDateTime( timestamp ) }>
			<button type="button" className="newspack-newsletters-list__status-tip" onClick={ event => event.stopPropagation() }>
				{ indicator }
			</button>
		</Tooltip>
	);
};

const renderSendList = ( { item } ) => {
	const id = item?.meta?.send_list_id || '';
	if ( ! id ) {
		return renderEmpty();
	}
	const sublistId = item?.meta?.send_sublist_id || '';
	const names = item?.newspack_newsletters_send_list;
	const labels = [ names?.list || id ];
	if ( sublistId ) {
		labels.push( names?.sublist || sublistId );
	}
	return (
		<CellList>
			{ labels.map( ( label, index ) => (
				<span key={ index }>{ label }</span>
			) ) }
		</CellList>
	);
};

const renderAuthor = ( { item } ) => {
	const author = item?.newspack_newsletters_author;
	if ( ! author ) {
		return renderEmpty();
	}
	return <UserRow { ...avatarPropsFromAuthor( author ) } label={ author.name || '' } />;
};

const termNames = ( item, taxonomy ) =>
	termsForTaxonomy( item, taxonomy )
		.map( term => term?.name )
		.filter( Boolean );

const renderTerms =
	taxonomy =>
	( { item } ) => {
		const names = termNames( item, taxonomy );
		return names.length ? names.join( ', ' ) : renderEmpty();
	};

const renderPublicPage = ( { item } ) => {
	const isPublic = !! item?.meta?.is_public;
	const icon = isPublic ? globe : envelope;
	const label = isPublic ? __( 'Email and web', 'newspack-newsletters' ) : __( 'Email only', 'newspack-newsletters' );
	// Real anchor so the public page opens in one click and supports
	// cmd/middle-click; mirrors the `view-public-page` action's gate.
	const publicUrl = isPublic && 'publish' === item?.status && item?.link ? item.link : null;
	return (
		<StatusIndicator icon={ icon }>
			{ publicUrl ? (
				<ExternalLink href={ publicUrl } onClickCapture={ event => event.stopPropagation() }>
					{ label }
				</ExternalLink>
			) : (
				label
			) }
		</StatusIndicator>
	);
};

const renderDate = ( { item } ) => {
	const formatted = formatPostDate( item );
	return formatted ? <span className="newspack-dataviews__date">{ formatted }</span> : renderEmpty();
};

export function getFields( { authors = [], categories = [], tags = [], sendLists = [] } = {} ) {
	const statusLabels = STATUS_KIND_LABELS();

	return [
		{
			id: 'title',
			label: __( 'Subject', 'newspack-newsletters' ),
			enableGlobalSearch: true,
			getValue: ( { item } ) => getTitle( item ),
			render: TitleCell,
		},
		{
			id: 'status',
			label: __( 'Status', 'newspack-newsletters' ),
			elements: [
				{ value: 'publish,private', label: statusLabels.sent },
				{ value: 'future', label: statusLabels.scheduled },
				// Match `get_status_for_post`'s draft fallthrough.
				{ value: 'draft,pending', label: statusLabels.draft },
				{ value: 'trash', label: statusLabels.trash },
			],
			filterBy: { operators: [ 'isAny' ], isPrimary: true },
			getValue: ( { item } ) => item?.newspack_newsletters_status?.kind || 'draft',
			render: renderStatus,
		},
		{
			id: 'send_list',
			label: __( 'Send list', 'newspack-newsletters' ),
			elements: sendLists.map( ( { id, label } ) => ( {
				value: String( id ),
				label: String( label ),
			} ) ),
			filterBy: { operators: [ 'isAny' ], isPrimary: true },
			enableSorting: false,
			getValue: ( { item } ) => item?.meta?.send_list_id || '',
			render: renderSendList,
		},
		{
			id: 'author',
			label: __( 'Author', 'newspack-newsletters' ),
			elements: authors.map( ( { id, label } ) => ( {
				value: String( id ),
				label: String( label ),
			} ) ),
			filterBy: { operators: [ 'isAny' ], isPrimary: true },
			enableSorting: true,
			getValue: ( { item } ) => String( item?.newspack_newsletters_author?.id || '' ),
			render: renderAuthor,
		},
		{
			id: 'categories',
			label: __( 'Categories', 'newspack-newsletters' ),
			elements: categories.map( ( { id, label } ) => ( {
				value: String( id ),
				label: String( label ),
			} ) ),
			filterBy: { operators: [ 'isAny' ], isPrimary: true },
			enableSorting: false,
			getValue: ( { item } ) => termNames( item, 'category' ).join( ', ' ),
			render: renderTerms( 'category' ),
		},
		{
			id: 'tags',
			label: __( 'Tags', 'newspack-newsletters' ),
			elements: tags.map( ( { id, label } ) => ( {
				value: String( id ),
				label: String( label ),
			} ) ),
			filterBy: { operators: [ 'isAny' ], isPrimary: true },
			enableSorting: false,
			getValue: ( { item } ) => termNames( item, 'post_tag' ).join( ', ' ),
			render: renderTerms( 'post_tag' ),
		},
		{
			id: 'public_page',
			label: __( 'Visibility', 'newspack-newsletters' ),
			elements: [
				{ value: '1', label: __( 'Email and web', 'newspack-newsletters' ) },
				{ value: '0', label: __( 'Email only', 'newspack-newsletters' ) },
			],
			filterBy: { operators: [ 'is' ], isPrimary: true },
			getValue: ( { item } ) => ( item?.meta?.is_public ? '1' : '0' ),
			render: renderPublicPage,
		},
		{
			id: 'date',
			label: __( 'Date', 'newspack-newsletters' ),
			enableSorting: true,
			getValue: ( { item } ) => item?.date || '',
			render: renderDate,
		},
	];
}

export const FIELD_IDS = getFields().map( field => field.id );
