module.exports = {
	extends: [ './node_modules/newspack-scripts/config/stylelint.config.js' ],
	rules: {
		// WordPress gives every post a `tag-{slug}` class, so a tag named "Labels"
		// or "Label" would pick up any rule written for these. Select the
		// `newspack-tag-labels` and `newspack-tag-label` classes instead.
		'selector-disallowed-list': [
			[ '/\\.tag-labels?(?![\\w-])/' ],
			{ message: 'Use .newspack-tag-labels / .newspack-tag-label: a tag can put .tag-labels or .tag-label on a post.' },
		],
	},
};
