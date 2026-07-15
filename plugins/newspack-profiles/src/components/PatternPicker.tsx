import { __ } from '@wordpress/i18n';
import { parse } from '@wordpress/blocks';
import { BlockPreview } from '@wordpress/block-editor';
import {
	ActionButton,
	DataViewsPicker,
	Field,
	filterSortAndPaginate,
	View,
} from '@wordpress/dataviews/wp';
import { memo, useMemo, useState } from '@wordpress/element';
import { type Pattern } from '../types/pattern';

type PatternPickerProps = {
	selectedPattern: string;
	patternType: string;
	onSelect: ( items: string[] ) => void;
};

/**
 * Component for picking block patterns of a specific type.
 * Build using DataViewsPicker.
 *
 * @param {PatternPickerProps} props - Component props.
 *
 * @return JSX.Element The PatternPicker component.
 */
export const PatternPicker = ( {
	selectedPattern,
	patternType,
	onSelect,
}: PatternPickerProps ) => {
	const patterns = useMemo(
		() =>
			window.NewspackProfilesSettingsConfig.patterns.filter(
				( pattern ) => pattern.type === patternType
			),
		[ patternType ]
	);

	const [ view, setView ] = useState< View >( {
		type: 'pickerGrid',
		page: 1,
		perPage: 8,
		search: '',
		titleField: 'title',
		mediaField: 'preview',
		layout: {
			previewSize: 180,
		},
	} );

	const fields: Field< Pattern >[] = useMemo(
		() => [
			{
				id: 'title',
				label: __( 'Title', 'newspack-profiles' ),
				enableGlobalSearch: true,
			},
			{
				id: 'preview',
				label: __( 'Preview', 'newspack-profiles' ),
				enableSorting: false,
				render: memo(
					( { item }: { item: Pattern } ) => {
						const blocks = parse( item.content );
						return (
							<BlockPreview.Async>
								<BlockPreview
									blocks={ blocks }
									viewportWidth={ 800 }
									additionalStyles={ [
										{
											css: `
												.is-root-container {
													margin: 0 auto;
													padding: 40px;
													padding-bottom: 0;
													max-width: 500px;
												}
											`,
										},
									] }
								/>
							</BlockPreview.Async>
						);
					},
					( prevProps, nextProps ) =>
						prevProps.item.content === nextProps.item.content
				),
			},
		],
		[]
	);

	const actions = useMemo(
		() =>
			[
				{
					/**
					 * This is a hack to disable multiple selection
					 * in DataViewsPicker until we have a proper prop for it.
					 */
					supportsBulk: false,
				},
			] as ActionButton< Pattern >[],
		[]
	);

	const { data, paginationInfo } = filterSortAndPaginate(
		patterns,
		view,
		fields
	);

	return (
		<DataViewsPicker
			fields={ fields }
			view={ view }
			data={ data }
			actions={ actions }
			getItemId={ ( item: Pattern ) => item.name }
			paginationInfo={ paginationInfo }
			selection={ selectedPattern ? [ selectedPattern ] : [] }
			onChangeView={ setView }
			onChangeSelection={ onSelect }
			defaultLayouts={ {
				pickerGrid: {},
			} }
			config={ { perPageSizes: [ 4, 8 ] } }
		/>
	);
};
