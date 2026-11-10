# Publish Page Button

TYPO3-14-Extension (`publish_page_button`, Composer `gb-web-de/publish-page-button`): One-Click „Seite veroeffentlichen“ fuer Redakteure, die nur im Workspace arbeiten. Ein Klick bringt alle Workspace-Aenderungen einer Seite live (Seite, Uebersetzungen, Inhaltselemente, Bilder), Unterseiten nicht. Gleiche Logik wie der Publish-Knopf der Workspace-Vorschau, aber direkt im Seitenmodul.

## Bestandteile

- **Knopf im Seitenmodul & FormEngine** (`EventListener/PublishPageButton`, `ModifyButtonBarEvent`): erscheint im Seitenmodul (`web_layout`), im Visual Editor (`web_edit`) und im EditorialFlow-Board (`web_editorialflow`) fuer Seiten, sowie in der FormEngine (`record_edit`) als „Datensatz veroeffentlichen“ fuer alle workspace-faehigen Tabellen (z. B. News, Inhaltselemente).
- **Zeilen-Aktion in der Datensatzliste** (`EventListener/PublishRecordListAction`, `ModifyRecordListRecordActionsEvent`): ermoeglicht in der Listenansicht (`web_list`) das direkte Veroeffentlichen einzelner Workspace-Datensaetze mit einem Klick.
- **Route & Controller** (`Controller/PublishPageController`): veroeffentlicht Seiten oder einzelne Datensaetze per DataHandler und leitet mit Flash-Message zurueck. Berechtigung ueber den Core-`WorkspacePublishGate`.
- **PSR-14 Events** (`GbWebDe\PublishPageButton\Event\`):
  - `BeforePublishPageEvent`: Vor dem Veroeffentlichen abfangen, `cmdMap` modifizieren oder per `$event->cancel('Grund')` abbrechen (z. B. fuer Freigabe-Workflows / Compliance).
  - `AfterPublishPageEvent`: Nach dem Veroeffentlichen Resultat pruefen, Cache leeren, Webhooks triggern oder Benachrichtigungen versenden.

## EditorialFlow

Ist [gb-web/editorial-flow](https://github.com/gb-web-de/editorial-flow) installiert und aktiv, erscheint der Knopf auch im EditorialFlow-Board (`web_editorialflow`), sobald dort eine Seite gewaehlt ist. Ohne EditorialFlow aendert sich nichts; es ist nur als `suggest` eingetragen.

## Installation

```bash
composer require gb-web-de/publish-page-button
vendor/bin/typo3 extension:setup
vendor/bin/typo3 publishpage:setup        # Assistent: Gruppen, Workspace, Redakteure
vendor/bin/typo3 publishpage:check        # prueft, ob der Knopf funktioniert
```

Alle Szenarien und Optionen: [Documentation/Installation/README.de.md](Documentation/Installation/README.de.md).

Solange das Paket nicht auf Packagist liegt, das Git-Repository als `vcs`-Repository in der `composer.json` des Projekts eintragen.

## Entwicklung

```bash
composer install
composer test:unit
typo3DatabaseDriver=pdo_sqlite composer test:functional
composer phpstan
composer cs:check   # composer cs:fix zum Korrigieren
```
