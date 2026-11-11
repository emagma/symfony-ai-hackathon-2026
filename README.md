# Emagma Agent Backend for Sylius

> Let any AI agent run your Sylius back-office, within each user's rights.

Emagma Agent Backend turns [Sylius](https://sylius.com), the Symfony-based open-source e-commerce platform, into an AI-operable back-office. It is built with [Symfony AI](https://github.com/symfony/ai) and the official PHP MCP SDK.

Built for the [Symfony AI & MCP Hackathon 2026](https://symfony.com/hackathon).

## Highlights

- **Not only a wrapper**: tools cut the round trips an agent makes to the store's APIs.
- **Lean by design**: a bundled plugin optimizes agent calls over API Platform and returns only the data the agent needs, saving tokens.
- **Access control ready**: a Keycloak adapter plugs into the MCP authorization flow, so user roles decide which tools each person can see and call.
- **Elicitation when available**: the server asks for missing details or confirmation before sensitive operations, when the client supports it.
- **Built to be extended**: third-party plugins can expose their own tools through an extension point, and yours register as Symfony services.

## Status

🚧 **Code coming soon.** This repository is being set up for the hackathon.

## License

Released under the MIT license.

## Made with ❤️ by Emagma
