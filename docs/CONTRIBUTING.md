# Contributing to Content for Agents

This guide covers the quickest path from a new checkout to a validated change.
The public behavior and supported extension contracts are documented in the
[plugin guide](PLUGIN-GUIDE.md).

## Table of contents

- [Development requirements](#development-requirements)
- [Set up a checkout](#set-up-a-checkout)
- [Architecture and request lifecycle](#architecture-and-request-lifecycle)
- [Choose the right test layer](#choose-the-right-test-layer)
- [Run the complete checks](#run-the-complete-checks)
- [Common change recipes](#common-change-recipes)
- [Release boundaries](#release-boundaries)

## Development requirements

- PHP 8.2 or newer
- Composer
- Node.js 24, selected through `.nvmrc`
- npm 10 or newer
- Docker, OrbStack, or another Docker-compatible runtime for `wp-env`

The local `wp-env` configuration runs WordPress 7.0 with PHP 8.2, matching the
minimum supported runtime. CI runs PHP checks and unit tests with PHP 8.2
through 8.5. It runs the integration suite on those PHP versions against the
WordPress 7.0 and 7.1 branches and master.
For branch protection, require the stable `PHP / Result`, `Frontend`, and
`Integration / Result` checks. The PHP and Integration results pass only when
every job in their respective matrices succeeds. In the Actions run view, each
matrix and its result appear beneath the PHP or Integration caller.

Composer and npm dependencies are development-only. WordPress supplies the
runtime JavaScript packages, and the deployed plugin does not load Composer's
autoloader.

## Set up a checkout

```sh
composer install
nvm use
npm ci
npm run build
npx wp-env start
```

The development site is available at <http://localhost:8910> by default. If
those ports are already in use, run `npx wp-env start --auto-port` and use the
URL it prints. Sign in at
`/wp-admin/` with the default `wp-env` credentials, username `admin` and
password `password`. The WordPress test environment uses port `8911` by default.

Run `npx wp-env stop` when the environments are no longer needed. Generated
settings assets, dependencies, test results, browser artifacts, and ZIP files
are ignored and must not be committed.

## Architecture and request lifecycle

The plugin deliberately uses a small, direct bootstrap:

```text
content-for-agents.php
  -> plugins_loaded: Bootstrap creates and registers the modules
  -> init: supported post types and block callbacks are registered
  -> parse_request: /markdown and /llms.txt requests are handled
  -> template_redirect: the markdown=true query endpoint is handled
  -> Markdown_Response: access and response behavior are applied
  -> Markdown_Converter: blocks are resolved and converted
  -> Frontmatter: document metadata is assembled
  -> cache invalidators: affected Markdown URLs and /llms.txt are purged
```

The main responsibilities are divided as follows:

| Area                            | Primary classes                                                                                          |
| ------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Plugin initialization and hooks | `Bootstrap`, `Loader`                                                                                    |
| Dedicated Markdown URLs         | `Markdown_Endpoint`, `Markdown_Response`                                                                 |
| Block and HTML conversion       | `Markdown_Converter`, `Block_Markdown_Resolver`, `Block_Markdown_Registry`, `Html_To_Markdown_Converter` |
| Document metadata               | `Frontmatter`                                                                                            |
| Discovery                       | `Discovery`, `Robots_Txt`, `LLMs_Txt`                                                                    |
| Cache invalidation              | `Markdown_Cache_Invalidator`, `Llms_Txt_Cache_Invalidator`                                               |
| Admin interface and REST routes | `Settings` and `src/settings/`                                                                           |

Public PHP classes are direct members of the `Content_For_Agents` namespace.
Integration plugins use their own namespaces and autoloaders. Preserve the
extension contracts in the plugin guide when changing hooks, identifiers,
callback precedence, REST routes, options, metadata, or cache behavior.

The two public endpoints are handled directly during `parse_request`.
`/markdown` uses WordPress's normalized request path; `/llms.txt` compares URL
paths so it continues to work when plain permalinks leave the normalized request
empty. Do not replace this with stored rewrite rules or an activation hook. VIP
application-loaded plugins are not guaranteed to run activation hooks when
deployed. Individual documents use `/markdown` with pretty permalinks and the
`markdown=true` query endpoint with plain permalinks. The query
endpoint runs during `template_redirect` and supports anonymous published
content plus non-public content that WordPress has resolved as a permitted
singular request. It does not perform direct post-ID fallback.

## Choose the right test layer

| Layer                  | What it verifies                                                               | Command or method                            |
| ---------------------- | ------------------------------------------------------------------------------ | -------------------------------------------- |
| PHP unit               | Isolated behavior that does not require WordPress                              | `composer test:unit`                         |
| WordPress integration  | WordPress hooks, content conversion, and access behavior                       | `npm run test:integration`                   |
| Frontend unit          | Settings UI behavior, search, and save state                                   | `npm run test:frontend`                      |
| Frontend static checks | TypeScript types, linting, formatting, and settings compilation                | npm checks listed below                      |
| Manual UI              | The settings screen and public Markdown output in a real browser               | Exercise the UI and public URLs in a browser |
| VIP deployment         | Edge caching, purge propagation, provider services, and application load order | Verify in the target VIP application         |

Start `wp-env` before running the integration suite. Its bootstrap loads the
base plugin. Deployment checks cannot be fully reproduced by `wp-env`.

Tests should be added at the lowest layer that proves the behavior. Changes to
WordPress hooks, permissions, conversion integration, or cache invalidation
normally require an integration test even when the underlying helper also has a
unit test.

## Run the complete checks

Run the checks relevant to the change. Before proposing a completed change, run
the complete set:

```sh
composer validate --strict
composer phpcs
composer test:unit
npm run test:frontend
npm run typecheck
npm run lint:js
npm run format:check
npm run build
npx wp-env start
npm run test:integration
git diff --check
```

CI runs the same categories of checks and confirms that the required settings
assets can be generated from source.

## Common change recipes

- To support a custom block, use the registry or block metadata described in
  [Extend the base](PLUGIN-GUIDE.md#extend-the-base).
- To expose another post type, add the `content-for-agents` support flag. Add
  `content-for-agents-llms-txt` when changes to that type affect discovery.
- To add an `/llms.txt` section, filter
  `content_for_agents_llms_txt_sections` using the documented section shape.
- To provide editable starter settings, use
  `content_for_agents_settings_defaults`. Use
  `content_for_agents_additional_resources_blocks` for integration-maintained
  resources.
- When related data changes, identify the affected post IDs and use the public
  cache invalidators described in [Cache invalidation](PLUGIN-GUIDE.md#cache-invalidation).
- Provider-specific compatibility belongs in a separately loaded plugin, not
  in the provider-neutral base.

When changing access or invalidation behavior, include private, draft, preview,
password-protected, moved, and deleted content in the design. A conversion hook
that changes output does not automatically invalidate an already cached result.

## Release boundaries

The base plugin and integrations are separate release units.

The base version appears in `package.json`, its lockfile, the plugin header, and
`CONTENT_FOR_AGENTS_VERSION`. The **Create release PR** workflow updates these
values. After that version change reaches `trunk`, the release workflow builds
`content-for-agents.zip`, creates the matching `v{version}` tag, and publishes
the ZIP. The root package allowlist excludes development files and provider-specific
integrations.

Integrations keep their dependencies, versioning, release process, and
deployment separate from the base plugin.
