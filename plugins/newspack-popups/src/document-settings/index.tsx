/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { withSelect, withDispatch } from '@wordpress/data';
import { compose } from '@wordpress/compose';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { registerPlugin } from '@wordpress/plugins';
import { ToggleControl } from '@wordpress/components';

/**
 * External dependencies
 */
import type { ComponentType } from 'react';

type PopupsSettingsPanelProps = {
	hasDisabledPopups?: boolean;
	onChange: ( hasDisabledPopups: boolean ) => void;
};

const PopupsSettingsPanel = ( { hasDisabledPopups, onChange }: PopupsSettingsPanelProps ) => (
	<PluginDocumentSettingPanel name="newsletters-popups-settings-panel" title={ __( 'Newspack Campaigns Settings', 'newspack-popups' ) }>
		<ToggleControl
			checked={ hasDisabledPopups }
			onChange={ () => onChange( ! hasDisabledPopups ) }
			label={ __( 'Disable prompts on this post or page', 'newspack-popups' ) }
		/>
	</PluginDocumentSettingPanel>
);

// compose() is loosely typed (its result takes and returns `unknown`), so the
// composed component is asserted as ComponentType at the trailing boundary.
// Passed as separate arguments (rather than the original single-array form) to match
// compose()'s declared variadic signature -- its real implementation `.flat()`s its
// arguments either way, so this is not a behavior change.
const PopupsSettingsPanelWithSelect = compose(
	withSelect( select => {
		// The editor selectors are untyped for string-keyed stores; assert at the store boundary.
		const { getEditedPostAttribute } = select( 'core/editor' ) as {
			getEditedPostAttribute: ( attribute: 'meta' ) => { newspack_popups_has_disabled_popups?: boolean } | undefined;
		};
		const meta = getEditedPostAttribute( 'meta' );
		return { hasDisabledPopups: meta && meta.newspack_popups_has_disabled_popups };
	} ),
	// The mapper returns a specifically-typed action prop; withDispatch's own signature
	// widens it to an unknown-arg index, so cast the mapper at the boundary.
	withDispatch( ( ( dispatch: ( store: string ) => Record< string, ( ...args: unknown[] ) => unknown > ) => {
		const { editPost } = dispatch( 'core/editor' ) as {
			editPost: ( edits: { meta: { newspack_popups_has_disabled_popups: boolean } } ) => void;
		};
		return {
			onChange: ( hasDisabledPopups: boolean ) => {
				editPost( { meta: { newspack_popups_has_disabled_popups: hasDisabledPopups } } );
			},
		};
	} ) as Parameters< typeof withDispatch >[ 0 ] )
)( PopupsSettingsPanel ) as ComponentType;

registerPlugin( 'newspack-popups-post-status-info', {
	render: PopupsSettingsPanelWithSelect,
	// An explicit falsy icon overrides registerPlugin's default plugins icon via object
	// spread; `undefined` (rather than the original `false`) satisfies `icon?: IconType`
	// while producing the same falsy override.
	icon: undefined,
} );
