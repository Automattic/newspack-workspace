/**
 * Manual Jest mock for the `newspack-components` package.
 *
 * The package ships ESM as its `main` entry (`dist/esm/index.js`) and is
 * not on Jest's `transformIgnorePatterns` allow-list, so a direct import
 * fails to parse during tests. Jest auto-loads `__mocks__/<pkg>.js` from
 * the project root for node_modules without requiring an explicit
 * `jest.mock()` call — this stub keeps tests that transitively import
 * `newspack-components` (via the screen registry, the empty state, etc.)
 * loadable without rendering the real components.
 *
 * The exports are deliberately minimal: each component is a pass-through
 * function returning `null`, which is enough for module evaluation. Any
 * test that needs to inspect the rendered output of a `newspack-components`
 * component should mock the relevant export inline with richer behaviour
 * via `jest.mock('newspack-components', () => …)`.
 */

const passthrough = () => null;

module.exports = new Proxy(
	{},
	{
		get( _target, prop ) {
			// `__esModule` is checked by Babel/Webpack interop to decide
			// whether to use `default` vs the namespace. Returning `true`
			// keeps default-import shape consistent with the real ESM.
			if ( prop === '__esModule' ) {
				return true;
			}
			return passthrough;
		},
	}
);
