# Installation: so einfach wie möglich

[English version](README.md)

Der Knopf „Seite veröffentlichen“ braucht drei Dinge: Redakteure arbeiten **in einem Workspace** (nicht in Live), ihre Gruppe **besitzt** diesen Workspace (nur Besitzer dürfen ohne Live-Zugriff veröffentlichen), und der Workspace erlaubt Veröffentlichen **nicht nur aus der Publish-Stage**. Zwei Befehle richten das ein und prüfen es:

- `publishpage:setup` richtet ein. Ohne Angaben fragt ein Assistent nach.
- `publishpage:check` prüft und sagt, was fehlt.

Beide sind beliebig oft wiederholbar. Das Setup fügt nur hinzu, was fehlt; es nimmt keiner Gruppe ein Modul, keinem Workspace einen Besitzer und übernimmt keine Seite, die schon einer anderen Gruppe gehört.

## 1. Extension installieren

```bash
composer require gb-web-de/publish-page-button
vendor/bin/typo3 extension:setup
```

## 2. Die eigene Ausgangslage wählen

Am einfachsten ohne Angaben – der Assistent fragt alles Nötige:

```bash
vendor/bin/typo3 publishpage:setup
```

Oder direkt mit einem Szenario (für Deploy und CI). Vorher mit `--dry-run` ansehen, was passieren würde:

| Ausgangslage | Befehl |
| --- | --- |
| **Grüne Wiese**, leere Datenbank | `publishpage:setup fresh --editor=jana` |
| **Eine Redaktionsgruppe**, Name frei wählbar | `publishpage:setup single-group --group="Online-Redaktion" --editor=jana` |
| **Mehrere Gruppen, jede mit eigenem Workspace** | `publishpage:setup group-per-workspace --group="News,Sport,Kultur" --editor=jana:Sport` |
| **Großer bestehender Gruppenbaum** | `publishpage:setup existing-groups --attach-to-editors --dry-run` |

Das Passwort für neue Redakteure kommt aus `--editor-password=…` oder – besser, weil es nicht in der Shell-History landet – aus der Umgebungsvariable `PUBLISHPAGE_EDITOR_PASSWORD`.

### Was die Szenarien anlegen

**fresh** – Ist der Seitenbaum leer, kommen eine Startseite und eine Site (`--site-base=https://…`, Standard `/`) dazu. Danach wie *single-group* mit der Gruppe „Redaktion“.

**single-group** – Eine Gruppe (Standard „Redaktion“, frei wählbar mit `--group`) mit Seitenmodul, Visual Editor, Liste und Medien, allen Inhaltstypen, gemountet auf den Startseiten, **ohne** Live-Zugriff. Sie bekommt alle Seiten, die noch keiner Gruppe gehören. Dazu ein Workspace gleichen Namens (anderer Name: `--workspace=…`), den die Gruppe besitzt. Gibt es die Gruppe schon, bekommt sie höchstens das Seitenmodul dazu – sonst bleibt sie, wie sie ist.

**group-per-workspace** – Jede Gruppe bekommt einen eigenen Workspace mit ihrem Namen und besitzt nur diesen. Seiten und Rechte liegen in einer gemeinsamen Gruppe „Redaktion (gemeinsame Rechte)“, die jede Gruppe einschließt. So teilen sich alle die Seiten, aber Sport kann nicht im Workspace von News veröffentlichen.

**existing-groups** – Für gewachsene Installationen mit vielen Gruppen. Keine Gruppe und keine Seite wird geändert. Die genannten Gruppen werden Besitzer des Workspaces „Redaktion“ (anderer Name: `--workspace=…`):

```bash
# alle Gruppen, die das Seitenmodul haben
vendor/bin/typo3 publishpage:setup existing-groups --attach-to-editors
# oder gezielt, nach Titel oder uid
vendor/bin/typo3 publishpage:setup existing-groups --attach-to="Redaktion,Online-Desk,12"
```

Besitz wirkt über Untergruppen: Wer in einer Gruppe ist, die eine Besitzergruppe einschließt, darf ebenfalls veröffentlichen. Eine Gruppe in einen bestehenden Workspace zu hängen reicht also meistens.

## 3. Prüfen

```bash
vendor/bin/typo3 publishpage:check
```

Der Befehl endet mit Fehlercode, wenn etwas den Knopf sicher blockiert – ein Deploy kann ihn also nach dem Setup laufen lassen. Typische Befunde und was hilft:

| Befund | Abhilfe |
| --- | --- |
| Workspace erlaubt Veröffentlichen nur aus der Publish-Stage | `publishpage:check --fix` |
| Redakteur oder Gruppe darf Live bearbeiten (Redakteur landet in Live, sieht keinen Knopf) | `publishpage:check --fix-live-editing` |
| Redakteur mit Seitenmodul besitzt keinen Workspace („Keine Berechtigung“) | `publishpage:setup existing-groups --attach-to="<seine Gruppe>"` |
| Workspace ohne Besitzer | wie oben |

## 4. Weitere Redakteure

Mit dem Setup (legt an oder nimmt bestehende Benutzer in die Gruppe auf, Passwort und Einstellungen bleiben):

```bash
PUBLISHPAGE_EDITOR_PASSWORD='…' vendor/bin/typo3 publishpage:setup single-group --group="Online-Redaktion" --editor=tom
```

Oder mit dem Core – dann danach Live-Zugriff abschalten, denn das Benutzerformular hakt „Live bearbeiten“ standardmäßig an:

```bash
vendor/bin/typo3 backend:user:create --username=tom --groups=<uid der Gruppe>
vendor/bin/typo3 publishpage:check --fix-live-editing
```

## Für Entwickler: Tests

```bash
composer test:unit
typo3DatabaseDriver=pdo_sqlite composer test:functional
npm ci && PUBLISHPAGE_BASE_URL=https://… PUBLISHPAGE_SETUP_EDITOR=jana npx playwright test
```

Die CI (`.github/workflows/ci.yml`) spielt jedes Szenario dieser Anleitung in einer frischen TYPO3-Installation durch: Setup, Check, zweiter Lauf ohne Änderung und im Browser eine Seite ändern und per Knopf live bringen. Ein Tag `v1.2.3` startet `release.yml`: Erst läuft die ganze CI, dann entsteht ein GitHub-Release mit installierbarem Zip.
