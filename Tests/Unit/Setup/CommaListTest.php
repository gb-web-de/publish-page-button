<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\Setup;

use GbWebDe\PublishPageButton\Setup\CommaList;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CommaListTest extends UnitTestCase
{
    #[Test]
    public function parseTrimsDropsEmptiesAndDuplicates(): void
    {
        self::assertSame(['News', 'Sport'], CommaList::parse(' News , ,Sport,News'));
    }

    #[Test]
    public function parseAcceptsARepeatedOption(): void
    {
        self::assertSame(['News', 'Sport', 'Kultur'], CommaList::parse(['News,Sport', 'Kultur']));
    }

    /**
     * Darum gibt es die Klasse: Das Setup ergaenzt die Modulliste einer Gruppe und darf nie ein Modul
     * verlieren, das der Integrator ihr gegeben hat.
     */
    #[Test]
    public function mergeKeepsEverythingThatWasThere(): void
    {
        self::assertSame('records,media_management,web_layout', CommaList::merge('records,media_management', ['web_layout', 'records']));
    }

    #[Test]
    public function mergeIntoAnEmptyListHasNoLeadingComma(): void
    {
        self::assertSame('be_groups_3', CommaList::merge('', ['be_groups_3']));
    }

    #[Test]
    public function containsAllComparesWholeEntriesNotSubstrings(): void
    {
        self::assertTrue(CommaList::containsAll('be_groups_3,be_users_1', ['be_users_1']));
        self::assertFalse(CommaList::containsAll('be_groups_13', ['be_groups_1']), 'be_groups_1 besitzt nichts, nur weil be_groups_13 es tut');
    }
}
