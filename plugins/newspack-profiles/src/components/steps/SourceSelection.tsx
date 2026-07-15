/* eslint-disable @wordpress/no-unsafe-wp-apis */
import {
	Button,
	Notice,
	__experimentalGrid as Grid,
	__experimentalHStack as HStack,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { SourceTypeIcon } from '../SourceTypeIcon';
import classNames from 'classnames';
import { store as onboardingStore } from '../../stores/onboarding';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { isSameDataSource } from '../../utils';
import type { DataSourceConfig } from '../../types/data-source';
import { useEditContext } from '../../context/EditContext';
import './SourceSelection.scss';

/**
 * Component for selecting data source.
 *
 * @return JSX.Element The SourceSelection component.
 */
export const SourceSelection = () => {
	const isEdit = useEditContext();

	const [ sources, setSources ] = useState< DataSourceConfig[] >(
		window.NewspackProfilesSettingsConfig.availableDataSources
	);
	const [ isRefreshing, setIsRefreshing ] = useState< boolean >( false );

	const selectedDataSource = useSelect( ( select ) => {
		return select( onboardingStore ).getDataSource();
	}, [] );

	const { setDataSource } = useDispatch( onboardingStore );

	const filteredSources = sources.filter(
		( source ) => source.type !== 'wpdb'
	);

	const isSelectedSourceTypeWPDB = selectedDataSource.type === 'wpdb';

	const handleRefreshSources = async () => {
		try {
			setIsRefreshing( true );

			const refreshedSources: DataSourceConfig[] = await apiFetch( {
				method: 'GET',
				path: '/newspack-profiles/v1/data-sources',
			} );

			// Update global settings data to keep it in sync.
			window.NewspackProfilesSettingsConfig.availableDataSources =
				refreshedSources;

			setSources( refreshedSources );
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( 'Failed to refresh data sources', error );
		} finally {
			setIsRefreshing( false );
		}
	};

	return (
		<div className="newspack-profiles__source-selection">
			<HStack
				justify="space-between"
				alignment="flex-start"
				className="newspack-profiles__source-selection__header"
			>
				<div>
					<h3>{ __( 'Select Data Source', 'newspack-profiles' ) }</h3>
					<p>
						{ __(
							'Choose the source of your profile data.',
							'newspack-profiles'
						) }
					</p>
				</div>
				<Button
					variant="secondary"
					onClick={ handleRefreshSources }
					className="newspack-profiles__source-selection__refresh"
					disabled={ isRefreshing }
				>
					{ isRefreshing
						? __( 'Refreshing…', 'newspack-profiles' )
						: __( 'Refresh Data Sources', 'newspack-profiles' ) }
				</Button>
			</HStack>

			{ isEdit && ! isSelectedSourceTypeWPDB && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Changing the data source will recreate all profiles and may break existing links.',
						'newspack-profiles'
					) }
				</Notice>
			) }

			{ isEdit && isSelectedSourceTypeWPDB && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The data source cannot be changed once profiles have been imported.',
						'newspack-profiles'
					) }
				</Notice>
			) }

			<div
				className={ classNames( {
					'newspack-profiles__source-selection__options--disabled':
						isSelectedSourceTypeWPDB,
				} ) }
			>
				{ filteredSources?.length ? (
					<fieldset className="newspack-profiles__source-selection__fieldset">
						<Grid
							columns={ 2 }
							gap={ 4 }
							className="newspack-profiles__source-grid"
						>
							{ filteredSources.map( ( source, index ) => (
								<SourceOption
									key={ index }
									source={ source }
									isSelected={ isSameDataSource(
										source,
										selectedDataSource
									) }
									onSelect={ () => setDataSource( source ) }
								/>
							) ) }
							<Button
								href={
									window.NewspackProfilesSettingsConfig
										.remoteDataBlocksSettingsPageURL
								}
								target="_blank"
								variant="tertiary"
								className="newspack-profiles__source-add"
							>
								{ __( 'Add Data Source', 'newspack-profiles' ) }
							</Button>
						</Grid>
					</fieldset>
				) : (
					<div>
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'No data sources are configured. Please add a data source to proceed.',
								'newspack-profiles'
							) }
						</Notice>
						<Button
							href={
								window.NewspackProfilesSettingsConfig
									.remoteDataBlocksSettingsPageURL
							}
							target="_blank"
							rel="noopener noreferrer"
							variant="secondary"
						>
							{ __( 'Add Data Source', 'newspack-profiles' ) }
						</Button>
					</div>
				) }
			</div>
		</div>
	);
};

const SourceOption = ( {
	source,
	isSelected,
	onSelect,
}: {
	source: DataSourceConfig;
	isSelected: boolean;
	onSelect: () => void;
} ) => {
	const info = [
		{
			label: __( 'Base/Spreadsheet', 'newspack-profiles' ),
			value: source.spreadsheet ?? source.base,
		},
		{
			label: __( 'Table/Sheet', 'newspack-profiles' ),
			value: source.sheet ?? source.table,
		},
	];

	const id = getOptionId( source );

	return (
		<label
			className={ classNames( 'newspack-profiles__source-option', {
				'is-selected': isSelected,
			} ) }
			htmlFor={ id }
		>
			<input
				id={ id }
				type="radio"
				className="newspack-profiles__source-option__input"
				checked={ isSelected }
				onChange={ onSelect }
			/>
			<SourceTypeIcon
				sourceType={ source.type }
				className="newspack-profiles__source-option__icon"
				size={ 60 }
			/>
			<div className="newspack-profiles__source-option__content">
				<div className="newspack-profiles__source-option__name">
					{ source.name }
				</div>
				<div className="newspack-profiles__source-option__details">
					{ info.map( ( item ) =>
						item.value ? (
							<div
								key={ item.label }
								className="newspack-profiles__source-option__detail"
							>
								<strong>{ item.label }:</strong> { item.value }
							</div>
						) : null
					) }
				</div>
			</div>
		</label>
	);
};

const getOptionId = ( source: DataSourceConfig ) => {
	return `source-${ source.type }-${ source.name }-${
		source.base ?? source.spreadsheet
	}-${ source.table ?? source.sheet }`
		.replaceAll( /\s+/g, '-' )
		.toLowerCase();
};
