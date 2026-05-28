# Newspack

[![semantic-release](https://img.shields.io/badge/%20%20%F0%9F%93%A6%F0%9F%9A%80-semantic--release-e10079.svg)](https://github.com/semantic-release/semantic-release) [![newspack-plugin](https://circleci.com/gh/Automattic/newspack-plugin/tree/trunk.svg?style=shield)](https://circleci.com/gh/Automattic/newspack-plugin)

Welcome to the Newspack plugin repository on GitHub. Here you can browse the source, look at open issues and keep track of development. We also recommend everyone [follow the Newspack blog](https://newspack.com/) to stay up to date about everything happening in the project.

The Newspack plugin provides tools and guidance for setting up and managing the important features and plugins a modern newsroom needs.

Newspack is an open-source publishing platform built on WordPress for small to medium sized news organizations. It is an “opinionated” platform that stakes out clear, best-practice positions on technology, design, and business practice for news publishers.

## How to install Newspack on your site

If you'd like to install Newspack on your self-hosted site or want to try Newspack out, the easiest way to do so is to [download the latest plugin release](https://github.com/Automattic/newspack-plugin/releases) and [the latest theme release](https://github.com/Automattic/newspack-theme/releases). Upload them using the plugin or theme installer in your WordPress admin interface. To take full advantage of Newspack, the plugin and theme should be run together, but each should also work fine individually.

## Reporting Security Issues

To disclose a security issue to our team, [please submit a report via HackerOne here](https://hackerone.com/automattic/).

## Contributing to Newspack

If you have a patch or have stumbled upon an issue with the Newspack plugin/theme, you can contribute this back to the code. [Please read our contributor guidelines for more information on how you can do this.](https://github.com/Automattic/newspack-plugin/blob/trunk/.github/CONTRIBUTING.md)

### Development

- Run `npm start` to compile the SCSS and JS files, and start file watcher.
- Run `npm run build` to perform a single compilation run.

#### Environment variables

Some features require environment variables to be set (e.g. in `wp-config.php`):

```php
// Support
define( 'NEWSPACK_SUPPORT_EMAIL', 'support@company.com' );
```

## News Consumer Insights integration

[News Consumer Insights](https://newsinitiative.withgoogle.com/training/datatools) is a Google Analytics based solution for measuring performance of site audience using benchmarks and getting actionable recommendations to improve business gaps.

This plugin reports NCI events to a Google Analytics account, if one is connected via the Site Kit plugin. We're working on supporting [all applicable NCI events](https://newsinitiative.withgoogle.com/training/states/ntg/assets/ntg-playbook.pdf#page=245). The implementation is in `includes/class-analytics.php` file.

## Support or Questions

This repository is not suitable for support or general questions about Newspack. Please only use our issue trackers for bug reports and feature requests, following [the contribution guidelines](https://github.com/Automattic/newspack-plugin/blob/trunk/.github/CONTRIBUTING.md).

Support requests in issues on this repository will be closed on sight.

## License

Newspack is licensed under [GNU General Public License v2 (or later)](https://github.com/Automattic/newspack-plugin/blob/trunk/LICENSE.md).
