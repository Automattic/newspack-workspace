/**
 * Field definitions for the Newsletters list DataView.
 *
 * Status renders the consolidated `newspack_newsletters_status` REST
 * field so sent/scheduled is never re-derived client-side.
 */

import { Icon } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { commentAuthorAvatar, drafts, envelope, globe, published, scheduled, trash } from '@wordpress/icons';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';

import { getAdminUrl } from '../../admin-globals';
import { isManualProvider } from '../../../utils/service-provider';
import { formatPostDate } from '../../utils/format-date';
import { termsForTaxonomy } from '../../utils/terms';
import { statusKindLabel, STATUS_KIND_LABELS } from './status-label';

const STATUS_KIND_ICONS = {
	sent: published,
	scheduled,
	draft: drafts,
	trash,
};

const formatDate = timestamp => {
	if ( ! timestamp ) {
		return '';
	}
	const settings = getDateSettings();
	const format = settings.formats?.datetime || 'M j, Y g:ia';
	return dateI18n( format, timestamp * 1000 );
};

const editUrl = item => `${ getAdminUrl() }post.php?post=${ item.id }&action=edit`;

// `title.rendered` is HTML-encoded by WP REST, so entities like `&amp;`
// or `&#8217;` would display literally in the DataView. Prefer
// `title.raw` (always present with `context=edit`, which we request) and
// fall back to the rendered value for safety. Reused by `getValue` so
// search / sort / display stay consistent.
const getTitle = item => item?.title?.raw ?? item?.title?.rendered ?? '';

const renderTitle = ( { item } ) => {
	const raw = getTitle( item );
	// New newsletters carry WordPress's "Auto Draft" placeholder title; show a friendly label instead.
	const title = ! raw || 'auto-draft' === item?.status ? __( '(no subject)', 'newspack-newsletters' ) : raw;
	return (
		<a className="newspack-newsletters-list__title" href={ editUrl( item ) } onClickCapture={ event => event.stopPropagation() }>
			<strong>{ title }</strong>
		</a>
	);
};

const renderStatus = ( { item } ) => {
	const status = item?.newspack_newsletters_status || {};
	const kind = status.kind || 'draft';
	const icon = STATUS_KIND_ICONS[ kind ] || STATUS_KIND_ICONS.draft;

	let label;
	if ( 'sent' === kind && status.sent_at ) {
		// The manual provider publishes rather than sends through an ESP, so the date reads "Published %s".
		let dateFormat;
		if ( isManualProvider() ) {
			/* translators: %s: formatted publish date */
			dateFormat = __( 'Published %s', 'newspack-newsletters' );
		} else {
			/* translators: %s: formatted send date */
			dateFormat = __( 'Sent %s', 'newspack-newsletters' );
		}
		label = sprintf( dateFormat, formatDate( status.sent_at ) );
	} else if ( 'scheduled' === kind && status.scheduled_at ) {
		label = sprintf(
			/* translators: %s: formatted scheduled date */
			__( 'Scheduled for %s', 'newspack-newsletters' ),
			formatDate( status.scheduled_at )
		);
	} else {
		label = statusKindLabel( kind );
	}

	return (
		<span className="newspack-newsletters-list__status">
			<Icon className="newspack-newsletters-list__status-icon" icon={ icon } size={ 24 } />
			<span>{ label }</span>
		</span>
	);
};

const renderSendDate = ( { item } ) => {
	const status = item?.newspack_newsletters_status || {};
	const ts = status.sent_at || status.scheduled_at;
	return ts ? formatDate( ts ) : '';
};

const renderSendList = ( { item } ) => {
	const id = item?.meta?.send_list_id || '';
	const sublistId = item?.meta?.send_sublist_id || '';
	if ( ! id ) {
		return <span className="newspack-newsletters-list__empty">&mdash;</span>;
	}
	return <code className="newspack-newsletters-list__send-list">{ sublistId ? `${ id } / ${ sublistId }` : id }</code>;
};

const renderAuthor = ( { item } ) => {
	const author = item?._embedded?.author?.[ 0 ];
	if ( ! author ) {
		return '';
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
};

const renderTerms =
	taxonomy =>
	( { item } ) =>
		termsForTaxonomy( item, taxonomy )
			.map( term => term?.name )
			.filter( Boolean )
			.join( ', ' );

const renderPublicPage = ( { item } ) => {
	const isPublic = !! item?.meta?.is_public;
	const icon = isPublic ? globe : envelope;
	const label = isPublic ? __( 'Email and web', 'newspack-newsletters' ) : __( 'Email only', 'newspack-newsletters' );
	return (
		<span className="newspack-newsletters-list__visibility">
			<Icon className="newspack-newsletters-list__visibility-icon" icon={ icon } size={ 24 } />
			<span>{ label }</span>
		</span>
	);
};

const renderDate = ( { item } ) => formatPostDate( item );

export function getFields( { authors = [], categories = [], tags = [], sendLists = [] } = {} ) {
	const statusLabels = STATUS_KIND_LABELS();

	return [
		{
			id: 'title',
			label: __( 'Subject', 'newspack-newsletters' ),
			enableGlobalSearch: true,
			getValue: ( { item } ) => getTitle( item ),
			render: renderTitle,
		},
		{
			id: 'status',
			label: __( 'Status', 'newspack-newsletters' ),
			elements: [
				{ value: 'publish,private', label: statusLabels.sent },
				{ value: 'future', label: statusLabels.scheduled },
				// Match `get_status_for_post`'s draft fallthrough.
				{ value: 'draft,pending,auto-draft', label: statusLabels.draft },
				{ value: 'trash', label: statusLabels.trash },
			],
			filterBy: { operators: [ 'isAny' ] },
			getValue: ( { item } ) => item?.newspack_newsletters_status?.kind || 'draft',
			render: renderStatus,
		},
		{
			id: 'send_date',
			// For the manual provider the date is a publish date, not an ESP send date.
			label: isManualProvider() ? __( 'Publish date', 'newspack-newsletters' ) : __( 'Send date', 'newspack-newsletters' ),
			enableSorting: true,
			getValue: ( { item } ) => item?.newspack_newsletters_status?.sent_at || item?.newspack_newsletters_status?.scheduled_at || 0,
			render: renderSendDate,
		},
		{
			id: 'send_list',
			label: __( 'Send list', 'newspack-newsletters' ),
			elements: sendLists.map( ( { id, label } ) => ( {
				value: String( id ),
				label: String( label ),
			} ) ),
			filterBy: { operators: [ 'isAny' ] },
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
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: true,
			getValue: ( { item } ) => String( item?._embedded?.author?.[ 0 ]?.id || '' ),
			render: renderAuthor,
		},
		{
			id: 'categories',
			label: __( 'Categories', 'newspack-newsletters' ),
			elements: categories.map( ( { id, label } ) => ( {
				value: String( id ),
				label: String( label ),
			} ) ),
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
			getValue: ( { item } ) =>
				termsForTaxonomy( item, 'category' )
					.map( term => term?.name )
					.filter( Boolean )
					.join( ', ' ),
			render: renderTerms( 'category' ),
		},
		{
			id: 'tags',
			label: __( 'Tags', 'newspack-newsletters' ),
			elements: tags.map( ( { id, label } ) => ( {
				value: String( id ),
				label: String( label ),
			} ) ),
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
			getValue: ( { item } ) =>
				termsForTaxonomy( item, 'post_tag' )
					.map( term => term?.name )
					.filter( Boolean )
					.join( ', ' ),
			render: renderTerms( 'post_tag' ),
		},
		{
			id: 'public_page',
			label: __( 'Visibility', 'newspack-newsletters' ),
			elements: [
				{ value: '1', label: __( 'Email and web', 'newspack-newsletters' ) },
				{ value: '0', label: __( 'Email only', 'newspack-newsletters' ) },
			],
			filterBy: { operators: [ 'is' ] },
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
