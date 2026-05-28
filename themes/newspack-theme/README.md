# Newspack

Welcome to the Newspack theme repository on GitHub. Here you can browse the source, look at open issues and keep track of development. We also recommend everyone [follow the Newspack blog](https://newspack.com/) to stay up to date about everything happening in the project.

The Newspack theme is a forward-looking news theme designed and developed to be highly customizable with the WordPress block editor.

Newspack is an open-source publishing platform built on WordPress for small to medium sized news organizations. It is an “opinionated” platform that stakes out clear, best-practice positions on technology, design, and business practice for news publishers.

## How to install Newspack on your site

If you'd like to install Newspack on your self-hosted site or want to try Newspack out, the easiest way to do so is to [download the latest plugin release](https://github.com/Automattic/newspack-plugin/releases) and [the latest theme release](https://github.com/Automattic/newspack-theme/releases). Upload them using the plugin or theme installer in your WordPress admin interface. To take full advantage of Newspack, the plugin and theme should be run together, but each should also work fine individually.

## Reporting Security Issues

To disclose a security issue to our team, [please submit a report via HackerOne here](https://hackerone.com/automattic/).

## Contributing to Newspack

If you have a patch or have stumbled upon an issue with the Newspack plugin/theme, you can contribute this back to the code. [Please read our contributor guidelines for more information on how you can do this.](https://github.com/Automattic/newspack-theme/blob/trunk/.github/CONTRIBUTING.md)

### Development

The Newspack theme repository contains the Newspack parent theme in a subdirectory. This means you cannot `git clone` the theme directly to the wp-content/themes directory and have it work. The recommended approach is to `git clone` the repository to another location, and symlink the `newspack-theme/newspack-theme` directory -- the folder containing the actual theme -- to the `wp-content/themes` directory of your development website.

- Run `npm install && composer install` to install the dependencies.
- Run `npm start` to compile the SCSS and JS files, and start file watcher.
- Run `npm run build` to perform a single compilation run.

### Repository Structure

The repository contains the base theme and five child themes:

- `newspack-theme/` — Base theme. All child themes extend this.
- `newspack-joseph/`, `newspack-katharine/`, `newspack-nelson/`, `newspack-sacha/`, `newspack-scott/` — Child themes. Cosmetic variants only (colors, typography accents); identical in features.

The build tooling at the repo root compiles assets for all six themes in a single pass. There is no per-theme build command.

### PHP Structure

Key files in `newspack-theme/inc/`:

| File | Purpose |
|------|---------|
| `customizer.php` | All Customizer settings: colors, typography, header/footer layout |
| `template-functions.php` | Template hooks, body class additions, content filters |
| `template-tags.php` | Template tag functions for post meta, author info, dates, reading time |
| `color-patterns.php` | Converts Customizer color settings into inline CSS |
| `typography.php` | Font loading and custom typography CSS generation |
| `icon-functions.php` | SVG icon helpers |

Plugin integrations (WooCommerce, Jetpack, Yoast, etc.) each have their own file in `inc/` and are only loaded when the relevant plugin is active.

### SCSS Structure

Source SCSS lives in `newspack-theme/sass/` and is compiled by a custom Node script (`scripts/compile-scss.js`) that handles all themes, RTL generation, and minification.

Key subdirectories:

| Directory | Contents |
|-----------|----------|
| `blocks/` | Gutenberg block styles |
| `elements/` | Lists, tables, and basic HTML elements |
| `layout/` | Grid and layout utilities |
| `mixins/` | SCSS mixins |
| `modules/` | Component modules (cards, bylines, etc.) |
| `navigation/` | Menu and nav styles |
| `plugins/` | Integration styles (WooCommerce, Newsletters, Sponsors, etc.) |
| `site/` | Header, footer, sidebar styles |

Child themes have their own `sass/` directories with override files compiled alongside the base theme.

### Child Themes

The child themes share the base theme's features entirely — differences are purely cosmetic. To switch between child themes, use **Appearance > Themes** in wp-admin. Switching via the Customizer does not reliably transfer settings between themes.

Each child theme follows the same structure:

```
newspack-<name>/
├── functions.php         # Enqueues child styles, hooks color/typography CSS
├── style.css             # Theme header + generated CSS
├── style-editor.css      # Generated editor styles
├── style-rtl.css         # Generated RTL styles
├── inc/
│   ├── child-color-patterns.php  # Child-specific color CSS generation
│   └── child-typography.php     # Child-specific typography CSS generation
└── sass/                 # SCSS overrides compiled alongside the base theme
```

## Support or Questions

This repository is not suitable for support or general questions about Newspack. Please only use our issue trackers for bug reports and feature requests, following [the contribution guidelines](https://github.com/Automattic/newspack-theme/blob/trunk/.github/CONTRIBUTING.md).

Support requests in issues on this repository will be closed on sight.

## License

Newspack is licensed under [GNU General Public License v2 (or later)](https://github.com/Automattic/newspack-theme/blob/trunk/LICENSE).
