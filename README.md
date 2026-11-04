# Publish Page Button

TYPO3-14-Extension (`publish_page_button`, Composer `gb-web-de/publish-page-button`): One-Click „Seite veroeffentlichen“ fuer Redakteure, die nur im Workspace arbeiten. Ein Klick bringt alle Workspace-Aenderungen einer Seite live (Seite, Uebersetzungen, Inhaltselemente, Bilder), Unterseiten nicht. Gleiche Logik wie der Publish-Knopf der Workspace-Vorschau, aber direkt im Seitenmodul.

## Bestandteile

- **Knopf** (`EventListener/PublishPageButton`, `ModifyButtonBarEvent`): erscheint im Seitenmodul (`web_layout`) und im Visual Editor (`web_edit`), sobald der Benutzer in einem Workspace ist und eine Seite gewaehlt hat. Immer sichtbar, weil der Visual Editor ohne Neuladen speichert.
- **Route** `publish_page_button` (`Controller/PublishPageController`): veroeffentlicht per DataHandler und leitet mit Flash-Message zurueck. Berechtigung ueber den Core-`WorkspacePublishGate` (Admin, Workspace-Besitzer oder Mitglied mit Live-Zugriff); Workspaces mit „nur aus der Publish-Stage“ werden abgelehnt. Backend-Routen pruefen das URL-Token, ein fremder Link kann also nichts veroeffentlichen.
- **Command** `luchse:workspace:setup` (`Command/WorkspaceSetupCommand`): legt den Workspace „Redaktion“ an, macht alle Benutzergruppen zu Besitzern (Besitzer duerfen ohne Live-Zugriff veroeffentlichen) und nimmt Gruppen und Nicht-Admins den Live-Zugriff. Name und Workspace-Titel stammen aus luchse-handball.de und sind dort im Deploy verdrahtet.

## EditorialFlow

Ist [gb-web/editorial-flow](https://github.com/gb-web-de/editorial-flow) installiert und aktiv, erscheint der Knopf auch im EditorialFlow-Board (`web_editorialflow`), sobald dort eine Seite gewaehlt ist. Ohne EditorialFlow aendert sich nichts; es ist nur als `suggest` eingetragen.

## Installation

```bash
composer require gb-web-de/publish-page-button
vendor/bin/typo3 extension:setup
vendor/bin/typo3 luchse:workspace:setup   # optional: Redaktions-Workspace einrichten
```

Solange das Paket nicht auf Packagist liegt, das Git-Repository als `vcs`-Repository in der `composer.json` des Projekts eintragen.

## Entwicklung

```bash
composer install
composer test:unit
composer phpstan
composer cs:check   # composer cs:fix zum Korrigieren
```
