# Installation: as simple as possible

[Deutsche Fassung](README.de.md)

The "Seite veröffentlichen" (publish page) button needs three things: editors work **in a workspace** (not in Live), their group **owns** that workspace (only owners may publish without Live access), and the workspace does **not** restrict publishing to the publish stage. Two commands set this up and check it:

- `publishpage:setup` sets it up. Without arguments, a wizard asks.
- `publishpage:check` checks and tells you what is missing.

Both can be run as often as you like. Setup only adds what is missing: it never takes a module from a group or an owner from a workspace, and never takes over a page that already belongs to another group.

## 1. Install the extension

```bash
composer require gb-web-de/publish-page-button
vendor/bin/typo3 extension:setup
```

## 2. Pick your starting point

Simplest: no arguments, the wizard asks for everything it needs:

```bash
vendor/bin/typo3 publishpage:setup
```

Or name a scenario (for deployments and CI). Look at the plan with `--dry-run` first:

| Starting point | Command |
| --- | --- |
| **Greenfield**, empty database | `publishpage:setup fresh --editor=jana` |
| **One editor group**, any name | `publishpage:setup single-group --group="Online-Redaktion" --editor=jana` |
| **Several groups, each with its own workspace** | `publishpage:setup group-per-workspace --group="News,Sport,Kultur" --editor=jana:Sport` |
| **Large existing group tree** | `publishpage:setup existing-groups --attach-to-editors --dry-run` |

The password for new editors comes from `--editor-password=…` or, better since it stays out of your shell history, from the environment variable `PUBLISHPAGE_EDITOR_PASSWORD`.

### What each scenario creates

**fresh**: if the page tree is empty, a root page and a site are added (`--site-base=https://…`, default `/`). Then the same as *single-group* with the group "Redaktion".

**single-group**: one group (default "Redaktion", any name with `--group`) with the Page module, Visual Editor, List and Media, all content types, mounted on the root pages, **without** Live access. It gets every page no group owns yet. Plus a workspace of the same name (another name: `--workspace=…`) that the group owns. If the group already exists, at most the Page module is added; otherwise it stays as it is.

**group-per-workspace**: every group gets its own workspace with its name and owns only that one. Pages and rights live in a shared group "Redaktion (gemeinsame Rechte)" that every group includes. Everyone shares the pages, but Sport cannot publish in News's workspace.

**existing-groups**: for grown installations with many groups. No group and no page is changed. The groups you name become owners of the workspace "Redaktion" (another name: `--workspace=…`):

```bash
# every group that has the Page module
vendor/bin/typo3 publishpage:setup existing-groups --attach-to-editors
# or by title or uid
vendor/bin/typo3 publishpage:setup existing-groups --attach-to="Redaktion,Online-Desk,12"
```

Ownership works through subgroups: anyone in a group that includes an owner group may publish too. So attaching one group is usually enough.

## 3. Check

```bash
vendor/bin/typo3 publishpage:check
```

It exits with an error code when something definitely blocks the button, so a deployment can run it after setup. Typical findings and their fix:

| Finding | Fix |
| --- | --- |
| Workspace only publishes from the publish stage | `publishpage:check --fix` |
| Editor or group may edit Live (editor lands in Live, sees no button) | `publishpage:check --fix-live-editing` |
| Editor with the Page module owns no workspace ("Keine Berechtigung") | `publishpage:setup existing-groups --attach-to="<their group>"` |
| Workspace without owners | as above |

## 4. More editors

With setup (creates the user, or adds an existing one to the group; password and settings stay):

```bash
PUBLISHPAGE_EDITOR_PASSWORD='…' vendor/bin/typo3 publishpage:setup single-group --group="Online-Redaktion" --editor=tom
```

Or with the core command. Then switch Live access off, because the backend user form ticks "Edit Live" by default:

```bash
vendor/bin/typo3 backend:user:create --username=tom --groups=<group uid>
vendor/bin/typo3 publishpage:check --fix-live-editing
```

## For developers: tests

```bash
composer test:unit
typo3DatabaseDriver=pdo_sqlite composer test:functional
npm ci && PUBLISHPAGE_BASE_URL=https://… PUBLISHPAGE_SETUP_EDITOR=jana npx playwright test
```

CI (`.github/workflows/ci.yml`) plays every scenario of this guide in a fresh TYPO3: setup, check, a second run that changes nothing, and in the browser an editor changes a page and publishes it with the button. A tag `v1.2.3` starts `release.yml`: the whole CI runs first, then a GitHub release with an installable zip is created.
