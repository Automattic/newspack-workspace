/**
 * Institutions list view using DataViews.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import type { Action, Field, View } from '@wordpress/dataviews';
import { Button, Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { DataViews, Router } from '../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import InstitutionsOnboarding from './onboarding';

const { useHistory } = Router;

const API_PATH = '/wp/v2/np_institution';

const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 25,
	sort: { field: 'title', direction: 'asc' },
	search: '',
	fields: [ 'email_domain', 'ip_range', 'reader_data' ],
	filters: [],
	layout: {},
	titleField: 'title',
	mediaField: 'logo',
	descriptionField: 'description',
};

export default function Institutions() {
	const history = useHistory();
	const { setHeaderData, addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ data, setData ] = useState< Institution[] >( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ view, setView ] = useState< View >( DEFAULT_VIEW );

	useEffect( () => {
		const actions: HeaderAction[] = [
			{
				type: 'secondary',
				label: __( 'Back to Access control', 'newspack-plugin' ),
				icon: 'chevronLeft',
				href: '#/content-gates',
			},
		];
		if ( data.length !== 0 ) {
			actions.push( {
				type: 'primary',
				label: __( 'Add new institution', 'newspack-plugin' ),
				href: '#/institutions/new',
			} );
		}
		setHeaderData( {
			sectionName: __( 'Institutions', 'newspack-plugin' ),
			actions,
		} );
	}, [ setHeaderData, data, isLoading ] );

	const fetchData = useCallback( () => {
		setIsLoading( true );
		apiFetch< Institution[] >( { path: `${ API_PATH }?per_page=100&context=edit&_embed=wp:featuredmedia` } )
			.then( setData )
			.catch( () => {
				addNotice( {
					message: __( 'Failed to load institutions. Please refresh the page.', 'newspack-plugin' ),
					type: 'error',
					id: 'institutions-fetch-error',
				} );
			} )
			.finally( () => setIsLoading( false ) );
	}, [ addNotice ] );

	useEffect( () => {
		fetchData();
	}, [ fetchData ] );

	const fields: Field< Institution >[] = useMemo(
		() => [
			{
				id: 'logo',
				label: __( 'Logo', 'newspack-plugin' ),
				type: 'media',
				render: ( { item }: { item: Institution } ) => {
					const url = item._embedded?.[ 'wp:featuredmedia' ]?.[ 0 ]?.source_url;
					return url ? <img src={ url } alt={ item.title.raw } /> : null;
				},
				enableSorting: false,
			},
			{
				id: 'title',
				label: __( 'Title', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item }: { item: Institution } ) => item.title.raw,
				render: ( { item }: { item: Institution } ) => (
					<div>
						<strong>{ item.title.raw }</strong>
					</div>
				),
			},
			{
				id: 'description',
				label: __( 'Description', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item }: { item: Institution } ) => item.excerpt.raw,
				render: ( { item }: { item: Institution } ) =>
					item.excerpt.raw ? <div className="newspack-institutions__description">{ item.excerpt.raw }</div> : null,
			},
			{
				id: 'email_domain',
				label: __( 'Email domain', 'newspack-plugin' ),
				getValue: ( { item }: { item: Institution } ) => item.meta?.np_institution_email_domain || '',
				render: ( { item }: { item: Institution } ) => {
					const val = item.meta?.np_institution_email_domain;
					return val ? <code>{ val }</code> : <span className="newspack-institutions__empty">&mdash;</span>;
				},
			},
			{
				id: 'ip_range',
				label: __( 'IP range', 'newspack-plugin' ),
				getValue: ( { item }: { item: Institution } ) => item.meta?.np_institution_ip_range || '',
				render: ( { item }: { item: Institution } ) => {
					const val = item.meta?.np_institution_ip_range;
					return val ? <code>{ val }</code> : <span className="newspack-institutions__empty">&mdash;</span>;
				},
			},
			{
				id: 'reader_data',
				label: __( 'Reader data', 'newspack-plugin' ),
				getValue: ( { item }: { item: Institution } ) => item.meta?.np_institution_reader_data || '',
				render: ( { item }: { item: Institution } ) => {
					const val = item.meta?.np_institution_reader_data;
					return val ? <code>{ val }</code> : <span className="newspack-institutions__empty">&mdash;</span>;
				},
			},
		],
		[]
	);

	const actions: Action< Institution >[] = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'newspack-plugin' ),
				isPrimary: true,
				callback: ( items: Institution[] ) => {
					history.push( `/institutions/${ items[ 0 ].id }` );
				},
			},
			{
				id: 'copy-url',
				label: __( 'Copy access page URL', 'newspack-plugin' ),
				callback: ( items: Institution[] ) => {
					const baseUrl = ( window as any ).newspackAudience?.institutional_access_url;
					const url = baseUrl ? `${ baseUrl }/${ items[ 0 ].slug }/` : '';
					if ( url ) {
						navigator.clipboard.writeText( url ).then(
							() => {
								addNotice( {
									message: __( 'URL copied to clipboard.', 'newspack-plugin' ),
									type: 'success',
									id: 'institution-url-copied',
								} );
							},
							() => {
								addNotice( {
									message: __( 'Failed to copy URL. Please copy it manually.', 'newspack-plugin' ),
									type: 'error',
									id: 'institution-url-copy-error',
								} );
							}
						);
					}
				},
			},
			{
				id: 'delete',
				label: __( 'Delete', 'newspack-plugin' ),
				isDestructive: true,
				RenderModal: ( { items, closeModal }: { items: Institution[]; closeModal: () => void } ) => {
					const item = items[ 0 ];
					const [ isDeleting, setIsDeleting ] = useState( false );
					return (
						<div>
							<p>{ __( 'This will permanently delete this institution. This action cannot be undone.', 'newspack-plugin' ) }</p>
							<div style={ { display: 'flex', gap: '8px', justifyContent: 'flex-end' } }>
								<Button variant="tertiary" onClick={ closeModal } disabled={ isDeleting }>
									{ __( 'Cancel', 'newspack-plugin' ) }
								</Button>
								<Button
									variant="primary"
									isDestructive
									isBusy={ isDeleting }
									disabled={ isDeleting }
									onClick={ () => {
										setIsDeleting( true );
										apiFetch( { path: `${ API_PATH }/${ item.id }?force=true`, method: 'DELETE' } )
											.then( () => {
												fetchData();
												closeModal();
											} )
											.catch( () => {
												setIsDeleting( false );
												closeModal();
												addNotice( {
													message: __( 'Failed to delete institution. Please try again.', 'newspack-plugin' ),
													type: 'error',
													id: 'institution-delete-error',
												} );
											} );
									} }
								>
									{ __( 'Delete', 'newspack-plugin' ) }
								</Button>
							</div>
						</div>
					);
				},
			},
		],
		[ addNotice, fetchData, history ]
	);

	const { data: processedData, paginationInfo } = useMemo( () => filterSortAndPaginate( data, view, fields ), [ data, view, fields ] );

	if ( isLoading ) {
		return (
			<div style={ { display: 'flex', justifyContent: 'center', alignItems: 'center' } }>
				<Spinner />
			</div>
		);
	}

	if ( ! isLoading && data.length === 0 ) {
		return <InstitutionsOnboarding />;
	}

	return (
		<DataViews
			className="newspack-institutions"
			data={ processedData }
			fields={ fields }
			view={ view }
			onChangeView={ setView }
			actions={ actions }
			paginationInfo={ paginationInfo }
			defaultLayouts={ { table: {}, grid: {} } }
			isLoading={ isLoading }
			getItemId={ ( item: Institution ) => String( item.id ) }
			search
		/>
	);
}
