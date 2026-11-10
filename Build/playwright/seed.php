<?php

declare(strict_types=1);

/*
 * Bereitet eine frische SQLite-Installation fuer den CI-Job "installation" vor - die Ausgangslage, die
 * ein Szenario von publishpage:setup erwartet. Nur fuer die CI, nicht fuer echte Installationen.
 *
 *   php Build/playwright/seed.php pages   Startseite mit Unterseite und Site, aber keine Gruppen
 *   php Build/playwright/seed.php tree    dazu ein gewachsener Gruppenbaum: "Redaktion" mit
 *                                         Seitenmodul und eigenen Seiten, "Bildredaktion" ohne
 *
 * Direkt per PDO, weil es fuer Gruppen keinen Core-Befehl gibt. Redakteure legt die CI danach mit
 * backend:user:create an - so, wie es die Anleitung beschreibt.
 */

$mode = $argv[1] ?? 'pages';
$database = glob('var/sqlite/*.sqlite')[0] ?? throw new RuntimeException('Keine SQLite-Datenbank unter var/sqlite/.');
$db = new PDO('sqlite:' . $database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = time();

$insert = static function (string $table, array $row) use ($db): void {
    $statement = $db->prepare(sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(',', array_keys($row)),
        implode(',', array_fill(0, count($row), '?')),
    ));
    $statement->execute(array_values($row));
};

$ownerGroup = $mode === 'tree' ? 1 : 0;
foreach ([[1, 0, 'Startseite', '/', 1], [2, 1, 'Ueber uns', '/ueber-uns', 0]] as [$uid, $pid, $title, $slug, $isRoot]) {
    $insert('pages', [
        'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1, 'is_siteroot' => $isRoot,
        'tstamp' => $now, 'crdate' => $now, 'perms_userid' => 1, 'perms_user' => 31,
        'perms_groupid' => $ownerGroup, 'perms_group' => $ownerGroup > 0 ? 31 : 0, 'perms_everybody' => 0,
    ]);
}

@mkdir('config/sites/main', 0775, true);
file_put_contents('config/sites/main/config.yaml', <<<'YAML'
    rootPageId: 1
    base: /
    languages:
      -
        title: Deutsch
        enabled: true
        languageId: 0
        base: /
        locale: de_DE.UTF-8
        navigationTitle: Deutsch
        flag: de
    YAML);

if ($mode === 'tree') {
    $insert('be_groups', [
        'uid' => 1, 'pid' => 0, 'title' => 'Redaktion', 'tstamp' => $now, 'crdate' => $now,
        'groupMods' => 'web_layout,records', 'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content',
        'pagetypes_select' => '1,254', 'explicit_allowdeny' => 'tt_content:CType:text,tt_content:CType:textmedia,tt_content:CType:header',
        'db_mountpoints' => '1', 'workspace_perms' => 1,
    ]);
    $insert('be_groups', [
        'uid' => 2, 'pid' => 0, 'title' => 'Bildredaktion', 'tstamp' => $now, 'crdate' => $now,
        'groupMods' => 'media_management', 'tables_select' => 'sys_file_reference', 'tables_modify' => 'sys_file_reference',
        'workspace_perms' => 1,
    ]);
}

echo sprintf("Angelegt: %s\n", $mode === 'tree' ? 'Seiten, Site und Gruppenbaum' : 'Seiten und Site');
