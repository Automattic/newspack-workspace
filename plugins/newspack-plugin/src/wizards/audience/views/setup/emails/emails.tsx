/**
 * Newspack > Settings > Emails > Emails section
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useMemo, Fragment } from '@wordpress/element';
import { DataViews as WPDataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import type { Action, Field, View } from '@wordpress/dataviews';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { DataViews, Notice, StatusIndicator, utils } from '../../../../../../packages/components/src';
import { postStatus } from '../../../post-status';
import WizardsPluginCard from '../../../../wizards-plugin-card';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import EmailPreview from './email-preview';
import './emails.scss';

interface EmailItem {
	label: string;
	// Newspack-source rows carry an integer post ID; WC-source rows carry
	// a string in the form `wc:{wc_email_id}` (e.g. `wc:customer_payment_retry`).
	// The activate/deactivate action callbacks branch on `typeof` to route
	// the write through the correct endpoint.
	post_id: number | string;
	// Preview identifier the EmailPreview component sends to the
	// /preview endpoint. Newspack rows don't emit this — the field
	// render falls back to post_id. WC rows emit either an integer
	// (block-editor template post) or a `wc:{id}` string (classic
	// template) via the smart fallback in serialize_wc_email_row.
	preview_id?: number | string | null;
	edit_link: string;
	status: string;
	type: string;
	category: string;
	trigger_description: string;
	recipient: 'reader' | 'admin';
	recommended: boolean;
	chip: 'auth-account' | 'reader-revenue';
	source: 'newspack' | 'woocommerce';
}

// The bundled @wordpress/dataviews types (v10) predate `isDestructive`,
// which the WP-core-provided runtime DataViews does support — extend the
// Action type locally so destructive styling can be declared.
type EmailAction = Action< EmailItem > & { isDestructive?: boolean };

interface EmailSettings {
	newspack_emails: EmailItem[];
	post_type: string;
	admin_url?: string;
	enable_woocommerce_email_editor?: boolean;
}

const DEFAULT_VIEW: View = {
	type: 'grid',
	page: 1,
	perPage: 50,
	search: '',
	fields: [ 'chip', 'recipient', 'status' ],
	filters: [],
	layout: {},
	titleField: 'name',
	descriptionField: 'trigger_description',
	// v14 grid layout resolves this to find the field by id and
	// render it as the card's media tile (top of each card).
	mediaField: 'preview',
};

const PageHeading = () => <h2 className="screen-reader-text">{ __( 'Emails', 'newspack-plugin' ) }</h2>;

const TYPES: { value: EmailItem[ 'chip' ]; label: string }[] = [
	{
		value: 'reader-revenue',
		label: __( 'Reader Revenue', 'newspack-plugin' ),
	},
	{
		value: 'auth-account',
		label: __( 'Authentication & Account', 'newspack-plugin' ),
	},
];

const Emails = () => {
	// Defensive: fall back to an empty shape if the SSR-bootstrap payload
	// is missing (e.g., a plugin filter strips the localized object, the
	// component is mounted from a non-Audience surface, or a dev-time HMR
	// reseed clears window state). Without this, accessing
	// `.dependencies.newspackNewsletters` on undefined would throw
	// TypeError at mount and crash the entire route.
	const emailSettings = window.newspackAudience?.emails ?? { dependencies: {}, postType: '', initial: undefined, isNewspackPlatform: false };
	const [ pluginsReady, setPluginsReady ] = useState( Boolean( emailSettings.dependencies?.newspackNewsletters ) );

	// Seed from the SSR bootstrap (class-audience-wizard.php passes the same
	// shape as api_get_email_settings()) so DataViews renders on first paint
	// instead of waiting for the mount-time XHR.
	const initial = emailSettings.initial;
	const [ data, setData ] = useState< EmailItem[] >( ( initial?.newspack_emails as EmailItem[] | undefined ) ?? [] );
	const postType = initial?.post_type ?? emailSettings.postType;
	// Only the Newspack platform has both types; elsewhere the server returns
	// auth/account emails alone, so a Type filter would have one option.
	const isNewspackPlatform = Boolean( emailSettings.isNewspackPlatform );
	const [ view, setView ] = useState< View >( () =>
		isNewspackPlatform ? DEFAULT_VIEW : { ...DEFAULT_VIEW, fields: DEFAULT_VIEW.fields?.filter( field => field !== 'chip' ) }
	);

	const { wizardApiFetch, isFetching, errorMessage, resetError } = useWizardApiFetch( 'newspack-settings/emails' );

	const fetchData = useCallback( () => {
		resetError();
		wizardApiFetch< EmailSettings >(
			{
				path: '/newspack/v1/wizard/newspack-settings/emails',
				isCached: false,
			},
			{
				onSuccess( result: EmailSettings ) {
					setData( result.newspack_emails || [] );
				},
				onError() {
					// useWizardApiFetch surfaces the message via errorMessage;
					// this handler is here so a rejection doesn't become an
					// unhandled promise rejection if the hook's plumbing changes.
				},
			}
		);
	}, [ wizardApiFetch, resetError ] );

	useEffect( () => {
		// Only refetch on mount if we didn't get the SSR seed. The SSR data
		// is built from the same api_get_email_settings() shape, so it's
		// authoritative for first paint.
		if ( ! initial?.newspack_emails ) {
			fetchData();
		}
		// Empty cleanup return; the hook's promise resolves to nothing
		// observable post-unmount, but explicit cleanup signals intent.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const updateStatus = ( postId: number, nextStatus: string ) => {
		resetError();
		// Optimistic update — the server response only confirms what we
		// already wrote, so patch the row in place and skip the full
		// refetch (which would otherwise re-pay the N+1 query in
		// Emails::get_emails on every toggle).
		//
		// Capture only THIS row's prior status, not a snapshot of the whole
		// array. Rolling back to a full-array snapshot would clobber any
		// unrelated row an overlapping request mutated in the meantime; on
		// failure we restore just the failed row via a functional update so
		// concurrent edits to other rows survive.
		const prevStatus = data.find( email => email.post_id === postId )?.status;
		setData( prevData => prevData.map( email => ( email.post_id === postId ? { ...email, status: nextStatus } : email ) ) );
		wizardApiFetch(
			{
				path: `/wp/v2/${ postType }/${ postId }`,
				method: 'POST',
				data: { status: nextStatus },
			},
			{
				onError() {
					// Roll back only the failed row, leaving any concurrent
					// changes to other rows intact.
					setData( prevData =>
						prevData.map( email => ( email.post_id === postId ? { ...email, status: prevStatus ?? email.status } : email ) )
					);
				},
			}
		);
	};

	// WooCommerce-source rows have a string post_id `wc:{wc_email_id}` —
	// routed through the slice 2a toggle endpoint, which writes the WC
	// option directly. The endpoint returns the full refreshed payload,
	// so consume it directly instead of firing a second GET.
	const toggleWcEmail = ( wcPostId: string, enabled: boolean ) => {
		resetError();
		const wcEmailId = wcPostId.replace( /^wc:/, '' );
		// Optimistic update so the row reflects the intent immediately;
		// rollback on error.
		const prev = data;
		setData( data.map( email => ( email.post_id === wcPostId ? { ...email, status: enabled ? 'publish' : 'draft' } : email ) ) );
		wizardApiFetch< EmailSettings >(
			{
				path: `/newspack/v1/wizard/newspack-settings/emails/${ wcEmailId }/toggle`,
				method: 'POST',
				data: { enabled },
			},
			{
				onSuccess( result: EmailSettings ) {
					// The server response is authoritative for any
					// downstream state we couldn't predict client-side
					// (e.g. first-run side effects on sibling rows). Use
					// it to reconcile state instead of refetching.
					setData( result.newspack_emails || [] );
				},
				onError() {
					setData( prev );
				},
			}
		);
	};

	const resetEmail = ( postId: number ) => {
		resetError();
		// Reset is served by Emails_Section under the pinned emails
		// namespace (NPPD-1535), so the moved Audience UI no longer reaches
		// into the donations wizard namespace.
		wizardApiFetch(
			{
				path: `/newspack/v1/wizard/newspack-settings/emails/${ postId }`,
				method: 'DELETE',
			},
			{
				onSuccess: () => fetchData(),
			}
		);
	};

	const fields: Field< EmailItem >[] = useMemo(
		() => [
			{
				id: 'preview',
				label: __( 'Preview', 'newspack-plugin' ),
				type: 'media',
				enableSorting: false,
				enableHiding: true,
				render: ( { item }: { item: EmailItem } ) => {
					// Smart fallback: WC rows emit `preview_id` via
					// serialize_wc_email_row (integer block-template post ID
					// or `wc:{id}` string). Newspack rows don't emit it —
					// they use their own integer post_id as the preview id
					// (the REST endpoint routes by post_type).
					const previewId = item.preview_id ?? ( typeof item.post_id === 'number' ? item.post_id : null );
					if ( ! previewId ) {
						return null;
					}
					// aria-label gives the anchor an accessible name —
					// the iframe inside has tabIndex=-1 and only a
					// generic "Email preview" title, so the wrapper
					// anchor is what assistive tech announces.
					return (
						<a
							href={ item.edit_link }
							className="newspack-emails__preview-link"
							aria-label={ sprintf(
								/* translators: %s: email label. */
								__( 'Edit %s', 'newspack-plugin' ),
								item.label
							) }
						>
							<EmailPreview postId={ previewId } />
						</a>
					);
				},
			},
			{
				id: 'name',
				label: __( 'Email', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item }: { item: EmailItem } ) => item.label,
				render: ( { item }: { item: EmailItem } ) => (
					<a href={ item.edit_link } className="newspack-emails__name-link">
						<strong>{ item.label }</strong>
					</a>
				),
			},
			{
				id: 'trigger_description',
				label: __( 'Description', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item }: { item: EmailItem } ) => item.trigger_description,
				render: ( { item }: { item: EmailItem } ) => (
					<span className="newspack-emails__trigger-description">{ item.trigger_description }</span>
				),
				enableHiding: false,
				enableSorting: false,
			},
			...( isNewspackPlatform
				? [
						{
							id: 'chip',
							label: __( 'Type', 'newspack-plugin' ),
							getValue: ( { item }: { item: EmailItem } ) => item.chip,
							render: ( { item }: { item: EmailItem } ) => <span>{ TYPES.find( type => type.value === item.chip )?.label }</span>,
							elements: TYPES,
							filterBy: { isPrimary: true, operators: [ 'isAny' as const ] },
							enableSorting: false,
						},
				  ]
				: [] ),
			{
				id: 'recipient',
				label: __( 'Recipient', 'newspack-plugin' ),
				getValue: ( { item }: { item: EmailItem } ) => item.recipient,
				render: ( { item }: { item: EmailItem } ) => (
					<span>{ item.recipient === 'admin' ? __( 'Admin', 'newspack-plugin' ) : __( 'Reader', 'newspack-plugin' ) }</span>
				),
				elements: [
					{ value: 'reader', label: __( 'Reader', 'newspack-plugin' ) },
					{ value: 'admin', label: __( 'Admin', 'newspack-plugin' ) },
				],
				filterBy: { isPrimary: true, operators: [ 'isAny' ] },
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				getValue: ( { item }: { item: EmailItem } ) => ( item.status === 'publish' ? 'publish' : 'draft' ),
				render: ( { item }: { item: EmailItem } ) => {
					const isEnabled = item.status === 'publish';
					return (
						<StatusIndicator status={ postStatus( item.status ) }>
							{ isEnabled ? __( 'Enabled', 'newspack-plugin' ) : __( 'Disabled', 'newspack-plugin' ) }
						</StatusIndicator>
					);
				},
				elements: [
					{
						value: 'publish',
						label: __( 'Enabled', 'newspack-plugin' ),
					},
					{
						value: 'draft',
						label: __( 'Disabled', 'newspack-plugin' ),
					},
				],
				filterBy: { isPrimary: true, operators: [ 'isAny' ] },
			},
		],
		[ isNewspackPlatform ]
	);

	const actions: EmailAction[] = [
		{
			id: 'edit',
			label: __( 'Edit', 'newspack-plugin' ),
			isPrimary: true,
			callback: ( items: EmailItem[] ) => {
				window.location.href = items[ 0 ].edit_link;
			},
		},
		{
			id: 'deactivate',
			label: __( 'Deactivate', 'newspack-plugin' ),
			// Eligibility is category- and status-based only — no source guard.
			// The callback routes by post_id type to pick the right endpoint.
			isEligible: ( item: EmailItem ) => item.category !== 'reader-activation' && item.status === 'publish',
			callback: ( items: EmailItem[] ) => {
				const item = items[ 0 ];
				if ( typeof item.post_id === 'string' ) {
					toggleWcEmail( item.post_id, false );
				} else {
					updateStatus( item.post_id, 'draft' );
				}
			},
		},
		{
			id: 'activate',
			label: __( 'Activate', 'newspack-plugin' ),
			isEligible: ( item: EmailItem ) => item.category !== 'reader-activation' && item.status !== 'publish',
			callback: ( items: EmailItem[] ) => {
				const item = items[ 0 ];
				if ( typeof item.post_id === 'string' ) {
					toggleWcEmail( item.post_id, true );
				} else {
					updateStatus( item.post_id, 'publish' );
				}
			},
		},
		{
			id: 'reset',
			label: __( 'Reset', 'newspack-plugin' ),
			isDestructive: true,
			// Source guard on reset only — WC emails aren't customized
			// through the post editor, so reset has no meaning for them.
			isEligible: ( item: EmailItem ) => item.source === 'newspack',
			callback: ( items: EmailItem[] ) => {
				// Runtime type narrowing — Newspack-source rows always carry an
				// integer post_id by contract, but a future source/provider
				// pair with `source: 'newspack'` + string post_id would slip
				// past isEligible. Matches the activate/deactivate callback
				// pattern that guards via typeof.
				const postId = items[ 0 ].post_id;
				if ( typeof postId !== 'number' ) {
					return;
				}
				if ( utils.confirmAction( __( 'Are you sure you want to reset the contents of this email?', 'newspack-plugin' ) ) ) {
					resetEmail( postId );
				}
			},
		},
	];

	const { data: processedData, paginationInfo } = useMemo( () => filterSortAndPaginate( data, view, fields ), [ data, view, fields ] );

	// The `preview` media field belongs to the grid view (each card
	// renders the iframe as its top tile). DataViews v14 also renders
	// the media field as a leading thumbnail in table view, where the
	// EmailPreview iframe is too constrained to actually show
	// content — leaving an empty rounded square next to every row.
	// Strip `mediaField` from the view passed to DataViews when
	// the user has switched to table layout; the underlying state
	// keeps the value so toggling back to grid restores the tile.
	const effectiveView = useMemo< View >( () => {
		if ( 'table' === view.type ) {
			const { mediaField: _stripped, ...rest } = view;
			return rest as View;
		}
		return view;
	}, [ view ] );

	// Normalize the view DataViews hands back before persisting it.
	// `effectiveView` strips `mediaField` while in table layout, so
	// without this a table→grid toggle would store a grid view that
	// lost its preview tile, leaving grid cards with no media field.
	// Grid always carries `mediaField: 'preview'`; table never does
	// (it's stripped in `effectiveView` regardless, but drop it here
	// too so the persisted state stays canonical). This keeps a
	// grid→table→grid round-trip lossless.
	const handleChangeView = useCallback( ( nextView: View ) => {
		if ( 'grid' === nextView.type ) {
			setView( { ...nextView, mediaField: 'preview' } );
			return;
		}
		const { mediaField: _stripped, ...rest } = nextView;
		setView( rest as View );
	}, [] );

	if ( false === pluginsReady ) {
		return (
			<Fragment>
				<PageHeading />
				<div className="newspack-emails__notices">
					<Notice
						isError
						noticeText={ __(
							'Newspack uses Newspack Newsletters to handle editing email-type content. Please activate this plugin to proceed. Until this feature is configured, default receipts will be used.',
							'newspack-plugin'
						) }
					/>
					<WizardsPluginCard
						slug="newspack-newsletters"
						title={ __( 'Newspack Newsletters', 'newspack-plugin' ) }
						description={ __( 'Newspack Newsletters is the plugin that powers Newspack email receipts.', 'newspack-plugin' ) }
						onStatusChange={ ( statuses: Record< string, boolean > ) => {
							if ( ! statuses.isLoading ) {
								setPluginsReady( statuses.isSetup );
							}
						} }
					/>
				</div>
			</Fragment>
		);
	}

	return (
		<Fragment>
			<PageHeading />
			{ errorMessage && (
				<div className="newspack-emails__notices">
					<Notice isError noticeText={ errorMessage } />
				</div>
			) }
			<DataViews
				className="newspack-emails"
				data={ processedData }
				fields={ fields }
				view={ effectiveView }
				onChangeView={ handleChangeView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				defaultLayouts={ { table: {}, grid: {} } }
				isLoading={ isFetching }
				getItemId={ ( item: EmailItem ) => String( item.post_id ) }
			>
				<Stack direction="row" justify="space-between" align="flex-start" gap="sm" className="dataviews__view-actions">
					<Stack direction="row" align="center" gap="xl" wrap="wrap" className="dataviews__search">
						<WPDataViews.Search />
						<WPDataViews.Filters />
					</Stack>
					<Stack direction="row" align="center" gap="xs">
						<WPDataViews.LayoutSwitcher />
						<WPDataViews.ViewConfig />
					</Stack>
				</Stack>
				<WPDataViews.Layout />
				<WPDataViews.Footer />
			</DataViews>
		</Fragment>
	);
};

export default Emails;
