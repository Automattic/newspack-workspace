/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * External dependencies.
 */
import debounce from 'lodash/debounce';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useState, useMemo, useCallback } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews/wp';
import {
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Button,
	Modal,
	Notice,
	ProgressBar,
	ToggleControl,
} from '@wordpress/components';
import { update } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import utils from '../utils';
import { NAMESPACE as storeNamespace } from '../store/constants';
import { useStoryFields, useStoryActions, useView } from '../hooks';

export default () => {
	const { stories, isLoading, isRefreshing, progress, errors, canManage, canRefreshStories } = useSelect( select => ( {
		stories: select( storeNamespace ).getStories(),
		isLoading: select( storeNamespace ).isLoading(),
		isRefreshing: select( storeNamespace ).isRefreshing(),
		progress: select( storeNamespace ).getProgress(),
		errors: select( storeNamespace ).getErrors(),
		canManage: select( storeNamespace ).canManage(),
		canRefreshStories: select( storeNamespace ).canRefreshStories(),
	} ) );
	const [ editMode, setEditMode ] = useState( false );

	useEffect( () => {
		setEditMode( applyFilters( 'newspack-story-budget.defaultEditMode', false ) );
	}, [] );

	const [ isReconnectingRemoteSite, setIsReconnectingRemoteSite ] = useState( false );

	const view = useView();
	const currentStories = useMemo( () => {
		return stories.slice( ( view.page - 1 ) * view.perPage, ( view.page - 1 ) * view.perPage + view.perPage );
	}, [ stories, view.page, view.perPage ] );

	// Scroll to top when changing page.
	useEffect( () => {
		window.scrollTo( 0, 0 );
	}, [ view.page ] );

	const { clearErrors, setView, setSearching, search, fetchFields, refreshStories } = useDispatch( storeNamespace );

	const doSearch = useMemo( () => debounce( search, 300 ), [ search ] );

	useEffect( () => {
		if ( view.search ) {
			setSearching();
			doSearch( view.search );
		}
	}, [ view.search ] );

	useEffect( () => {
		return () => {
			if ( utils.budgets.isBudgetStories() ) {
				utils.budgets.redirectWithCleanUrl();
			}
		};
	}, [] );

	const dataViewFields = useStoryFields( {
		allowEdit: editMode && ! isRefreshing,
	} );

	const actions = useStoryActions();

	const refresh = useCallback( () => {
		clearErrors();
		fetchFields();
		refreshStories( false );
	}, [ clearErrors, fetchFields, refreshStories ] );

	const paginationInfo = useMemo(
		() => ( {
			totalItems: stories.length,
			totalPages: Math.ceil( stories.length / view.perPage ),
		} ),
		[ stories.length, view.perPage ]
	);

	const defaultLayouts = useMemo(
		() => ( {
			table: {
				showMedia: false,
			},
		} ),
		[]
	);

	if ( isLoading && undefined !== progress && progress < 1 ) {
		return (
			<div className="newspack-story-budget__loading">
				<ProgressBar value={ Math.ceil( progress * 100 ) } />
				<p>{ __( 'Fetching Stories…', 'newspack-story-budget' ) }</p>
			</div>
		);
	}

	if ( errors?.stories ) {
		return (
			<Modal
				isOpen
				isDismissible={ false }
				size="small"
				title={ __( 'Something went wrong', 'newspack-story-budget' ) }
				shouldCloseOnClickOutside={ false }
			>
				<VStack spacing={ 4 }>
					<Notice className="newspack-story-budget__error" isDismissible={ false } status="error">
						{ errors.stories }
					</Notice>
					<HStack expanded spacing={ 2 } justify="end" direction="row-reverse">
						{ utils.sites.isRemoteSite() ? (
							<>
								<Button
									variant="primary"
									onClick={ () => {
										utils.sites.connect();
										setIsReconnectingRemoteSite( true );
									} }
									isBusy={ isReconnectingRemoteSite }
									disabled={ isReconnectingRemoteSite }
								>
									{ __( 'Reconnect', 'newspack-story-budget' ) }
								</Button>
								<Button variant="secondary" href={ utils.sites.getLeaveSiteUrl() }>
									{ __( 'Leave remote site', 'newspack-story-budget' ) }
								</Button>
							</>
						) : (
							<Button
								variant="primary"
								onClick={ () => {
									window.location.reload();
								} }
							>
								{ __( 'Reload page', 'newspack-story-budget' ) }
							</Button>
						) }
					</HStack>
				</VStack>
			</Modal>
		);
	}

	return (
		<DataViews
			isLoading={ isLoading }
			fields={ dataViewFields }
			view={ view }
			onChangeView={ setView }
			actions={ actions }
			data={ isLoading ? [] : currentStories }
			paginationInfo={ paginationInfo }
			defaultLayouts={ defaultLayouts }
			header={
				<HStack style={ { marginLeft: '8px' } }>
					{ canRefreshStories && (
						<Button
							className={
								isLoading || isRefreshing ? 'newspack-story-budget__refresh-button-is-busy' : 'newspack-story-budget__refresh-button'
							}
							icon={ update }
							disabled={ isLoading || isRefreshing }
							label={
								isLoading || isRefreshing
									? __( 'Loading stories…', 'newspack-story-budget' )
									: __( 'Refresh all stories', 'newspack-story-budget' )
							}
							size="compact"
							onClick={ refresh }
						/>
					) }
					{ canManage && (
						<ToggleControl
							label={ __( 'Edit mode', 'newspack-story-budget' ) }
							checked={ editMode }
							onChange={ setEditMode }
							__nextHasNoMarginBottom
						/>
					) }
				</HStack>
			}
		/>
	);
};
