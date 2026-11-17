# Newspack Playground

A harness for building design prototypes against the workspace's own components. The prototypes live outside this repo; the playground only supplies the build and the frame bridge.

## Config

Each prototype folder has a `playground.config.js` (CommonJS). Paths are relative to the config file and must exist.

```js
module.exports = {
	title: 'My prototype',
	entry: './src/index.js',
	aliases: { '@shared': './shared' },
};
```

`@np` (`packages/components/src`), `@plugin` (`plugins/newspack-plugin/src`), `@themes` (`themes/`), `newspack-playground/bridge` and `newspack-playground/theme` are always available. A prototype's `aliases` are merged over them.

## Build

```sh
node packages/playground/bin/build.js <prototype-dir> --out <dir>
```

This writes `index.html`, `screen.js`, `screen.css` and any fonts to the output directory. Serve it over http to view it.

## Bridge

`newspack-playground/bridge` keeps a parent page and an iframe in sync. It is plain ESM with no dependencies.

-   `connectScreen( { win, onSetState } )` runs inside the iframe. `report( state )` posts the state to the parent and mirrors it into the URL query.
-   `connectFrame( iframe, { onState, onHeight } )` runs in the parent. `setState( state )` pushes state into the iframe.
-   `readState( search )` and `writeState( state )` convert between a query string and a state object.

Messages are `np-playground:state`, `np-playground:height` and `np-playground:set-state`. Both sides only accept messages from the expected window on the same origin.

## Theme

`newspack-playground/theme` exports `themeConfig( theme, ...variations )`. It resolves a theme.json the way WordPress does: core's theme.json at the bottom, its presets under the `default` origin, then the theme and any style variations, their presets under `theme`. The block editor and `GlobalStylesUI` both read that shape.

```js
import { generateGlobalStyles } from '@wordpress/global-styles-engine';
import theme from '@themes/newspack-block-theme/theme.json';
import nocturne from '@themes/newspack-block-theme/styles/10-nocturne.json';
import { themeConfig } from 'newspack-playground/theme';

const [ styles, features ] = generateGlobalStyles( themeConfig( theme, nocturne ) );
// <BlockEditorProvider settings={ { __experimentalFeatures: features, styles } }>
```

Core's theme.json is `src/wp-core-theme.json`, copied from WordPress 7.1.4. Without it the editor sees none of core's defaults, and panels such as Color stay hidden. Update it with the WordPress version the sites run.

## Pinned versions

The playground bundles its own pinned copies of `@wordpress/components`, `@wordpress/dataviews` and the editor packages (`block-editor`, `block-library`, `blocks`, `data`, `core-data`, `global-styles-engine`, `global-styles-ui`), independent of the ranges elsewhere in the workspace, so prototypes render with current versions. Every import of one of these resolves to the pinned copy, so the editor stores exist once. Bump them deliberately, together: each editor release names the `@wordpress/components` and `@wordpress/ui` versions it was built against.

`fixtures/editor` builds a block editor with core blocks, the block theme's styles and the block inspector. Build it after a bump.

The build stubs `@wordpress/route`, which needs React 19.

The CLI runs only from within a newspack-workspace checkout, because it uses the workspace's scripts and components.
