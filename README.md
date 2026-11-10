# Publish Page Button

[Deutsche Fassung](README.de.md)

TYPO3 v14 extension (`publish_page_button`, Composer `gb-web-de/publish-page-button`): One-click "Publish page" and "Publish record" for editors working exclusively in workspaces. One click publishes all workspace changes on a page or single record to live. Fully bilingual (English and German).

## Features

- **DocHeader Button in Page Module & FormEngine** (`EventListener/PublishPageButton`, `ModifyButtonBarEvent`): appears in the Page module (`web_layout`), Visual Editor (`web_edit`), and EditorialFlow board (`web_editorialflow`) for pages, as well as in the FormEngine (`record_edit`) as "Publish record" for all workspace-aware tables (e.g. news, content elements).
- **Row Action in Record List** (`EventListener/PublishRecordListAction`, `ModifyRecordListRecordActionsEvent`): adds a one-click "Publish record" action in the Web List (`web_list`) directly on record rows.
- **Route & Controller** (`Controller/PublishPageController`): publishes pages or single records via DataHandler and redirects with a localized flash message. Permissions are governed by core `WorkspacePublishGate`.
- **Internationalization (i18n / l10n)**: All button titles, action labels, and flash messages are bilingual (English and German) via XLF files (`locallang.xlf` and `de.locallang.xlf`).
- **PSR-14 Events** (`GbWebDe\PublishPageButton\Event\`):
  - `BeforePublishPageEvent`: Intercept before publishing, inspect or modify `cmdMap`, or stop/cancel via `$event->cancel('Reason')` (e.g. for approval workflows / compliance). Supports `$event->isSingleRecord()`, `$event->getTable()`, and `$event->getRecordUid()`.
  - `AfterPublishPageEvent`: Inspect results (`hasErrors()`), clear caches, trigger webhooks, or send notifications.
- **Commands** `publishpage:setup` and `publishpage:check` (`Classes/Setup/`): set up editor groups, workspaces, and editors for four starting points and verify configuration. Guide: [Documentation/Installation/README.md](Documentation/Installation/README.md) ([German](Documentation/Installation/README.de.md)).

## EditorialFlow

If [gb-web/editorial-flow](https://github.com/gb-web-de/editorial-flow) is installed and active, the button automatically appears in the EditorialFlow board (`web_editorialflow`) when a page is selected.

## Installation

```bash
composer require gb-web-de/publish-page-button
vendor/bin/typo3 extension:setup
vendor/bin/typo3 publishpage:setup        # Wizard: groups, workspace, editors
vendor/bin/typo3 publishpage:check        # Verifies button readiness
```

All scenarios and options: [Documentation/Installation/README.md](Documentation/Installation/README.md).

## Development

```bash
composer install
composer test:unit
typo3DatabaseDriver=pdo_sqlite composer test:functional
composer phpstan
composer cs:check   # composer cs:fix to format
```
