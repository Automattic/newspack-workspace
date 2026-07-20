import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockEditingMode } from '@wordpress/block-editor';
import { TextControl } from '@wordpress/components';
import type { ComponentType } from 'react';

const ALLOWED_BLOCKS = [ 'core/paragraph', 'core/heading' ];

/** The subset of block settings touched by the `blocks.registerBlockType` filter. */
type BlockSettingsWithAttributes = {
	attributes?: Record< string, unknown >;
};

/**
 * The subset of the wrapped BlockEdit's props consumed by the `editor.BlockEdit`
 * filter. The real props object carries the full block-edit surface and is
 * passed through unchanged. `indesignTag` is always present on allowed blocks
 * (the registerBlockType filter above installs a `''` default).
 */
type IndesignBlockEditProps = {
	name: string;
	attributes: { indesignTag: string };
	setAttributes: ( attributes: { indesignTag: string } ) => void;
};

const addAttribute = ( settings: BlockSettingsWithAttributes, name: string ) => {
	if ( ! ALLOWED_BLOCKS.includes( name ) ) {
		return settings;
	}

	settings.attributes = {
		...settings.attributes,
		indesignTag: {
			type: 'string',
			default: '',
		},
	};

	return settings;
};

const TagNameControl = ( {
	blockName,
	indesignTag,
	setAttributes,
}: {
	blockName: string;
	indesignTag: string;
	setAttributes: IndesignBlockEditProps[ 'setAttributes' ];
} ) => {
	const blockEditingMode = useBlockEditingMode();
	if ( blockEditingMode !== 'default' ) {
		return null;
	}

	// Only paragraphs and heading can have custom tag names.
	if ( ! ALLOWED_BLOCKS.includes( blockName ) ) {
		return null;
	}

	return (
		<InspectorControls group="advanced">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'InDesign Exporter Tag Name', 'newspack-plugin' ) }
				help={ __( 'Define a custom tag name to be used in the Tagged Text export.', 'newspack-plugin' ) }
				value={ indesignTag }
				onChange={ value => setAttributes( { indesignTag: value } ) }
			/>
		</InspectorControls>
	);
};

const addTagNameControl = ( BlockEdit: ComponentType< IndesignBlockEditProps > ) => {
	return ( props: IndesignBlockEditProps ) => {
		return (
			<>
				<BlockEdit { ...props } />
				<TagNameControl blockName={ props.name } indesignTag={ props.attributes.indesignTag } setAttributes={ props.setAttributes } />
			</>
		);
	};
};

addFilter( 'blocks.registerBlockType', 'newspack-plugin/indesign-export', addAttribute );
addFilter( 'editor.BlockEdit', 'newspack-plugin/indesign-export', addTagNameControl );
