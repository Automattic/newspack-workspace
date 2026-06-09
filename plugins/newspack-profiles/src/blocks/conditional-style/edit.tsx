import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import { ColorValueStylesControl } from './components/ColorValueStylesControl';

import './editor.scss';
import { ColorStyle } from './components/types';
import {
	DEFAULT_BACKGROUND_COLOR,
	DEFAULT_TEXT_COLOR,
} from './components/utils';

const REMOTE_DATA_CONTEXT_KEY = 'remote-data-blocks/remoteData';
const BLOCK_BINDING_SOURCE = 'remote-data/binding';

type Attributes = {
	fieldName: string;
	styles: Record< string, ColorStyle >;
	fallbackStyle: ColorStyle;
	metadata?: {
		bindings: Record<
			string,
			{
				source: string;
				args: {
					field: string;
					block: string;
				};
			}
		>;
		name?: string;
	};
};

type EditProps = {
	attributes: Attributes;
	context: {
		[ REMOTE_DATA_CONTEXT_KEY ]?: {
			blockName: string;
		};
	};
	setAttributes: ( attributes: Partial< Attributes > ) => void;
};

export const Edit = ( { attributes, context, setAttributes }: EditProps ) => {
	const { fieldName, styles, fallbackStyle } = attributes;
	const normalizedFallbackStyle = {
		textColor: fallbackStyle?.textColor || DEFAULT_TEXT_COLOR,
		backgroundColor:
			fallbackStyle?.backgroundColor || DEFAULT_BACKGROUND_COLOR,
	};

	const blockProps = useBlockProps( {
		style: {
			overflow: 'hidden',
			'--np-conditional-style-text-color':
				styles?.[ fieldName ]?.textColor ||
				normalizedFallbackStyle.textColor,
			backgroundColor:
				styles?.[ fieldName ]?.backgroundColor ||
				normalizedFallbackStyle.backgroundColor,
		},
	} );

	const remoteData = context[ REMOTE_DATA_CONTEXT_KEY ];

	const availableBindings = useMemo( () => {
		return (
			window.REMOTE_DATA_BLOCKS?.config?.[ remoteData?.blockName ?? '' ]
				?.availableBindings || {}
		);
	}, [ remoteData ] );

	const fieldOptions = useMemo( () => {
		const options = Object.entries( availableBindings )
			.map( ( [ key, binding ] ) => ( {
				label: binding.name,
				value: key,
			} ) )
			.sort( ( a, b ) => a.label.localeCompare( b.label ) );

		options.unshift( {
			label: __( 'Select a field', 'newspack-profiles' ),
			value: '',
		} );

		return options;
	}, [ availableBindings ] );

	const existingBindings = attributes.metadata?.bindings ?? {};

	function removeBinding() {
		const { fieldName: _remove, ...newBindings } = existingBindings;
		setAttributes( {
			metadata: {
				...attributes.metadata,
				bindings: newBindings,
				name: undefined,
			},
		} );
	}

	function updateBinding( args: any ) {
		setAttributes( {
			metadata: {
				...attributes.metadata,
				bindings: {
					...attributes.metadata?.bindings,
					fieldName: {
						source: BLOCK_BINDING_SOURCE,
						args: {
							...args,
							block: remoteData?.blockName,
						},
					},
				},
				name: availableBindings[ args.field ]?.name,
			},
		} );
	}

	function handleFieldNameBindingChange( field: string ): void {
		if ( ! field ) {
			removeBinding();

			return;
		}

		const args = attributes.metadata?.bindings?.fieldName?.args ?? {};
		updateBinding( { ...args, field } );
	}

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Conditional Style Settings',
						'newspack-profiles'
					) }
					initialOpen={ true }
				>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Select Data Field', 'newspack-profiles' ) }
						help={ __(
							'Choose the data field to apply conditional styles based on its value.',
							'newspack-profiles'
						) }
						options={ fieldOptions }
						onChange={ handleFieldNameBindingChange }
						value={
							attributes.metadata?.bindings?.fieldName?.args
								?.field ?? ''
						}
					/>
					<ColorValueStylesControl
						styles={ styles || {} }
						fallbackStyle={ normalizedFallbackStyle }
						onChange={ ( nextStyles ) =>
							setAttributes( { styles: nextStyles } )
						}
						onChangeFallbackStyle={ ( nextFallbackStyle ) =>
							setAttributes( {
								fallbackStyle: nextFallbackStyle,
							} )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<InnerBlocks />
			</div>
		</>
	);
};
