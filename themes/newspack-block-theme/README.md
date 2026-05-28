# Newspack

Welcome to the Newspack block theme repository on GitHub. Here you can browse the source, look at open issues and keep track of development. We also recommend everyone [follow the Newspack blog](https://newspack.com/) to stay up to date about everything happening in the project.

The Newspack block theme is a forward-looking news theme designed and developed to be highly customizable with the WordPress block editor.

Newspack is an open-source publishing platform built on WordPress for small to medium sized news organizations. It is an “opinionated” platform that stakes out clear, best-practice positions on technology, design, and business practice for news publishers.

## How to install Newspack on your site

If you'd like to install Newspack on your self-hosted site or want to try Newspack out, the easiest way to do so is to [download the latest plugin release](https://github.com/Automattic/newspack-plugin/releases) and [the latest theme release](https://github.com/Automattic/newspack-block-theme/releases). Upload them using the plugin or theme installer in your WordPress admin interface. To take full advantage of Newspack, the plugin and theme should be run together, but each should also work fine individually.

## Reporting Security Issues

To disclose a security issue to our team, [please submit a report via HackerOne here](https://hackerone.com/automattic/).

## Contributing to Newspack

If you have a patch or have stumbled upon an issue with the Newspack plugin/theme, you can contribute this back to the code. [Please read our contributor guidelines for more information on how you can do this.](https://github.com/Automattic/newspack-block-theme/blob/trunk/.github/CONTRIBUTING.md)

### Development

- Run `npm install && composer install` to install the dependencies.
- Run `npm start` to compile the SCSS and JS files, and start file watcher.
- Run `npm run build` to perform a single compilation run.

### Theme structure

This is a WordPress block theme. Global styles, layout, typography, and color are defined in `theme.json`. Templates and template parts are HTML block markup files:

- `templates/` — Page templates (front page, single, archive, search, 404, etc.). Some templates have layout variants in subdirectories (e.g. `templates/single/`, `templates/page/`).
- `parts/` — Reusable template parts (header, footer, menus). Headers and footers have separate mobile and desktop variants (e.g. `parts/header-mobile.html`, `parts/header-desktop.html`).
- `patterns/` — Block patterns organized into subdirectories by category (header, footer, post-header, post-meta, etc.). Note that patterns in subdirectories are not auto-registered by WordPress and are handled manually in `includes/class-patterns.php`.

### Blocks

This theme contains one custom block (`includes/blocks/subtitle-block/`) which is tightly coupled to theme-level post metadata. New blocks should not be added here — they belong in `newspack-plugin` (most cases) or `newspack-blocks`.

### Style variations

Two style variations are available in `styles/`: `harold.json` and `paul.json`. Each is a partial `theme.json` that overrides colors and typography from the base theme. Style variations can be selected from Appearance > Design in wp-admin.

### Variables

A cheat sheet of the most important CSS variables is available [here](https://github.com/Automattic/newspack-block-theme/blob/trunk/.github/VARIABLES.md).

### Utility Classes

A cheat sheet of the utility classes bundled with the theme is available [here](https://github.com/Automattic/newspack-block-theme/blob/trunk/.github/UTILITY_CLASSES.md).

## Support or Questions

This repository is not suitable for support or general questions about Newspack. Please only use our issue trackers for bug reports and feature requests, following [the contribution guidelines](https://github.com/Automattic/newspack-block-theme/blob/trunk/.github/CONTRIBUTING.md).

Support requests in issues on this repository will be closed on sight.

## License

Newspack is licensed under [GNU General Public License v2 (or later)](https://github.com/Automattic/newspack-block-theme/blob/trunk/LICENSE).
