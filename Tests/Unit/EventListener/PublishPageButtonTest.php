<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\EventListener;

use GbWebDe\PublishPageButton\EventListener\PublishPageButton;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PublishPageButtonTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{string, int, int, bool, bool}>
     */
    public static function cases(): iterable
    {
        yield 'Seitenmodul' => ['web_layout', 5, 1, false, true];
        yield 'Visual Editor' => ['web_edit', 5, 1, false, true];
        yield 'EditorialFlow aktiv' => ['web_editorialflow', 5, 1, true, true];
        yield 'EditorialFlow nicht aktiv' => ['web_editorialflow', 5, 1, false, false];
        yield 'anderes Modul' => ['web_list', 5, 1, true, false];
        yield 'keine Seite' => ['web_layout', 0, 1, false, false];
        yield 'Live-Workspace' => ['web_layout', 5, 0, false, false];
    }

    #[Test]
    #[DataProvider('cases')]
    public function addsButtonOnlyWhereItBelongs(string $module, int $pageId, int $workspace, bool $editorialFlowActive, bool $expected): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->workspace = $workspace;
        $GLOBALS['BE_USER'] = $backendUser;

        $moduleMock = $this->createMock(ModuleInterface::class);
        $moduleMock->method('getIdentifier')->willReturn($module);
        $request = (new ServerRequest('https://example.org/typo3/module'))
            ->withQueryParams(['id' => $pageId])
            ->withAttribute('module', $moduleMock)
            ->withAttribute('normalizedParams', $this->createMock(NormalizedParams::class));

        $componentFactory = $this->createMock(ComponentFactory::class);
        $componentFactory->method('createLinkButton')->willReturnCallback(static fn (): LinkButton => new LinkButton());
        $iconFactory = $this->createMock(IconFactory::class);
        $iconFactory->method('getIcon')->willReturn($this->createMock(Icon::class));
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/typo3/publish-page-button/publish'));
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('isPackageActive')->with('editorial_flow')->willReturn($editorialFlowActive);

        $event = new ModifyButtonBarEvent([], $this->createMock(ButtonBar::class), $request);
        (new PublishPageButton($componentFactory, $iconFactory, $uriBuilder, $packageManager))($event);

        $buttons = $event->getButtons()[ButtonBar::BUTTON_POSITION_LEFT][0] ?? [];
        self::assertCount($expected ? 1 : 0, $buttons);
    }
}
