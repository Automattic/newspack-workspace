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
 * External dependencies.
 */
import type { ComponentProps } from 'react';
// `moduleResolution: node` doesn't honor package.json `exports` subpaths for type resolution
// (only full Node16/bundler resolution does), so types must come from the base specifier --
// the runtime import above still uses `/wp` for the WordPress-flavored build.
import type { View } from '@wordpress/dataviews';

/**
 * Internal dependencies.
 */
import utils from '../utils';
import { NAMESPACE as storeNamespace } from '../store/constants';
import { useStoryFields, useStoryActions, useView } from '../hooks';
import type { Story, StoryBudgetSelectors } from './types';

export default () => {
	const { stories, isLoading, isRefreshing, progress, errors, canManage, canRefreshStories } = useSelect( select => {
		const selectors = select( storeNamespace ) as StoryBudgetSelectors;
		return {
			stories: selectors.getStories(),
			isLoading: selectors.isLoading(),
			isRefreshing: selectors.isRefreshing(),
			progress: selectors.getProgress(),
			errors: selectors.getErrors(),
			canManage: selectors.canManage(),
			canRefreshStories: selectors.canRefreshStories(),
		};
	} );
	const [ editMode, setEditMode ] = useState( false );

	useEffect( () => {
		setEditMode( applyFilters( 'newspack-story-budget.defaultEditMode', false ) as boolean );
	}, [] );

	const [ isReconnectingRemoteSite, setIsReconnectingRemoteSite ] = useState( false );

	// This subtree's own `View` shape (`StoryBudgetView`) doesn't match `@wordpress/dataviews`'
	// own `View` union (which `<DataViews>` below requires), so this is typed against theirs.
	const view = useView() as View;
	const currentStories = useMemo( () => {
		return stories.slice( ( view.page! - 1 ) * view.perPage!, ( view.page! - 1 ) * view.perPage! + view.perPage! );
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
			totalPages: Math.ceil( stories.length / view.perPage! ),
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
		// Two pre-existing gaps against the real `ModalProps`, neither introduced here: `isOpen`
		// isn't a real Modal prop (it's shown simply by being mounted) -- a silent no-op. More
		// seriously, `onRequestClose` is required AND is invoked unconditionally (no optional
		// chaining) by `@wordpress/components`' Modal on ESC/dismiss, so omitting it looks like
		// a real latent crash-on-ESC bug, not just a cosmetic type gap -- flagging, not fixing.
		const modalProps: Partial< ComponentProps< typeof Modal > > & Record< string, unknown > = {
			isOpen: true,
			isDismissible: false,
			size: 'small',
			title: __( 'Something went wrong', 'newspack-story-budget' ),
			shouldCloseOnClickOutside: false,
		};
		return (
			<Modal { ...( modalProps as ComponentProps< typeof Modal > ) }>
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

	// `Story['id']` is `number | string`, which doesn't satisfy DataViews' `ItemWithId` (`id:
	// string`) constraint, so it would otherwise require a `getItemId` the original code never
	// passed. The whole props bag is cast as a unit (as with other untyped-boundary components
	// in this subtree) rather than inventing a `getItemId` not present in the original.
	const dataViewsProps = {
		isLoading,
		fields: dataViewFields,
		view,
		onChangeView: setView,
		actions,
		data: isLoading ? [] : currentStories,
		paginationInfo,
		defaultLayouts,
		header: (
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
		),
	} as ComponentProps< typeof DataViews< Story > >;

	return <DataViews { ...dataViewsProps } />;
};
