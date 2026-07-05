/**
 * Ambient module shim for `@wordpress/dataviews/wp`.
 *
 * That subpath is the build variant screens import `DataViews` from, so the
 * component shares Gutenberg's `@wordpress/element` instance instead of
 * bundling its own. Its `package.json#exports` map points both `.` and
 * `./wp` at the same `build-types/index.d.ts`, so the two subpaths have an
 * identical type surface — but this workspace's `moduleResolution: "node"`
 * doesn't read the `exports` map, so `/wp` fails to resolve on its own.
 * Mirroring the root package's types here fixes that for every screen in
 * the program (ambient module declarations apply workspace-wide, not just
 * to this file).
 *
 * This is a global script file (no top-level `import`/`export`), which is
 * required for `declare module` here to introduce a new ambient module
 * rather than fail as an augmentation of one that doesn't already resolve.
 */
declare module '@wordpress/dataviews/wp' {
	export * from '@wordpress/dataviews';
}
