/**
 * This helps to integrate remote data binding controls into supported core blocks
 * within the Newspack Profiles plugin.
 * As of now, it specifically adds support for the 'core/social-link' block,
 * allowing users to bind its URL to remote data sources.
 *
 * @see https://github.com/Automattic/remote-data-blocks/blob/0711ba0a7314603f29e91bbc99dd4714b61c7447/src/block-editor/filters/index.ts#L17
 */
import { useSelect } from '@wordpress/data';
import {
	store as blockEditorStore,
	InspectorControls,
} from '@wordpress/block-editor';
import {
	registerBlockBindingsSource,
	unregisterBlockBindingsSource,
} from '@wordpress/blocks';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { PanelBody, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { escapeHTML } from '@wordpress/escape-html';

import { postAuthor } from '@wordpress/icons';

import colors from '../../../../packages/colors/colors.module.scss';

import './variations';

/**
 * Supported core blocks for remote data binding.
 * Note: Other blocks may also support remote data binding, but these are the ones
 * that make sense in the context of Newspack Profiles.
 * Others are handled via Remote Data Blocks plugin.
 */
const SUPPORTED_CORE_BLOCKS = [ 'core/social-link' ];
const REMOTE_DATA_CONTEXT_KEY = 'remote-data-blocks/remoteData';
const PATTERN_OVERRIDES_BINDING_SOURCE = 'core/pattern-overrides';
const PATTERN_OVERRIDES_CONTEXT_KEY = 'pattern/overrides';
const BLOCK_BINDING_SOURCE = 'remote-data/binding';
const NEWSPACK_PROFILES_BLOCK_ICON = 'newspack-profiles-block-icon';

/**
 * Add remote data context to supported core blocks.
 *
 * @param settings Block settings.
 * @param name     Block name.
 *
 * @return Modified block settings.
 */
function addUsesContext( settings: any, name: string ) {
	if ( ! SUPPORTED_CORE_BLOCKS.includes( name ) ) {
		return settings;
	}

	const { usesContext = [] } = settings;

	if ( ! usesContext?.includes( REMOTE_DATA_CONTEXT_KEY ) ) {
		return {
			...settings,
			usesContext: [ ...usesContext, REMOTE_DATA_CONTEXT_KEY ],
		};
	}

	return settings;
}

addFilter(
	'blocks.registerBlockType',
	'remote-data-blocks/addUsesContext',
	addUsesContext,
	10
);

/**
 * HOC to wrap block edit with remote data binding controls.
 */
const withBlockBinding = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props: any ) => {
		const { attributes, context, name, setAttributes } = props;
		const remoteData = context[ REMOTE_DATA_CONTEXT_KEY ];
		const availableBindings =
			window.REMOTE_DATA_BLOCKS?.config?.[ remoteData?.blockName ?? '' ]
				?.availableBindings || {};
		const hasAvailableBindings = Boolean(
			Object.keys( availableBindings ).length
		);
		const { hasMultiSelection } = useSelect( blockEditorStore );

		// If the block does not have a remote data context, render it as usual.
		if ( ! remoteData || ! hasAvailableBindings ) {
			return <BlockEdit { ...props } />;
		}

		// Synced pattern overrides are provided via context and the value can be:
		//
		// - undefined (block is not in a synced pattern)
		// - an empty array (block is in a synced pattern, but no overrides are applied)
		// - an object defining the applied overrides
		//
		// This gives no indication of whether overrides are enabled or not. For
		// that, we need to check the block's metadata bindings for the pattern
		// overrides binding source.
		//
		// This seems likely to change, so the code here may need maintenance. For
		// our purposes, though, we just want to know whether the block is in a
		// synced pattern and whether overrides are enabled. Trying to update
		// a synced block without overrides enabled is useless and can cause issues.

		const patternOverrides = context[ PATTERN_OVERRIDES_CONTEXT_KEY ] as
			| string[]
			| undefined;
		const isInSyncedPattern = Boolean( patternOverrides );
		const hasEnabledOverrides = Object.values(
			attributes.metadata?.bindings ?? {}
		).some(
			( binding: any ) =>
				binding.source === PATTERN_OVERRIDES_BINDING_SOURCE
		);

		// If multiple blocks are being selected, render it as usual.
		if ( hasMultiSelection() ) {
			return <BlockEdit { ...props } attributes={ attributes } />;
		}

		// If the block is not writable, render it as usual.
		if ( isInSyncedPattern && ! hasEnabledOverrides ) {
			return <BlockEdit { ...props } attributes={ attributes } />;
		}

		return (
			<BoundBlockEdit
				attributes={ attributes }
				availableBindings={ availableBindings }
				blockName={ name }
				remoteDataName={ remoteData?.blockName ?? '' }
				remoteDataTitle={ 'Newspack' }
				setAttributes={ setAttributes }
			>
				<BlockEdit { ...props } attributes={ attributes } />
			</BoundBlockEdit>
		);
	};
}, 'withBlockBinding' );

/**
 * Component wrapping a block edit with binding controls.
 *
 * @param props Block props.
 */
function BoundBlockEdit( props: any ) {
	const {
		attributes,
		availableBindings,
		blockName,
		remoteDataName,
		remoteDataTitle,
		setAttributes,
	} = props;
	const existingBindings = attributes.metadata?.bindings ?? {};

	function removeBinding( target: string ) {
		const { [ target ]: _remove, ...newBindings } = existingBindings;
		setAttributes( {
			metadata: {
				...attributes.metadata,
				bindings: newBindings,
				name: undefined,
			},
		} );
	}

	function updateBinding( target: string, args: any ) {
		setAttributes( {
			metadata: {
				...attributes.metadata,
				bindings: {
					...attributes.metadata?.bindings,
					[ target ]: {
						source: BLOCK_BINDING_SOURCE,
						args: {
							...args,
							block: remoteDataName, // Remote Data Block name
						},
					},
				},
				name: availableBindings[ args.field ]?.name, // Changes block name in list view.
			},
		} );
	}

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Newspack Remote Data', 'newspack-profiles' ) }
				>
					<p className="rdb-block-helper-text">
						{ sprintf(
							/* translators: %s is the remote data source title. */
							__( 'Connected to %s', 'newspack-profiles' ),
							remoteDataTitle
						) }
					</p>
					<BlockBindingControls
						attributes={ attributes }
						availableBindings={ availableBindings }
						blockName={ blockName }
						removeBinding={ removeBinding }
						updateBinding={ updateBinding }
					/>
				</PanelBody>
			</InspectorControls>
			{ props.children }
		</>
	);
}

/**
 * Render binding controls for supported blocks.
 *
 * @param props Block props.
 */
function BlockBindingControls( props: any ) {
	const {
		attributes,
		availableBindings,
		blockName,
		removeBinding,
		updateBinding,
	} = props;

	function updateFieldBinding( target: string, field: string ): void {
		if ( ! field ) {
			removeBinding( target );

			return;
		}

		const args = attributes.metadata?.bindings?.[ target ]?.args ?? {};
		updateBinding( target, { ...args, field } );
	}

	switch ( blockName ) {
		case 'core/social-link':
			return (
				<>
					<BlockBindingFieldControl
						availableBindings={ availableBindings }
						fieldTypes={ [ 'button_url', 'email_address' ] }
						label="Social Media URL"
						target="url"
						updateFieldBinding={ updateFieldBinding }
						value={
							attributes.metadata?.bindings?.url?.args?.field ??
							''
						}
					/>
				</>
			);
	}

	return null;
}

/**
 * Component rendering a field binding control.
 *
 * @param props Component props.
 */
export function BlockBindingFieldControl( props: any ) {
	const {
		availableBindings,
		fieldTypes,
		label,
		target,
		updateFieldBinding,
		value,
	} = props;

	const options = Object.entries( availableBindings )
		// eslint-disable-next-line @typescript-eslint/no-unused-vars
		.filter( ( [ _, mapping ]: [ string, any ] ) =>
			fieldTypes.includes( mapping.type )
		)
		.map( ( [ key, mapping ]: [ string, any ] ) => {
			return { label: mapping.name, value: key };
		} )
		.sort( ( a, b ) => a.label.localeCompare( b.label ) );

	return (
		<SelectControl
			label={ label }
			name={ target }
			options={ [ { label: 'Select a field', value: '' }, ...options ] }
			onChange={ ( field: string ) =>
				updateFieldBinding( target, field )
			}
			value={ value }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);
}

/**
 * HOC to wrap supported core blocks with remote data binding controls.
 *
 * @param settings Block settings.
 *
 * @return Modified block settings.
 */
function withBlockBindingShim( settings: any ) {
	if ( ! SUPPORTED_CORE_BLOCKS.includes( settings?.name ) ) {
		return settings;
	}

	return {
		...settings,
		edit: withBlockBinding( settings.edit ?? ( () => null ) ),
	};
}

addFilter(
	'blocks.registerBlockType',
	'remote-data-blocks/withBlockBinding',
	withBlockBindingShim,
	5 // Ensure this runs before core filters
);

/**
 * Unregister existing remote data bindings source and
 * registers a mock remote data bindings source to provide sample data
 * for block previews while designing templates.
 *
 * @see https://github.com/Automattic/remote-data-blocks/blob/trunk/src/block-editor/binding-sources/remote-data-binding.ts
 */
unregisterBlockBindingsSource( 'remote-data/binding' );
registerBlockBindingsSource( {
	name: 'remote-data/binding',
	label: __( 'Remote Data', 'newspack-profiles' ),
	getValues( {
		bindings,
		context,
		select,
	}: {
		bindings: Record< string, any >;
		context: Record< string, any >;
		select: ( storeName: string ) => any;
	} ) {
		const remoteData = context[ REMOTE_DATA_CONTEXT_KEY ];
		const previewIndex = select(
			'remote-data-blocks/store'
		).getPreviewIndex( remoteData?.resultId );

		const hasResults = Boolean( remoteData?.results?.length );

		const entries = Object.entries( bindings )
			.filter(
				// eslint-disable-next-line @typescript-eslint/no-unused-vars
				( [ _targetAttribute, binding ] ) =>
					binding?.args?.isPreview || hasResults
			)
			.map( ( [ targetAttribute, binding ] ) => {
				const {
					field,
					label: labelText,
					previewIndex: bindingPreviewIndex,
					previewValue,
				} = binding.args;

				const index = bindingPreviewIndex ?? previewIndex ?? 0;

				const labelHTML = labelText
					? `<span class="rdb-block-label" style="pointer-events:none;">${ escapeHTML(
							labelText
					  ) }</span> `
					: '';

				const fallbackHTML = `<span style="opacity:80%;pointer-events:none;">${ escapeHTML(
					String( labelText ? labelText : field )
				) }</span>`;

				const resultValue =
					remoteData?.results?.[ index ]?.result?.[ field ]?.value;

				const value = previewValue || resultValue || fallbackHTML;

				return [ targetAttribute, `${ labelHTML }${ value }` ];
			} );

		return Object.fromEntries( entries );
	},
} );

const withIconShim = ( settings: any ) => {
	if ( settings?.icon !== NEWSPACK_PROFILES_BLOCK_ICON ) {
		return settings;
	}

	return {
		...settings,
		icon: (
			<span
				style={ {
					backgroundColor: colors[ 'primary-400' ],
					color: colors[ 'neutral-000' ],
					borderRadius: '100%',
					display: 'inline-flex',
					alignItems: 'center',
					justifyContent: 'center',
					padding: '2px',
				} }
			>
				{ postAuthor }
			</span>
		),
	};
};

addFilter(
	'blocks.registerBlockType',
	'newspack-profiles/withIconShim',
	withIconShim,
	5 // Ensure this runs before core filters
);
