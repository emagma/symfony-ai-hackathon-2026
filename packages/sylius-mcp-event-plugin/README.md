<p align="center">
    <a href="https://github.com/emagma/sylius-mcp-event-plugin/actions/workflows/ci.yml"><img src="https://github.com/emagma/sylius-mcp-event-plugin/actions/workflows/ci.yml/badge.svg" alt="Build"></a>
    <a href="https://packagist.org/packages/emagma/sylius-mcp-event-plugin"><img src="https://img.shields.io/packagist/v/emagma/sylius-mcp-event-plugin.svg" alt="Packagist version"></a>
    <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="License: MIT"></a>
</p>

<h1 align="center">Sylius MCP Event Plugin</h1>

<p align="center">
    The shop half of the MCP Sylius events bundle.<br>
    Relays what happens in the shop as signed webhooks.
</p>

## License

Released under the [MIT license](LICENSE).

The Emagma trademark, logo and artwork are not covered by it: see [TRADEMARK.md](TRADEMARK.md).

Made by [Emagma](https://www.emagma.fr), Toulouse, France.

## Requirements

| | Version |
| --- | --- |
| PHP | `^8.3` |
| Symfony | `6.4`, `7.4` or `8.1` |
| Sylius | `^1.14` or `^2.2` |
| Doctrine ORM | `^2.18` or `^3.0` |
| Symfony Messenger | `6.4`, `7.4` or `8.1` |
| Symfony Webhook | `6.4`, `7.4` or `8.1` |

## Install

```bash
composer require emagma/sylius-mcp-event-plugin
```

Register the plugin in `config/bundles.php` if Flex did not:

```php
Emagma\SyliusMcpEventPlugin\EmagmaSyliusMcpEventPlugin::class => ['all' => true],
```

Set `MCP_EVENT_WEBHOOK_URL` and `MCP_EVENT_WEBHOOK_SECRET` in the shop's environment: empty or unset, the plugin sends nothing.

## Documentation

- [User guide](docs/user-guide.md)
- [Developer guide](docs/developer-guide.md)

[Changelog](CHANGELOG.md) · [Upgrade guide](UPGRADE.md) · [Contributing](CONTRIBUTING.md)

## Contributing

Every contribution is welcome, from humans and agents alike.

The way in is a **detailed issue** rather than code:

- what you observed,
- what you expected,
- how to reproduce it,
- the change you propose.

A maintainer picks it up from there.

Improvements and feature proposals are welcome too.
We weigh them against the spirit of the current work.

Pull requests are open to collaborators only.
[CONTRIBUTING.md](CONTRIBUTING.md) shows what makes a good issue.

## Security

Found a vulnerability? Please do not open a public issue.

Write to <security@emagma.fr> with what you found and how to reproduce it.
We will get back to you directly.

## Support

This plugin is released under the MIT license.
It comes **without warranty or liability**: validating it for your own project is on you.

We use it ourselves and we care about it.
Bugs get fixed, with no promised timeline: this is shared work on top of client projects.

For priority help on your own project, contact our pro support at <support@emagma.fr>.
Paying us for consulting is a way to encourage our efforts in open-sourcing this plugin.
