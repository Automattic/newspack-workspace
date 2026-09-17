/**
 * The Subscriptions wizard's Subscriber discounts tab: rules giving a
 * subscription's subscribers money off store products.
 */

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import { addQueryArgs } from '@wordpress/url';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import type { Action, View } from '@wordpress/dataviews';
import { percent } from '@wordpress/icons';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, DataViews, Grid, Notice, SectionHeader, Waiting } from '../../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../../packages/components/src/wizard/store';
import Router from '../../../../../../../packages/components/src/proxied-imports/router';
import { SEARCH_ENDPOINTS, WIZARD_ENDPOINT } from '../../constants';
import { useNames } from '../../use-names';
import { registerTab } from '../registry';
import { DISCOUNTS_ENDPOINT } from './constants';
import { DEFAULT_CURRENCY } from './discount';
import { discountFields } from './fields';
import DiscountEditor from './editor';
import SettingsModal from './settings-modal';
import type { DiscountRule, DiscountsPayload } from './types';

import './style.scss';

const { useHistory, useLocation, useRouteMatch } = Router;

const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 25,
	sort: { field: 'created_at', direction: 'desc' },
	search: '',
	fields: [ 'status', 'discount', 'applies_to', 'created_at' ],
	filters: [],
	layout: {},
	titleField: 'subscription',
};

function SubscriberDiscounts() {
	const [ payload, setPayload ] = useState< DiscountsPayload >( {
		rules: [],
		settings: { apply_on_sale: true, apply_at_checkout: false },
		currency: DEFAULT_CURRENCY,
	} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ view, setView ] = useState< View >( DEFAULT_VIEW );
	const [ showSettings, setShowSettings ] = useState( false );
	const [ error, setError ] = useState( '' );
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	// The editor is routed rather than held in state so rules stay addressable.
	const history = useHistory();
	const { pathname } = useLocation();
	const { url: baseUrl } = useRouteMatch();
	const newMatch = useRouteMatch( `${ baseUrl }/new` );
	const editMatch = useRouteMatch< { id: string } >( `${ baseUrl }/edit/:id` );
	const closeEditor = useCallback( () => history.push( baseUrl ), [ history, baseUrl ] );

	const reportFailure = ( apiError: { message?: string } ) =>
		setError( apiError?.message || __( 'That change could not be saved.', 'newspack-plugin' ) );

	useEffect( () => {
		apiFetch< DiscountsPayload >( { path: DISCOUNTS_ENDPOINT } )
			.then( setPayload )
			.catch( reportFailure )
			.finally( () => setIsLoading( false ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const [ subscriptionOptions, setSubscriptionOptions ] = useState< { id: number; name: string }[] >( [] );

	useEffect( () => {
		apiFetch< { id: number; name: string }[] >( {
			path: addQueryArgs( `${ WIZARD_ENDPOINT }/${ SEARCH_ENDPOINTS.subscriptions }`, { per_page: 100 } ),
		} )
			// Decoded once here, so every name in the merged list below is in the
			// same encoding.
			.then( items => setSubscriptionOptions( ( items || [] ).map( item => ( { ...item, name: decodeEntities( item.name ) } ) ) ) )
			.catch( () => setSubscriptionOptions( [] ) );
	}, [] );

	const applyPayload = useCallback( ( next: DiscountsPayload ) => {
		setPayload( next );
		setError( '' );
		setShowSettings( false );
	}, [] );

	const setActive = useCallback( ( rule: DiscountRule, active: boolean ) => {
		apiFetch< DiscountsPayload >( {
			path: DISCOUNTS_ENDPOINT,
			method: 'POST',
			data: { ...rule, active },
		} )
			.then( next => {
				setPayload( next );
				setError( '' );
			} )
			.catch( reportFailure );
	}, [] );

	const deleteRule = useCallback( ( rule: DiscountRule ) => {
		apiFetch< DiscountsPayload >( {
			path: `${ DISCOUNTS_ENDPOINT }/${ rule.id }`,
			method: 'DELETE',
		} )
			.then( next => {
				setPayload( next );
				setError( '' );
			} )
			.catch( reportFailure );
	}, [] );

	const { currency } = payload;

	const editingId = editMatch?.params.id;
	const editingRule = useMemo(
		() => ( editingId ? payload.rules.find( rule => rule.id === editingId ) ?? null : null ),
		[ editingId, payload.rules ]
	);
	const isEditorOpen = !! newMatch || !! editingRule;

	useEffect( () => {
		if ( ! isLoading && editingId && ! editingRule ) {
			setError( __( 'That discount could not be found.', 'newspack-plugin' ) );
			history.replace( baseUrl );
		}
	}, [ isLoading, editingId, editingRule, history, baseUrl ] );

	// The picker's one page of suggestions names most subscriptions, but not a
	// trashed one and not the overflow on a store with more than a page of
	// them. Resolving the ids the rules actually hold covers both, and is what
	// keeps a row from counting a subscription it could have named.
	const ruleSubscriptionIds = useMemo(
		() => [ ...new Set( payload.rules.flatMap( rule => rule.subscription_product_ids || [] ) ) ],
		[ payload.rules ]
	);
	const resolvedNames = useNames( SEARCH_ENDPOINTS.subscriptions, ruleSubscriptionIds );

	const namedSubscriptions = useMemo( () => {
		const byId = new Map< number, string >( subscriptionOptions.map( option => [ option.id, option.name ] ) );
		Object.entries( resolvedNames ).forEach( ( [ id, name ] ) => byId.set( Number( id ), name ) );
		return [ ...byId ].map( ( [ id, name ] ) => ( { id, name } ) );
	}, [ subscriptionOptions, resolvedNames ] );

	const fields = useMemo( () => discountFields( currency, namedSubscriptions ), [ currency, namedSubscriptions ] );

	const actions: Action< DiscountRule >[] = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'newspack-plugin' ),
				isPrimary: true,
				callback: ( [ item ]: DiscountRule[] ) => history.push( `${ baseUrl }/edit/${ item.id }` ),
			},
			{
				id: 'toggle-active',
				label: ( [ item ]: DiscountRule[] ) =>
					item?.active ? __( 'Set to inactive', 'newspack-plugin' ) : __( 'Set to active', 'newspack-plugin' ),
				callback: ( [ item ]: DiscountRule[] ) => setActive( item, ! item.active ),
			},
			{
				id: 'delete',
				label: __( 'Delete', 'newspack-plugin' ),
				isDestructive: true,
				// Deleting a rule cannot be undone and immediately changes what
				// readers are charged, so it is confirmed rather than one-click.
				RenderModal: ( { items, closeModal }: { items: DiscountRule[]; closeModal?: () => void } ) => (
					<VStack spacing={ 4 }>
						<p>{ __( 'This discount will stop applying immediately. This cannot be undone.', 'newspack-plugin' ) }</p>
						<HStack spacing={ 2 } justify="flex-end">
							<Button variant="secondary" onClick={ closeModal }>
								{ __( 'Cancel', 'newspack-plugin' ) }
							</Button>
							<Button
								variant="primary"
								isDestructive
								onClick={ () => {
									items.forEach( deleteRule );
									closeModal?.();
								} }
							>
								{ __( 'Delete', 'newspack-plugin' ) }
							</Button>
						</HStack>
					</VStack>
				),
			},
		],
		[ setActive, deleteRule, history, baseUrl ]
	);

	const { data: processedData, paginationInfo } = useMemo(
		() => filterSortAndPaginate( payload.rules, view, fields ),
		[ payload.rules, view, fields ]
	);

	const hasRules = payload.rules.length > 0;
	const showEmptyState = ! isLoading && ! hasRules;
	const total = paginationInfo?.totalItems ?? 0;

	useEffect( () => {
		if ( isLoading || ! hasRules ) {
			setHeaderData( { sectionName: __( 'Subscriber Discounts', 'newspack-plugin' ), actions: [] } );
			return;
		}
		setHeaderData( {
			sectionName: (
				<>
					{ __( 'Subscriber Discounts', 'newspack-plugin' ) }{ ' ' }
					<span
						className="newspack-subscriber-discounts__header-count"
						aria-label={ sprintf(
							/* translators: %d: number of discount rules listed. */
							_n( '%d discount total', '%d discounts total', total, 'newspack-plugin' ),
							total
						) }
					>
						{ `(${ total.toLocaleString() })` }
					</span>
				</>
			),
			actions: [ { type: 'primary', label: __( 'Add Discount', 'newspack-plugin' ), action: () => history.push( `${ baseUrl }/new` ) } ],
		} );
		// `pathname` re-asserts the header after the wizard's ResetHeaderData
		// clears it on every route change, drawer routes included.
	}, [ setHeaderData, isLoading, hasRules, total, history, baseUrl, pathname ] );

	if ( isLoading ) {
		return (
			<div className="newspack-subscriber-discounts">
				<div className="newspack-subscriber-discounts__fetching">
					<VStack alignment="center" spacing={ 2 }>
						<Waiting noMargin />
						<strong>{ __( 'Fetching…', 'newspack-plugin' ) }</strong>
					</VStack>
				</div>
			</div>
		);
	}

	return (
		<div className="newspack-subscriber-discounts">
			{ error && <Notice isError noticeText={ error } /> }
			{ showEmptyState ? (
				<div className="newspack-subscriber-discounts__empty">
					<Grid className="newspack-empty-state" columns={ 4 } noMargin>
						{ /* The Grid stylesheet matches start and end as DOM attributes, so they cannot be passed as typed props. */ }
						<VStack { ...( { start: 2, end: 4 } as React.ComponentProps< 'div' > ) } spacing={ 8 }>
							<SectionHeader
								className="newspack-empty-state__header"
								icon={ percent }
								title={ __( 'Get started with subscriber discounts', 'newspack-plugin' ) }
								description={ __(
									'Offer subscribers a discount on your products. Create your first rule to choose which subscription gets what off which products.',
									'newspack-plugin'
								) }
								heading={ 2 }
								pageHeader
								noMargin
							/>
							<HStack alignment="center" spacing={ 2 } wrap className="newspack-empty-state__actions">
								<Button variant="primary" onClick={ () => history.push( `${ baseUrl }/new` ) }>
									{ __( 'Add Discount', 'newspack-plugin' ) }
								</Button>
							</HStack>
						</VStack>
					</Grid>
				</div>
			) : (
				<DataViews
					data={ processedData }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions }
					paginationInfo={ paginationInfo }
					defaultLayouts={ { table: {} } }
					isLoading={ isLoading }
					getItemId={ ( item: DiscountRule ) => item.id }
					onClickItem={ ( item: DiscountRule ) => history.push( `${ baseUrl }/edit/${ item.id }` ) }
					search
					header={
						<Button variant="secondary" size="compact" onClick={ () => setShowSettings( true ) }>
							{ __( 'Settings', 'newspack-plugin' ) }
						</Button>
					}
				/>
			) }
			<DiscountEditor
				isOpen={ isEditorOpen }
				rule={ editingRule }
				currency={ currency }
				onSaved={ next => {
					applyPayload( next );
					closeEditor();
				} }
				onClose={ closeEditor }
			/>
			{ showSettings && <SettingsModal settings={ payload.settings } onSaved={ applyPayload } onClose={ () => setShowSettings( false ) } /> }
		</div>
	);
}

registerTab( 'discounts', { render: () => <SubscriberDiscounts />, fullWidth: true, rendersLeafCrumb: true } );
