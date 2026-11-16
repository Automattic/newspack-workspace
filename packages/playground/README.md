# Newspack Playground

A harness for building design prototypes against the workspace's own components. The prototypes live outside this repo; the playground only supplies the build and the frame bridge.

## Config

Each prototype has a `playground.config.js` next to it. It is read by the build command.

```js
export default {
	entry: './src/screens.js',
	outDir: './dist',
};
```

## Build

```sh
npx newspack-playground build
```

## Bridge

`newspack-playground/bridge` keeps a parent page and an iframe in sync. It is plain ESM with no dependencies.

-   `connectScreen( { win, onSetState } )` runs inside the iframe. `report( state )` posts the state to the parent and mirrors it into the URL query.
-   `connectFrame( iframe, { onState, onHeight } )` runs in the parent. `setState( state )` pushes state into the iframe.
-   `readState( search )` and `writeState( state )` convert between a query string and a state object.

Messages are `np-playground:state`, `np-playground:height` and `np-playground:set-state`. Both sides only accept messages from the expected window on the same origin.

## Pinned versions

`@wordpress/components` and `@wordpress/dataviews` are pinned to the versions bundled with the WordPress release we support. Update them together with that release.
