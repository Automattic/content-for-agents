# Content for Agents contributor guide

## Project purpose

Content for Agents is a WordPress VIP plugin that publishes posts and pages as Markdown. Pretty permalinks use `{permalink}/markdown`; plain permalinks use `?markdown=true`. An explicit `Accept: text/markdown` also works at the normal URL. WordPress permissions govern non-public content. The base plugin is provider-neutral; compatibility adapters and VIP integration packaging belong in separate plugins.

The supported runtime is WordPress 7.0 or newer, PHP 8.2 or newer, and the WordPress VIP platform runtime. Node.js is required for the local WordPress integration environment and release packaging.

## Repository layout

- `content-for-agents.php` is the plugin entry point.
- The entry point maps top-level plugin classes to files in `includes/`.
- `includes/` contains the PHP implementation in the `Content_For_Agents` namespace.
- `README.md` provides the project overview; `docs/PLUGIN-GUIDE.md` explains customer-visible behavior; `docs/SITE-SETUP.md` contains site adaptation guidance and the public extension contracts.

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
composer check-platform-reqs
composer phpcs
composer test:unit
npm run format:check
npx wp-env start --config=.wp-env.test.json
npm run test:integration
git diff --check
```

Before committing or pushing, have an independent agent review the full change set. Resolve verified findings and rerun relevant checks.

## Branches

Prefix new branch names by purpose, such as `feature/`, `bugfix/`, `docs/`, or `chore/`.

## Pull requests

- Follow `.github/pull_request_template.md` when proposing or opening a PR. Describe only changes between the branch and trunk, and replace the template's guidance with specific content.
- Include relevant automated commands under Testing and reproducible steps with expected results under Manual testing. Remove a section when it does not apply.
- Include screenshots for visual changes when they help review. Omit a screenshots section for changes without visuals.
- Prepare the title and description for review before opening a PR unless the user has explicitly authorized opening it.

## Generated files and dependencies

- Do not commit `vendor/`. Composer installs development-only coding-standard tools, and the deployed plugin does not load Composer's autoloader.
- Keep the autoloader limited to direct classes in `Content_For_Agents`. Nested namespaces belong to integration plugins with their own loaders.
- Do not commit `node_modules/`.

## Implementation constraints

- Keep public PHP classes in the `Content_For_Agents` namespace and public identifiers under `content_for_agents` or `content-for-agents`.
- Preserve the extension contracts documented in `docs/SITE-SETUP.md`. Treat changes to hooks, option names, metadata, cache groups, and callback precedence as breaking changes.
- Do not add legacy provider aliases, provider-specific logic, or integration-framework packaging unless the task explicitly covers that work.
- The plugin requires VIP URL lookup, cache purge, and Cron Control APIs. Do not add silent non-VIP fallbacks that alter production behavior.
- Do not rely on activation hooks or stored rewrite rules. VIP application loaders may include the plugin during `plugins_loaded`.
- Use `/markdown` for individual documents with pretty permalinks and `markdown=true` with plain permalinks or a static front page. WordPress must resolve non-public posts and grant read permission.
- For the Accept route, support a case-sensitive `text/markdown` substring anywhere in the header value. This includes quoted parameters and `q=0`; mixed-case media types do not match. HTTP header names remain case insensitive. Keep response selection consistent with shared cache variants, and document these negotiation exceptions.
- Preserve access controls for private, draft, preview, and password-protected content. Never cache authenticated, preview, or password-authorized Markdown in a shared cache.
- Keep separate `Post_Context` scopes for filtered titles, body conversion, and response assembly. WordPress and third-party callbacks may read or change global `$post` despite receiving a post argument. Restore the caller's globals after each phase, including failures, and preserve context for direct converter calls. See `docs/ARCHITECTURE.md`.
- Cache invalidation changes must consider current and former `/markdown` and `markdown=true` URLs, plus parents, taxonomy changes, and author display-name changes.
- Update `README.md` when the project overview, installation, or supported versions change; update `docs/PLUGIN-GUIDE.md` when customer-visible behavior changes and `docs/SITE-SETUP.md` when integration contracts change.

## Private customer documentation site

- When asked to prepare a customer documentation site, use the current `README.md` for the purpose and beta warning. Build its guide pages directly from `docs/PLUGIN-GUIDE.md` and `docs/SITE-SETUP.md`. Keep the contributor guide, conversion architecture, and attribution in the repository, outside the customer site's navigation and published files.
- Use a simple responsive documentation layout: a dark navy header, a left navigation area on desktop, usable mobile navigation, a visible beta notice, readable article width, and keyboard focus styles. The home page should lead readers to using the plugin as is or adapting it to their site. Keep the site private and add `noindex` metadata and a deny-all `robots.txt`.
- Generate the site in an ignored local or task workspace directory from the current repository docs. Keep the generator, generated files, deployment state, API keys, handoff links, destination identifiers, and private access URLs out of the repository unless the user explicitly approves adding them.
- Publish an update only when the user explicitly authorizes that specific update. Use the existing private destination chosen by the user; missing credentials are not a reason to create a replacement. Confirm the new deployment is ready, check its pages and assets through authorized private access, and verify that unauthenticated visitors cannot reach it. Never make the site public without a separate explicit request.

## Style

- Use clear, concise technical US English in documentation and comments.
- Name upstream projects or collaborators only when crediting their work.
- Follow WordPress PHP conventions already present in the repository.
- Keep changes focused and avoid adding abstractions until more than one implementation needs them.
