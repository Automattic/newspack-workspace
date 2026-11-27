/**
 * WordPress dependencies.
 */
import { FormToggle } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

const PostDateSettingsPanel = () => {
	const { postType, meta } = useSelect( ( select ): { postType: string; meta: Record< string, unknown > } => {
		// The editor selectors are untyped for string-keyed stores; assert at the store boundary.
		const editor = select( 'core/editor' ) as {
			getCurrentPostType: () => string;
			getEditedPostAttribute: ( attribute: string ) => Record< string, unknown > | undefined;
		};
		return {
			postType: editor.getCurrentPostType(),
			meta: editor.getEditedPostAttribute( 'meta' ) || {},
		};
	}, [] );

	const { editPost } = useDispatch( 'core/editor' );

	const config = window.newspackPostDate;
	if ( ! config ) {
		return null;
	}

	const { mode, postTypes } = config;

	if ( ! postTypes.includes( postType ) ) {
		return null;
	}

	const updateMeta = ( key: string, value: boolean ) => {
		editPost( { meta: { [ key ]: value } } );
	};

	if ( mode === 'hide' ) {
		return (
			<PluginPostStatusInfo>
				<label htmlFor="newspack_hide_updated_date">{ __( 'Hide last updated date', 'newspack-plugin' ) }</label>
				<FormToggle
					checked={ !! meta.newspack_hide_updated_date }
					onChange={ () => updateMeta( 'newspack_hide_updated_date', ! meta.newspack_hide_updated_date ) }
					id="newspack_hide_updated_date"
				/>
			</PluginPostStatusInfo>
		);
	}

	return (
		<PluginPostStatusInfo>
			<label htmlFor="newspack_show_updated_date">{ __( 'Show last updated date', 'newspack-plugin' ) }</label>
			<FormToggle
				checked={ !! meta.newspack_show_updated_date }
				onChange={ () => updateMeta( 'newspack_show_updated_date', ! meta.newspack_show_updated_date ) }
				id="newspack_show_updated_date"
			/>
		</PluginPostStatusInfo>
	);
};

registerPlugin( 'newspack-post-date', {
	render: PostDateSettingsPanel,
	// An explicit falsy icon overrides registerPlugin's default plugins icon via object spread.
	icon: undefined,
} );
