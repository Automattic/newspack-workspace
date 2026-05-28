module.exports = api => {
	api.cache( true );
	return {
		extends: 'newspack-scripts/config/babel.config.js',
		presets: [
			[
				'@babel/preset-env',
				{
					corejs: 3.6,
					useBuiltIns: 'entry',
					// Exclude transforms that make all code slower, see https://github.com/facebook/create-react-app/pull/5278
					exclude: [ 'transform-typeof-symbol' ],
					modules: 'commonjs',
				},
			],
			[
				'@babel/preset-react',
				{
					runtime: 'automatic',
					importSource: process.env.IMPORT_SOURCE,
				},
			],
			[ '@babel/preset-typescript', { allowDeclareFields: true } ],
		],
	};
};
