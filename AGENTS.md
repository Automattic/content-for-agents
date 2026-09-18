# Content for Agents contributor guide

## Project purpose

Content for Agents is a WordPress VIP plugin that publishes supported content through `{permalink}/markdown` and provides `/llms.txt` discovery. Individual Markdown documents require non-plain permalinks. The plugin is the provider-neutral base derived from PRC Markdown for Agents. PRC compatibility, provider adapters, and VIP integration-framework packaging are separate work.

The supported runtime is WordPress 6.8 or newer, PHP 8.2 or newer, and the WordPress VIP platform runtime. Node.js is required only to develop and build the settings interface.

## Repository layout

- `content-for-agents.php` is the plugin entry point.
- The entry point maps top-level plugin classes to files in `includes/`.
- `includes/` contains the PHP implementation in the `Content_For_Agents` namespace.
- `src/settings/` contains the TypeScript settings application.
- `build/settings/` contains generated production assets loaded by WordPress.
- `README.md` provides the project overview; `docs/PLUGIN-GUIDE.md` documents behavior and public extension contracts.

## Development setup

Use Node.js 24 from `.nvmrc`, npm, Composer, and PHP 8.2 or newer. Composer is used only for development tooling.

```sh
composer install
nvm use
npm ci
```

## Required checks

Run these checks after making relevant changes:

```sh
composer validate --strict
composer phpcs
composer test:unit
npm run typecheck
npm run lint:js
npm run format:check
npm run build
find . -path ./node_modules -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
npx wp-env start
npm run test:integration
git diff --check
```

CI verifies that the required production assets can be generated from source.

## Generated files and dependencies

- Do not commit `build/`. Change `src/settings/` and run `npm run build`; release packaging must generate and include the resulting assets.
- Do not commit `vendor/`. Composer installs development-only coding-standard tools, and the deployed plugin does not load Composer's autoloader.
- Keep the autoloader limited to direct classes in `Content_For_Agents`. Nested namespaces belong to integration plugins with their own loaders.
- Do not commit `node_modules/`.
- Keep runtime WordPress packages externalized. WordPress provides them through the dependencies listed in `build/settings/index.asset.php`.

## Implementation constraints

- Keep public PHP classes in the `Content_For_Agents` namespace and public identifiers under `content_for_agents` or `content-for-agents`.
- Preserve the extension contracts documented in `docs/PLUGIN-GUIDE.md`. Treat changes to hooks, option names, REST routes, metadata, cache groups, and callback precedence as breaking changes.
- Do not add legacy PRC aliases, settings migration, provider-specific logic, or integration-framework packaging unless the task explicitly covers that work.
- The plugin requires VIP URL lookup, cache purge, and Cron Control APIs. Do not add silent non-VIP fallbacks that alter production behavior.
- Do not rely on activation hooks or stored rewrite rules. VIP application loaders may include the plugin during `plugins_loaded`.
- Keep `/markdown` as the only individual document retrieval form. Plain `?p=123` permalinks are unsupported; do not advertise or index individual Markdown URLs for them.
- Preserve access controls for private, draft, preview, and password-protected content. Never cache authenticated, preview, or password-authorized Markdown in a shared cache.
- Cache invalidation changes must consider current and former `/markdown` URLs, with and without a trailing slash, plus parents, taxonomy changes, and author display-name changes.
- Update `README.md` when the project overview, installation, or supported versions change; update `docs/PLUGIN-GUIDE.md` when behavior or extension contracts change.

## Style

- Use US English in new documentation and comments.
- Follow WordPress PHP conventions already present in the repository.
- Keep changes focused and avoid adding abstractions until more than one implementation needs them.
- Use accessible WordPress components and target WCAG 2.2 Level AA for settings UI changes.
