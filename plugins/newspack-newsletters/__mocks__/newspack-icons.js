/**
 * Manual Jest mock for the `newspack-icons` package.
 *
 * The npm package ships raw JSX in `node_modules/newspack-icons/src/` and is
 * not on Jest's `transformIgnorePatterns` allow-list, so a direct import
 * fails to parse during tests. Same posture as the `newspack-components`
 * mock alongside this one.
 */

const passthrough = () => null;

module.exports = new Proxy(
	{},
	{
		get( _target, prop ) {
			if ( prop === '__esModule' ) {
				return true;
			}
			return passthrough;
		},
	}
);
