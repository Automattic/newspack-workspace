import path from 'path';
import defaultConfig from '@wordpress/scripts/config/webpack.config.js';

/**
 * RtlCssPlugin added by Webpack to convert left, margin-left, etc. physical
 * properties to rtl like right, margin-right, etc.
 *
 * But we can use inset-inline-start, margin-inline-start, etc. logical
 * properties to achieve the same result.
 *
 * Hence, removing it from the plugins array.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_logical_properties_and_values
 */
const plugins = defaultConfig.plugins.filter(
	( plugin ) => plugin.constructor.name !== 'RtlCssPlugin'
);

export default {
	...defaultConfig,
	entry: {
		...defaultConfig.entry,
		'block-editor': path.resolve(
			import.meta.dirname,
			'src/block-editor/index.tsx'
		),
		'blocks/conditional-style/index': path.resolve(
			import.meta.dirname,
			'src/blocks/conditional-style'
		),
		index: path.resolve( import.meta.dirname, 'src/index.tsx' ),
	},
	module: {
		...defaultConfig.module,
		rules: [
			...defaultConfig.module.rules,
			/**
			 * postcss-loader is already added in the defaultConfig
			 * but we need it here for tailwindcss.
			 */
			{
				test: /\.scss$/i,
				include: path.resolve( import.meta.dirname, 'src' ),
				use: [ 'postcss-loader' ],
			},
		],
	},
	plugins,
};
