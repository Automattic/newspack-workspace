# Contributing to Newspack

Thank you for your interest in contributing to Newspack! These guidelines explain how the contribution process works.

**Please don't use the issue tracker for support questions or general inquiries.**

## Bug reports

**[To disclose a security issue, submit a report via HackerOne.](https://hackerone.com/automattic)**

To report a bug, [open a new issue](https://github.com/Automattic/newspack-workspace/issues/new?template=Bug_report.md). Please include:

- Steps to reproduce the issue.
- What you expected to happen.
- What actually happened.
- Details about your environment (WordPress version, PHP version, etc.).
- Screenshots if applicable.

## Feature requests

Feature requests can be [submitted to our issue tracker](https://github.com/Automattic/newspack-workspace/issues/new?template=Feature_request.md). Please search for similar ones in the closed issues before submitting.

## Pull requests

Create a pull request to the `main` branch. Please test and provide an explanation for your changes.

Guidelines:

- Follow the [WordPress Coding Standards](https://make.wordpress.org/core/handbook/best-practices/coding-standards/php/) and the [VIP Go Coding Standards](https://vip.wordpress.com/documentation/vip-go/code-review-blockers-warnings-notices/).
- Use conventional commits (`feat:`, `fix:`, etc.) for your commit messages.
- Run `pnpm install` at the root to set up the workspace and pre-commit hooks.
- Don't modify changelog files or `.pot` translation files. These are auto-generated.

### Code review

Every PR should be reviewed and approved by someone other than the author. Everyone is encouraged to review PRs and add feedback, regardless of experience level.

### Development setup

See the [README](../README.md) and [AGENTS.md](../AGENTS.md) for development environment setup, build commands, and testing instructions.

## License

Newspack is licensed under [GNU General Public License v2 (or later)](../LICENSE). All contributions must be compatible with the GPLv2.
