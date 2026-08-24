/**
 * Manual Jest mock for the `newspack-components` package.
 *
 * The package ships ESM as its `main` entry (`dist/esm/index.js`) and is
 * not on Jest's `transformIgnorePatterns` allow-list, so a direct import
 * fails to parse during tests. Jest auto-loads `__mocks__/<pkg>.js` from
 * the project root for node_modules without requiring an explicit
 * `jest.mock()` call, so this stub keeps tests that transitively import
 * `newspack-components` (via the screen registry, the empty state, etc.)
 * loadable without rendering the real components.
 *
 * The exports are deliberately minimal: each component is a pass-through
 * function returning `null`, which is enough for module evaluation. Any
 * test that needs to inspect the rendered output of a `newspack-components`
 * component should mock the relevant export inline with richer behaviour
 * via `jest.mock('newspack-components', () => …)`.
 *
 * Member access yields another stub so compound components survive. Without
 * it `EmptyState.Root` is `undefined`, and a screen rendered at zero items
 * fails with "Element type is invalid", which reads as a broken component
 * rather than a gap in this file.
 */

// Only capitalised string properties stand in for nested components. React reads
// `$$typeof`, `displayName` and friends off the element type, and those must read
// through to the function rather than becoming stubs of their own.
const isComponentName = prop => typeof prop === 'string' && /^[A-Z]/.test( prop );

const createStub = () => {
	const members = new Map();
	return new Proxy( () => null, {
		get( target, prop ) {
			if ( ! isComponentName( prop ) ) {
				return target[ prop ];
			}
			if ( ! members.has( prop ) ) {
				members.set( prop, createStub() );
			}
			return members.get( prop );
		},
	} );
};

const stubs = new Map();

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
			if ( ! stubs.has( prop ) ) {
				stubs.set( prop, createStub() );
			}
			return stubs.get( prop );
		},
	}
);
