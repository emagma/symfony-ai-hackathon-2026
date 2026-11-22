# Contributing

Thanks for considering it.

This project takes contributions as **issues, not pull requests**.
You describe the problem and the change you propose, a maintainer validates the approach, then the change is implemented by a maintainer, or by an agent working under their review.
The same rule applies to humans and to AI agents.

Pull requests are open to repository collaborators only.
An issue with a clear proposal is the part that matters most, and it is all we ask of you.

## Before you start

Bug reports and feature requests go to [GitHub issues](../../issues).

For hands-on help on your own project, write to <support@emagma.fr>: that is our pro support.

A vulnerability is never reported in a public issue: write to <security@emagma.fr>.

## Writing a good issue

A detailed issue is worth more than code.
Whether you write it yourself or with an agent, it is ready to work on when it answers these:

- **The problem**: what you are trying to do and what blocks you today. For a bug: the bundle, Symfony and PHP versions, the steps to reproduce, what you expected and what happened instead.
- **The proposal**: the behaviour you want, described as something a user or an integrator can observe. A sketch of the approach (which service, tool, command or configuration key is involved) is welcome, not required.
- **The scope**: what is in, and what is explicitly out. One concern per issue: a bug fix plus a refactor plus a rename is three issues.
- **How we will know it works**: acceptance criteria, ideally concrete scenarios ("given..., when..., then...") that can become a test.
- **What you already tried**: the extension points you looked at (an interface, a tagged service, a decorator) and why they did not cover it.

### If an agent helped you write it

Agent-assisted issues are welcome, on the same terms as any other:

- **You own it**: read it before posting, and post only what you would defend yourself. A maintainer will answer you, not the agent.
- **Ground it in this repository**: name real files, services, configuration keys and versions. Proposals built on APIs that do not exist here get closed.
- **Keep it short**: state the problem and the proposal; leave out generic explanations of Symfony or MCP.
- **Describe the change rather than pasting a patch**: a diff without the reasoning behind it is hard to validate. Snippets that illustrate a point are fine.
- **Say so**: one line noting that the issue was drafted with an agent is enough.

## Improvements and new features

Proposals are welcome.

We weigh them against the spirit of the current work: a small bundle, one concern, contracts rather than options.
A proposal that fits gets discussed in its issue before any code is written.

## Branches and versions

`main` prepares the next minor version.
A maintenance branch (`0.1`) exists only when a patch is needed.

In `0.x`, a minor version may break compatibility, a patch only fixes; each minor has its section in [UPGRADE.md](UPGRADE.md).

## Implementing a change

The rest of this guide is for whoever implements an agreed issue: maintainers, collaborators, and the agent working under their review.
It is also what to check a proposal against.

### Getting set up

```bash
git clone https://github.com/emagma/mcp-tasks-messenger-bundle.git
cd mcp-tasks-messenger-bundle
composer install
```

The floor is PHP 8.4, as `composer.json` declares.

### The quality gate

Run these before you push.
All three must pass:

```bash
vendor/bin/ecs check        # coding standard (--fix applies it)
vendor/bin/phpstan analyse  # static analysis, level max
vendor/bin/phpunit          # unit and integration tests
```


The CI also runs the tests on the lowest dependencies the constraints allow (`composer update --prefer-lowest`) and on the highest.


### What a good pull request looks like

- **A link to the issue it implements** (`Closes #123`): no issue, no pull request.
- **One concern per pull request.**
- **Tests for the behaviour you changed**: `tests/Unit` for a class on its own.
- **Documentation updated** if you changed something a user can observe: the [user guide](docs/user-guide.md), the [developer guide](docs/developer-guide.md), and `README.md` if it changes what the bundle does.
- **A `CHANGELOG.md` entry** under the next version, and an `UPGRADE.md` section if it breaks compatibility.
- **A description that explains why**: the diff already shows what.

### Code style

The Sylius Labs coding standard, enforced by ECS, plus:

- `declare(strict_types=1);` in every PHP file.
- Classes are `final` and `@internal` unless they are an extension point (an interface, the entity).
- Services are defined explicitly in `config/services.php`, with ids prefixed by `emagma_mcp_tasks_messenger.`.
- Type everything: PHPStan runs at level `max` on `src/`.
- One short docblock per class, saying its role or its rule; no `@param` or `@return` that repeats the signature.
- No long dash (U+2014, U+2013) anywhere, tool descriptions included.

### Review and merge

Maintainers triage issues when they can.
This is not anyone's full-time job, so a few days of silence is normal rather than a snub.

Expect questions about the approach in the issue before any code is written, and questions about style only once there is a pull request.

Pull requests are squashed or rebased on merge, so don't worry about tidying the history.

## Licensing

By contributing, you agree that your contribution is licensed under the [MIT license](LICENSE), like the rest of the project.
