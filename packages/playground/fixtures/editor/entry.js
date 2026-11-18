import { createRoot, useState } from '@wordpress/element';
import { BlockCanvas, BlockEditorProvider, BlockInspector } from '@wordpress/block-editor';
import { registerCoreBlocks } from '@wordpress/block-library';
import { parse } from '@wordpress/blocks';
import { generateGlobalStyles } from '@wordpress/global-styles-engine';
import theme from '@themes/newspack-block-theme/theme.json';
import { connectScreen } from 'newspack-playground/bridge';
import { themeConfig } from 'newspack-playground/theme';

import '@wordpress/components/build-style/style.css';
import '@wordpress/block-editor/build-style/style.css';
import '@wordpress/block-library/build-style/style.css';
import '@wordpress/block-library/build-style/editor.css';

registerCoreBlocks();

const [ styles, features ] = generateGlobalStyles( themeConfig( theme ) );

function Editor() {
	const [ blocks, setBlocks ] = useState( () => parse( '<!-- wp:paragraph --><p>Rendered by the block editor.</p><!-- /wp:paragraph -->' ) );
	return (
		<BlockEditorProvider value={ blocks } onInput={ setBlocks } onChange={ setBlocks } settings={ { __experimentalFeatures: features, styles } }>
			<BlockCanvas height="320px" styles={ styles } />
			<BlockInspector />
		</BlockEditorProvider>
	);
}

createRoot( document.getElementById( 'root' ) ).render( <Editor /> );

connectScreen( { onSetState: () => {} } ).report( { ready: true } );
