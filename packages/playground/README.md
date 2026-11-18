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

`replace` swaps one file inside a pinned package for the prototype's own version, for a change the package has no prop or hook for:

```js
replace: {
	'@wordpress/global-styles-ui/build-module/color-palette-panel.mjs': './shared/color-palette-panel.js',
},
```

The key is the package name followed by the file's path inside the package, extension included. It points at the playground's pinned copy, so the package's own imports of that file get the replacement too, not only the prototype's. The value is relative to the config file. The build fails if the package isn't pinned, the file isn't in it, or the replacement doesn't exist.

The replacement must keep the original's exports. To reach the rest of the package, it imports the files under `build-module` by their full path, such as `@wordpress/global-styles-ui/build-module/hooks.mjs`. That works for every pinned package even though its `exports` map hides those paths, and it loads the same module instance the package uses. A replacement must not import the file it replaces.

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

`newspack-playground/theme` exports `themeConfig( theme, ...variations )`. It resolves a theme.json the way WordPress does: core's theme.json at the bottom, its presets under the `default` origin, then the theme and any style variations, their presets under `theme`, each file migrated to schema v3 and `appearanceTools` expanded. The block editor and `GlobalStylesUI` both read that shape.

It covers theme.json only. It doesn't load the theme's `@font-face` rules or its editor stylesheet, and it doesn't generate core's spacing sizes from `spacingScale`, which WordPress does on the server.

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

The playground bundles its own pinned copies of `@wordpress/components`, `@wordpress/dataviews` and the editor packages (`api-fetch`, `block-editor`, `block-library`, `blocks`, `data`, `core-data`, `global-styles-engine`, `global-styles-ui`, `hooks`, `interface`), so prototypes render with current versions. Every import of one of these resolves to the pinned copy, so the editor stores exist once. That holds for screens built on `block-editor`; packages above it, such as `@wordpress/editor`, `edit-post` and `plugins`, aren't pinned, so a plugin sidebar from `@plugin` isn't supported yet. Bump them deliberately, together: each editor release names the `@wordpress/components` and `@wordpress/ui` versions it was built against.

The pins can still change what other packages test against. The workspace hoists `@wordpress/*` into the root `node_modules`, where plugins find the packages they import without declaring, and a pin the root doesn't declare can take that slot. So the root `package.json` declares each pinned package, and the packages they pull in that moved when they were added, at the versions the rest of the workspace uses. Keep those entries, bump them with the root's other `@wordpress/*` packages rather than with the playground, and add a root entry for any new pin. A test fails when a pin has none.

`fixtures/editor` builds a block editor with core blocks, the block theme's styles and the block inspector. Build it and open it after a bump: a package that uses WordPress's private APIs under a name the workspace's `@wordpress/private-apis` doesn't list throws when the page loads, not at build.

The build stubs `@wordpress/route`, which needs React 19.

The CLI runs only from within a newspack-workspace checkout, because it uses the workspace's scripts and components.
