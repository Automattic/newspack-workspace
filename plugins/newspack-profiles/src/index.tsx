import { createRoot, StrictMode } from '@wordpress/element';
import { store as blocksStore } from '@wordpress/blocks';
import { store as editSiteStore } from '@wordpress/edit-site';
import { dispatch } from '@wordpress/data';
import { App } from './App';
import { ViewContextProvider } from './context/ViewContext';
import {
	registerCoreBlocks,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalGetCoreBlocks,
} from '@wordpress/block-library';

import './style.scss';

const rootElement = document.querySelector(
	'#newspack-profiles-settings-root'
);

if ( rootElement ) {
	/**
	 * Reapply block type filters to ensure any customizations
	 * to core blocks are applied in the profile editor.
	 * Inspired by WordPress Core - Pattern Viewer setup.
	 *
	 * @see https://github.com/WordPress/gutenberg/blob/00a60dc2de5fe81554cd0668e4886135e0c9e867/packages/edit-site/src/index.js
	 */
	dispatch( blocksStore ).reapplyBlockTypeFilters();

	const coreBlocks = __experimentalGetCoreBlocks().filter(
		( { name }: { name: string } ) => name !== 'core/freeform'
	);

	registerCoreBlocks( coreBlocks );

	( dispatch( editSiteStore ) as any ).updateSettings(
		window.NewspackProfilesSettingsEditor.data
	);

	createRoot( rootElement ).render(
		<StrictMode>
			<ViewContextProvider>
				<App />
			</ViewContextProvider>
		</StrictMode>
	);
}
