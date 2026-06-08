import { useSelect, useDispatch } from '@wordpress/data';
import { Button, Icon } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SourceTypeIcon } from '../SourceTypeIcon';
import classNames from 'classnames';
import { store as onboardingStore } from '../../stores/onboarding';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { isSameDataSource } from '../../utils';
import type { DataSourceConfig } from '../../types/data-source';
import { useEditContext } from '../../context/EditContext';

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
		<div className="px-6 py-2">
			<div className="flex items-start justify-between flex-col md:flex-row md:items-center">
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
					className="mt-2"
					disabled={ isRefreshing }
				>
					{ isRefreshing
						? __( 'Refreshing…', 'newspack-profiles' )
						: __( 'Refresh Data Sources', 'newspack-profiles' ) }
				</Button>
			</div>

			{ isEdit && ! isSelectedSourceTypeWPDB && (
				<p className="text-sm text-yellow-700 bg-yellow-100 border border-yellow-300 py-1.5 px-2 rounded">
					<Icon icon="warning" className="align-middle me-1" />
					{ __(
						'Changing the data source will recreate all profiles and may break existing links.',
						'newspack-profiles'
					) }
				</p>
			) }

			{ isEdit && isSelectedSourceTypeWPDB && (
				<p className="text-sm text-yellow-700 bg-yellow-100 border border-yellow-300 py-1.5 px-2 rounded">
					<Icon icon="warning" className="align-middle me-1" />
					{ __(
						'The data source cannot be changed once profiles have been imported.',
						'newspack-profiles'
					) }
				</p>
			) }

			<div
				className={ classNames( {
					'pointer-events-none opacity-50': isSelectedSourceTypeWPDB,
				} ) }
			>
				{ filteredSources?.length ? (
					<fieldset className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
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
							className={
								'flex items-center justify-center text-base text-gray-500 rounded-md p-10 m-0 border border-dashed border-gray-300 hover:border-[var(--wp-admin-theme-color)] hover:bg-[var(--wp-admin-theme-color)]/5'
							}
						>
							{ __( 'Add Data Source', 'newspack-profiles' ) }
						</Button>
					</fieldset>
				) : (
					<div>
						<p className="my-6 px-2 py-1 border border-yellow-300 bg-yellow-50 rounded flex items-center gap-1 w-fit">
							<Icon
								icon="warning"
								size={ 24 }
								className="text-yellow-600 flex-shrink-0"
							/>
							<span className="text-yellow-800">
								{ __(
									'No data sources are configured. Please add a data source to proceed.',
									'newspack-profiles'
								) }
							</span>
						</p>
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
			label: 'Base/Spreadsheet',
			value: source.spreadsheet ?? source.base,
		},
		{
			label: 'Table/Sheet',
			value: source.sheet ?? source.table,
		},
	];

	const id = getOptionId( source );

	return (
		<label
			className={ classNames(
				'flex items-center border rounded-md p-2 hover:border-[var(--wp-admin-theme-color)] cursor-pointer',
				{
					'border-[var(--wp-admin-theme-color)] bg-[var(--wp-admin-theme-color)]/5':
						isSelected,
					'border-gray-300': ! isSelected,
				}
			) }
			htmlFor={ id }
		>
			<input
				id={ id }
				type="radio"
				className="hidden"
				checked={ isSelected }
				onChange={ onSelect }
			/>
			<SourceTypeIcon
				sourceType={ source.type }
				className="me-4"
				size={ 60 }
			/>
			<div>
				<div className="flex items-center font-semibold text-gray-700 text-base">
					{ source.name }
				</div>
				<div className="text-sm text-gray-500 mt-1">
					{ info.map( ( item ) =>
						item.value ? (
							<div key={ item.label } className="me-4">
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
