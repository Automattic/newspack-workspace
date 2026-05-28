# Newspack Ads

[![semantic-release](https://img.shields.io/badge/%20%20%F0%9F%93%A6%F0%9F%9A%80-semantic--release-e10079.svg)](https://github.com/semantic-release/semantic-release) [![newspack-ads](https://circleci.com/gh/Automattic/newspack-ads/tree/trunk.svg?style=shield)](https://circleci.com/gh/Automattic/newspack-ads)

A full suite of advertising functionality controls, allowing you to manage all ad placements and behaviors from within the WordPress admin. Connect to Google Ad Manager or Broadstreet for seamless inventory management, adjust load rules, and configure advanced programmatic practices such as header bidding – all with one click.

Some features include:
- Two options for ad server connection (Google Ad Manager and Broadstreet)
- Create and manage ad unit inventory
- Place ads across the site by enabling global placements, or using Newspack Ad blocks where desired
- Manage where and how often ads appear within content
- Set a custom ad label
- Control of lazy loading (for Google Ad Manager users)
- Control of active-view refresh
- Suppress ads based on tags or categories

### Development

- Run `npm start` to compile the JS files, and start file watcher.
- Run `npm run build` to perform a single compilation run.
- Run `npm run release:archive` to package a release. The archive will be created in `release/newspack-ads.zip`.

## Reporting Security Issues

To disclose a security issue to our team, [please submit a report via HackerOne here](https://hackerone.com/automattic/).

## Contributing to Newspack

If you have a patch or have stumbled upon an issue with the Newspack plugin/theme, you can contribute this back to the code. [Please read our contributor guidelines for more information on how you can do this.](https://github.com/Automattic/newspack-plugin/blob/trunk/.github/CONTRIBUTING.md)

## Support or Questions

This repository is not suitable for support or general questions about Newspack. Please only use our issue trackers for bug reports and feature requests, following [the contribution guidelines](https://github.com/Automattic/newspack-plugin/blob/trunk/.github/CONTRIBUTING.md).

Support requests in issues on this repository will be closed on sight.

## License

Newspack is licensed under [GNU General Public License v2 (or later)](https://github.com/Automattic/newspack-plugin/blob/trunk/LICENSE.md).
