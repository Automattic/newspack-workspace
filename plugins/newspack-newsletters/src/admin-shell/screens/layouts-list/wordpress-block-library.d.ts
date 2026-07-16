/**
 * Ambient module shim for `@wordpress/block-library`.
 *
 * The package ships no `.d.ts` files at all (no `types`/`typings` field in
 * its `package.json`), so any import from it is otherwise an implicit-`any`
 * error under this workspace's `strict` config. Only `registerCoreBlocks`
 * is used (in `./index.tsx`); typed narrowly rather than blanket-`any`.
 *
 * This is a global script file (no top-level `import`/`export`), which is
 * required for `declare module` here to introduce a new ambient module.
 */
declare module '@wordpress/block-library' {
	export function registerCoreBlocks(): void;
}
