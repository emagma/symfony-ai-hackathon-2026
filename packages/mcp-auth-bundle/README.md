<p align="center">
    <a href="https://github.com/emagma/mcp-auth-bundle/actions/workflows/ci.yml"><img src="https://github.com/emagma/mcp-auth-bundle/actions/workflows/ci.yml/badge.svg" alt="Build"></a>
    <a href="https://packagist.org/packages/emagma/mcp-auth-bundle"><img src="https://img.shields.io/packagist/v/emagma/mcp-auth-bundle.svg" alt="Packagist version"></a>
    <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="License: MIT"></a>
</p>

<h1 align="center">MCP Auth Bundle</h1>

<p align="center">
    The MCP endpoint of a Symfony server as an OAuth 2.0 protected resource.<br>
    RFC 9728 metadata, the 401 challenge, the grants of the token.
</p>

## License

Released under the [MIT license](LICENSE).

The Emagma trademark, logo and artwork are not covered by it: see [TRADEMARK.md](TRADEMARK.md).

Made by [Emagma](https://www.emagma.fr), Toulouse, France.

## Requirements

| | Version |
| --- | --- |
| PHP | `>=8.4` |
| Symfony | `7.4` or `8.1` |
| emagma/mcp-core-bundle | `^0.1` |

## Install

```bash
composer require emagma/mcp-auth-bundle
```

```php
// config/bundles.php
return [
    // ...
    Emagma\McpAuthBundle\EmagmaMcpAuthBundle::class => ['all' => true],
];
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

This bundle is released under the MIT license.
It comes **without warranty or liability**: validating it for your own project is on you.

We use it ourselves and we care about it.
Bugs get fixed, with no promised timeline: this is shared work on top of client projects.

For priority help on your own project, contact our pro support at <support@emagma.fr>.
Paying us for consulting is a way to encourage our efforts in open-sourcing this bundle.
