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

`@np` (`packages/components/src`), `@plugin` (`plugins/newspack-plugin/src`) and `newspack-playground/bridge` are always available. A prototype's `aliases` are merged over them.

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

## Pinned versions

The playground bundles its own pinned copies of `@wordpress/components` and `@wordpress/dataviews`, independent of the range in `packages/components`, so prototypes render with current versions. Bump them deliberately, together.

The build stubs `@wordpress/route`, which needs React 19.

The CLI runs only from within a newspack-workspace checkout, because it uses the workspace's scripts and components.
