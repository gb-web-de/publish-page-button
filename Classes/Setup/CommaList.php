<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Setup;

/**
 * Kommalisten, wie TYPO3 groupMods, subgroup, db_mountpoints und Workspace-Besitzer speichert und wie
 * man sie auf der Kommandozeile tippt.
 *
 * Wichtig ist merge(): Das Setup ergaenzt Listen nur. groupMods einer bestehenden Gruppe zu ueberschreiben
 * wuerde jedem Redakteur darin still Module wegnehmen.
 */
final class CommaList
{
    /**
     * @param string|list<string> $value ein String oder die Werte einer wiederholten Option
     * @return list<string> getrimmt, ohne leere Eintraege, erstes Vorkommen bleibt
     */
    public static function parse(string|array $value): array
    {
        $parts = [];
        foreach ((array)$value as $chunk) {
            foreach (explode(',', (string)$chunk) as $part) {
                $part = trim($part);
                if ($part !== '' && !in_array($part, $parts, true)) {
                    $parts[] = $part;
                }
            }
        }

        return $parts;
    }

    /**
     * @param list<string> $additions
     */
    public static function merge(string $existing, array $additions): string
    {
        return implode(',', self::parse([$existing, ...$additions]));
    }

    /**
     * @param list<string> $entries
     */
    public static function containsAll(string $existing, array $entries): bool
    {
        return array_diff($entries, self::parse($existing)) === [];
    }
}
