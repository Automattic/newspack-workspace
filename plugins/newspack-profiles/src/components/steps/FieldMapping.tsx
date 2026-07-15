import { useSelect, useDispatch } from '@wordpress/data';
/* eslint-disable @wordpress/no-unsafe-wp-apis */
import {
	Button,
	Icon,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
	Tooltip,
	__experimentalVStack as VStack,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { __ } from '@wordpress/i18n';
import { chevronDown, chevronUp, menu } from '@wordpress/icons';
import { store as onboardingStore } from '../../stores/onboarding';
import { store as blockStore } from '@wordpress/blocks';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { DragDropProvider, type DragDropEvents } from '@dnd-kit/react';
import { useSortable } from '@dnd-kit/react/sortable';
import { move } from '@dnd-kit/helpers';
import classNames from 'classnames';
import type { TypeMapping } from '../../types/profile-collection';
import { useEditContext } from '../../context/EditContext';
import './FieldMapping.scss';

const FIELD_TYPES_OPTIONS = [
	{ label: __( 'Text', 'newspack-profiles' ), value: 'string' },
	{ label: __( 'Image URL', 'newspack-profiles' ), value: 'image_url' },
	{ label: __( 'Social Link', 'newspack-profiles' ), value: 'social_link' },
	{ label: __( 'Link', 'newspack-profiles' ), value: 'button_url' },
];

type SocialPlatformOption = {
	label: string;
	value: string;
};

type SocialVariation = {
	name: string;
	title?: string;
};

/**
 * Detects the social platform based on the field name.
 * This function checks if the field name contains any of the known social platform identifiers and returns the corresponding platform value.
 *
 * @param fieldName
 * @param options
 * @return The detected social platform value or an empty string if no match is found.
 */
const detectSocialPlatform = (
	fieldName: string,
	options: SocialPlatformOption[]
): string => {
	const lower = fieldName.toLowerCase();
	const match = options.find(
		( option ) => option.value && lower.includes( option.value )
	);
	return match?.value ?? '';
};

/**
 * Component for mapping data source fields to profile fields.
 *
 * @return JSX.Element The FieldMapping component.
 */
export const FieldMapping = () => {
	const isEdit = useEditContext();

	const { mappings, dataSource } = useSelect( ( select ) => {
		const { getDataSource, getMappings } = select( onboardingStore );

		return {
			dataSource: getDataSource(),
			mappings: getMappings(),
		};
	}, [] );

	const socialPlatformOptions = useSelect( ( select ) => {
		const variations: SocialVariation[] =
			select( blockStore )?.getBlockVariations?.( 'core/social-link' ) ||
			[];

		return [
			{ label: __( 'Other', 'newspack-profiles' ), value: '' },
			...variations
				.filter( ( variation ) => variation?.name )
				.map( ( variation ) => ( {
					label: String( variation.title || variation.name ),
					value: variation.name,
				} ) )
				.sort( ( a, b ) => a.label.localeCompare( b.label ) ),
		];
	}, [] );

	const { setMappings } = useDispatch( onboardingStore );

	const orderedFields = [ ...( dataSource.fields || [] ) ].sort(
		( firstField, secondField ) => {
			const firstOrder = mappings[ firstField ]?.order ?? 0;
			const secondOrder = mappings[ secondField ]?.order ?? 0;

			return firstOrder - secondOrder;
		}
	);

	const [ sampleData, setSampleData ] = useState< Record< string, any > >(
		{}
	);

	const rebuildMappings = ( reorderedFields: string[] ) => {
		return reorderedFields.reduce(
			(
				acc: Record< string, TypeMapping >,
				fieldName: string,
				index: number
			) => {
				const newMapping = mappings[ fieldName ] || {
					type: 'string',
					visible: true,
				};

				acc[ fieldName ] = {
					...newMapping,
					order: index,
				};

				return acc;
			},
			{} as Record< string, TypeMapping >
		);
	};

	const handleDragEnd: DragDropEvents[ 'dragend' ] = ( event ) => {
		if ( event.canceled || orderedFields.length < 2 ) {
			return;
		}

		const reorderedFields = move( orderedFields, event );
		setMappings( rebuildMappings( reorderedFields ) );
	};

	const moveField = ( index: number, direction: 'up' | 'down' ) => {
		const newFields = [ ...orderedFields ];
		const targetIndex = direction === 'up' ? index - 1 : index + 1;

		const sourceField = newFields[ index ] as string;
		const targetField = newFields[ targetIndex ] as string;

		newFields[ index ] = targetField;
		newFields[ targetIndex ] = sourceField;

		setMappings( rebuildMappings( newFields ) );
	};

	useEffect( () => {
		let ignored = false;

		const fetchSampleData = async () => {
			if ( ! dataSource.type ) {
				return;
			}

			try {
				const data = await apiFetch( {
					path: '/newspack-profiles/v1/data-sources/sample-data',
					method: 'POST',
					data: {
						dataSource: {
							...dataSource,
							fields: undefined,
						},
					},
				} );

				if ( ! ignored ) {
					setSampleData( data as Record< string, any > );
				}
			} catch ( err ) {
				// eslint-disable-next-line no-console
				console.error( 'Error fetching sample data:', err );
				if ( ! ignored ) {
					setSampleData( {} );
				}
			}
		};

		fetchSampleData();

		return () => {
			ignored = true;
		};
	}, [ dataSource ] );

	return (
		<div className="newspack-profiles__field-mapping">
			<h3>
				{ __(
					'Define Field Types for Your Data Fields',
					'newspack-profiles'
				) }
			</h3>
			<p>
				{ __(
					'Tell us what type of content each field contains. Use "Text" for names and descriptions, "Image URL" for image links, "Social Link" for social media links, or "Link" for other URLs. Default is "Text".',
					'newspack-profiles'
				) }
			</p>
			{ isEdit && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Changes to sorting and visibility will only apply to new blocks. Existing blocks will not be updated.',
						'newspack-profiles'
					) }
				</Notice>
			) }
			<div className="newspack-profiles__map-table">
				<div className="newspack-profiles__map-table__head">
					<span></span>
					<span></span>
					<span className="newspack-profiles__map-table__head-label">
						{ __( 'Data Field', 'newspack-profiles' ) }
					</span>
					<span className="newspack-profiles__map-table__head-label">
						{ __( 'Display Label', 'newspack-profiles' ) }
						<span className="newspack-profiles__map-table__head-sublabel">
							{ __(
								'The display name for this field when used in page templates.',
								'newspack-profiles'
							) }
						</span>
					</span>
					<span className="newspack-profiles__map-table__head-label">
						{ __( 'Field Type', 'newspack-profiles' ) }
					</span>
					<span className="newspack-profiles__map-table__head-label">
						{ __( 'Visibility', 'newspack-profiles' ) }
					</span>
				</div>
				<DragDropProvider onDragEnd={ handleDragEnd }>
					<ul className="newspack-profiles__map-table__rows">
						{ orderedFields.map( ( dataSourceField, index ) => (
							<Row
								key={ dataSourceField }
								index={ index }
								isFirst={ index === 0 }
								isLast={ index === orderedFields.length - 1 }
								dataSourceField={ dataSourceField }
								sampleData={ sampleData[ dataSourceField ] }
								mapping={ mappings[ dataSourceField ] }
								socialPlatformOptions={ socialPlatformOptions }
								onMove={ moveField }
								onChange={ ( mapping ) =>
									setMappings( {
										...mappings,
										[ dataSourceField ]: mapping,
									} )
								}
							/>
						) ) }
					</ul>
				</DragDropProvider>
			</div>
		</div>
	);
};

type RowProps = {
	index: number;
	isFirst: boolean;
	isLast: boolean;
	dataSourceField: string;
	sampleData: any;
	mapping: TypeMapping | undefined;
	socialPlatformOptions: SocialPlatformOption[];
	onMove: ( index: number, direction: 'up' | 'down' ) => void;
	onChange: ( mapping: TypeMapping ) => void;
};

const handleMove = (
	index: number,
	direction: 'up' | 'down',
	onMove: ( index: number, direction: 'up' | 'down' ) => void,
	setIsMoving: ( moving: boolean ) => void
) => {
	onMove( index, direction );
	setIsMoving( true );
	setTimeout( () => setIsMoving( false ), 300 );
};

const Row = ( {
	index,
	isFirst,
	isLast,
	dataSourceField,
	sampleData,
	mapping,
	socialPlatformOptions,
	onMove,
	onChange,
}: RowProps ) => {
	const [ isMoving, setIsMoving ] = useState( false );
	const { ref, handleRef, isDragging, isDropping } = useSortable( {
		id: dataSourceField,
		index,
	} );

	return (
		<li
			ref={ ref }
			className={ classNames( 'newspack-profiles__map-table__row', {
				'is-dragging': isDragging || isMoving,
				'is-hidden': mapping?.visible === false,
			} ) }
		>
			<Tooltip
				text={
					! isDragging && ! isDropping
						? __( 'Drag to reorder', 'newspack-profiles' )
						: ''
				}
			>
				<span
					ref={ handleRef }
					className="newspack-profiles__map-table__drag-handle"
				>
					<Icon icon={ menu } />
				</span>
			</Tooltip>
			<VStack spacing={ 0 }>
				<Button
					icon={ chevronUp }
					size="small"
					variant="tertiary"
					disabled={ isFirst }
					aria-label={ __( 'Move up', 'newspack-profiles' ) }
					onClick={ () =>
						handleMove( index, 'up', onMove, setIsMoving )
					}
				/>
				<Button
					icon={ chevronDown }
					size="small"
					variant="tertiary"
					disabled={ isLast }
					aria-label={ __( 'Move down', 'newspack-profiles' ) }
					onClick={ () =>
						handleMove( index, 'down', onMove, setIsMoving )
					}
				/>
			</VStack>
			<VStack spacing={ 0 }>
				<span className="newspack-profiles__map-table__field-name">
					{ dataSourceField }
				</span>
				{ sampleData && (
					<span className="newspack-profiles__map-table__field-sample">
						<strong>
							{ __( 'Sample Data:', 'newspack-profiles' ) }
						</strong>{ ' ' }
						{ sampleData }
					</span>
				) }
			</VStack>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				value={ mapping?.label || '' }
				placeholder={ __(
					'Override the default label (optional)',
					'newspack-profiles'
				) }
				onChange={ ( value ) =>
					onChange( {
						...mapping,
						label: value,
					} )
				}
				onBlur={ ( event ) => {
					onChange( {
						...mapping,
						label: event.target.value.trim() || undefined,
					} );
				} }
			/>
			<div>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					value={ mapping?.type || 'string' }
					options={ FIELD_TYPES_OPTIONS }
					onChange={ ( value ) => {
						const update: TypeMapping = { ...mapping, type: value };
						if ( value === 'social_link' ) {
							update.social_platform = detectSocialPlatform(
								dataSourceField,
								socialPlatformOptions
							);
						}
						onChange( update );
					} }
				/>
				{ mapping?.type === 'social_link' && (
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						className="newspack-profiles__map-table__social-select"
						value={ mapping?.social_platform || '' }
						options={ socialPlatformOptions }
						onChange={ ( value ) =>
							onChange( {
								...mapping,
								social_platform: value,
							} )
						}
					/>
				) }
			</div>
			<ToggleControl
				__nextHasNoMarginBottom
				className="newspack-profiles__map-table__toggle"
				label={ '' }
				checked={ mapping?.visible === false ? false : true }
				onChange={ () =>
					onChange( {
						...mapping,
						visible: mapping?.visible === false ? true : false,
					} )
				}
			/>
		</li>
	);
};
