<p align="center">
    <a href="https://github.com/emagma/sylius-mcp-plugin/actions/workflows/ci.yml"><img src="https://github.com/emagma/sylius-mcp-plugin/actions/workflows/ci.yml/badge.svg" alt="Build"></a>
    <a href="https://packagist.org/packages/emagma/sylius-mcp-plugin"><img src="https://img.shields.io/packagist/v/emagma/sylius-mcp-plugin.svg" alt="Packagist version"></a>
    <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="License: MIT"></a>
</p>

<h1 align="center">Sylius MCP Plugin (Sylius 2.x)</h1>

<p align="center">
    What a Sylius 2.x shop needs so that an MCP server can drive it.<br>
    Lean API payloads, MCP filters and the service account.
</p>

This is the plugin of the Sylius 2.x line.

## License

Released under the [MIT license](LICENSE).

The Emagma trademark, logo and artwork are not covered by it: see [TRADEMARK.md](TRADEMARK.md).

Made by [Emagma](https://www.emagma.fr), Toulouse, France.

## Requirements

| | Version |
| --- | --- |
| PHP | `^8.3` |
| Symfony | `6.4`, `7.4` or `8.1` |
| Sylius | `2.2` to `2.3` |
| API Platform | `^4.0` or `^5.0` |
| Doctrine ORM | `^2.18` or `^3.0` |

## Install

```bash
composer require emagma/sylius-mcp-plugin
```

Register the plugin in `config/bundles.php` if Flex did not:

```php
Emagma\SyliusMcpPlugin\EmagmaSyliusMcpPlugin::class => ['all' => true],
```

Enable the Sylius API in every environment the MCP server talks to, in `config/packages/sylius_api.yaml` (the Sylius standard enables it in `dev` only):

```yaml
sylius_api:
    enabled: true
```

Set `MCP_SERVICE_ACCOUNT_EMAIL` and `MCP_SERVICE_ACCOUNT_PASSWORD` in the shop's environment, then create the service account:

```bash
bin/console sylius-mcp:service-account
```

Clear the caches, API Platform's metadata pools included (they survive `cache:clear`):

```bash
bin/console cache:clear
bin/console cache:pool:clear api_platform.cache.metadata.resource \
    api_platform.cache.metadata.resource_collection api_platform.cache.metadata.operation \
    api_platform.cache.metadata.property cache.system
```

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
