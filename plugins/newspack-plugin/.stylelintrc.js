module.exports = {
	extends: [ './node_modules/newspack-scripts/config/stylelint.config.js' ],
	rules: {
		'function-no-unknown': [
			true,
			{
				ignoreFunctions: [ 'color.adjust', 'to-rgb', 'to-rgba' ],
			},
		],
		'selector-pseudo-class-no-unknown': [
			true,
			{
				ignorePseudoClasses: [ 'export' ],
			},
		],
		'property-no-unknown': [
			true,
			{
				ignoreProperties: [ /^overlay-/, /^primary-/, /^secondary-/, /^tertiary-/, /^quaternary-/, /^neutral-/, /^success-/, /^error-/, /^warning-/ ],
			},
		],
	},
};
