# Publish Page Button

[English version](README.md)

TYPO3-14-Extension (`publish_page_button`, Composer `gb-web-de/publish-page-button`): One-Click „Seite veröffentlichen“ und „Datensatz veröffentlichen“ für Redakteure, die nur im Workspace arbeiten. Ein Klick bringt alle Workspace-Änderungen einer Seite oder eines Datensatzes live. Vollständig zweisprachig (Deutsch und Englisch).

## Bestandteile

- **Knopf im Seitenmodul & FormEngine** (`EventListener/PublishPageButton`, `ModifyButtonBarEvent`): erscheint im Seitenmodul (`web_layout`), im Visual Editor (`web_edit`) und im EditorialFlow-Board (`web_editorialflow`) für Seiten, sowie in der FormEngine (`record_edit`) als „Datensatz veröffentlichen“ für alle workspace-fähigen Tabellen (z. B. News, Inhaltselemente).
- **Zeilen-Aktion in der Datensatzliste** (`EventListener/PublishRecordListAction`, `ModifyRecordListRecordActionsEvent`): ermöglicht in der Listenansicht (`web_list`) das direkte Veröffentlichen einzelner Workspace-Datensätze mit einem Klick.
- **Route & Controller** (`Controller/PublishPageController`): veröffentlicht Seiten oder einzelne Datensätze per DataHandler und leitet mit Flash-Message zurück. Berechtigung über den Core-`WorkspacePublishGate`.
- **Mehrsprachigkeit (i18n / l10n)**: Alle Button-Labels, Zeilen-Aktionen und Flash-Messages sind über XLF-Sprachdateien (`locallang.xlf` und `de.locallang.xlf`) zweisprachig (Englisch und Deutsch).
- **PSR-14 Events** (`GbWebDe\PublishPageButton\Event\`):
  - `BeforePublishPageEvent`: Vor dem Veröffentlichen abfangen, `cmdMap` modifizieren oder per `$event->cancel('Grund')` abbrechen (z. B. für Freigabe-Workflows / Compliance). Unterstützt `$event->isSingleRecord()`, `$event->getTable()` und `$event->getRecordUid()`.
  - `AfterPublishPageEvent`: Nach dem Veröffentlichen Resultat prüfen (`hasErrors()`), Cache leeren, Webhooks triggern oder Benachrichtigungen versenden.
- **Commands** `publishpage:setup` und `publishpage:check` (`Classes/Setup/`): richten Redaktionsgruppen, Workspace und Redakteure für vier Ausgangslagen ein und prüfen, ob der Knopf funktioniert. Anleitung: [Documentation/Installation/README.de.md](Documentation/Installation/README.de.md) ([English](Documentation/Installation/README.md)).

## EditorialFlow

Ist [gb-web/editorial-flow](https://github.com/gb-web-de/editorial-flow) installiert und aktiv, erscheint der Knopf auch im EditorialFlow-Board (`web_editorialflow`), sobald dort eine Seite gewählt ist. Ohne EditorialFlow ändert sich nichts; es ist nur als `suggest` eingetragen.

## Installation

```bash
composer require gb-web-de/publish-page-button
vendor/bin/typo3 extension:setup
vendor/bin/typo3 publishpage:setup        # Assistent: Gruppen, Workspace, Redakteure
vendor/bin/typo3 publishpage:check        # prüft, ob der Knopf funktioniert
```

Alle Szenarien und Optionen: [Documentation/Installation/README.de.md](Documentation/Installation/README.de.md).

## Entwicklung

```bash
composer install
composer test:unit
typo3DatabaseDriver=pdo_sqlite composer test:functional
composer phpstan
composer cs:check   # composer cs:fix zum Korrigieren
```
