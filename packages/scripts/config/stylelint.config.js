module.exports = {
	extends: [ '@wordpress/stylelint-config' ],
	ignoreFiles: [ 'dist/**', 'node_modules/**', 'release/**', 'scripts/**' ],
	rules: {
		'rule-empty-line-before': null,
		'at-rule-empty-line-before': null,
		'comment-empty-line-before': null,
		'no-descending-specificity': null,
		'function-url-quotes': null,
		'font-weight-notation': null,
		'color-named': null,
		'selector-class-pattern': null,
		'custom-property-pattern': null,
		'at-rule-no-unknown': null,
		'alpha-value-notation': null,
		'color-function-notation': null,
		'selector-not-notation': null,
		'no-invalid-double-slash-comments': null,
		'function-no-unknown': [
			true,
			{
				ignoreFunctions: [ '/color/' ],
			},
		],
		'annotation-no-unknown': [
			true,
			{
				ignoreAnnotations: [ '/default/' ],
			},
		],
		'media-feature-range-notation': null,
	},
};
