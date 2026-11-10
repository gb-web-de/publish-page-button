<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Tests\Unit\EventListener;

use GbWebDe\PublishPageButton\EventListener\PublishRecordListAction;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\RecordList\DatabaseRecordList;
use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ComponentGroup;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PublishRecordListActionTest extends UnitTestCase
{
    private ComponentFactory $componentFactory;
    private IconFactory $iconFactory;
    private UriBuilder $uriBuilder;
    private TcaSchemaFactory $tcaSchemaFactory;
    private PublishRecordListAction $subject;

    protected function setUp(): void
    {
        $this->resetSingletonInstances = true;
        parent::setUp();

        $this->componentFactory = $this->createMock(ComponentFactory::class);
        $this->componentFactory->method('createLinkButton')->willReturnCallback(static fn (): LinkButton => new LinkButton());
        $this->iconFactory = $this->createMock(IconFactory::class);
        $this->iconFactory->method('getIcon')->willReturn($this->createMock(Icon::class));
        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/typo3/publish-page-button/publish?table=tx_news&id=42'));
        $this->tcaSchemaFactory = $this->createMock(TcaSchemaFactory::class);

        $this->subject = new PublishRecordListAction(
            $this->componentFactory,
            $this->iconFactory,
            $this->uriBuilder,
            $this->tcaSchemaFactory,
        );
    }

    #[Test]
    public function doesNothingWhenUserIsInLiveWorkspace(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->workspace = 0;
        $GLOBALS['BE_USER'] = $backendUser;

        $record = $this->createMock(RecordInterface::class);
        $record->method('getMainType')->willReturn('tx_news_domain_model_news');
        $record->method('getUid')->willReturn(42);

        $primary = new ComponentGroup('primary');
        $secondary = new ComponentGroup('secondary');
        $request = new ServerRequest('https://example.org/typo3/module/web/list');
        $event = new ModifyRecordListRecordActionsEvent($primary, $secondary, $record, $this->createMock(DatabaseRecordList::class), $request);

        ($this->subject)($event);

        self::assertFalse($event->hasAction('publish_record'));
    }

    #[Test]
    public function doesNothingWhenTableIsNotWorkspaceAware(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->workspace = 1;
        $GLOBALS['BE_USER'] = $backendUser;

        $record = $this->createMock(RecordInterface::class);
        $record->method('getMainType')->willReturn('sys_log');
        $record->method('getUid')->willReturn(42);

        $this->tcaSchemaFactory->method('has')->with('sys_log')->willReturn(true);
        $schema = $this->createMock(TcaSchema::class);
        $schema->method('isWorkspaceAware')->willReturn(false);
        $this->tcaSchemaFactory->method('get')->with('sys_log')->willReturn($schema);

        $primary = new ComponentGroup('primary');
        $secondary = new ComponentGroup('secondary');
        $request = new ServerRequest('https://example.org/typo3/module/web/list');
        $event = new ModifyRecordListRecordActionsEvent($primary, $secondary, $record, $this->createMock(DatabaseRecordList::class), $request);

        ($this->subject)($event);

        self::assertFalse($event->hasAction('publish_record'));
    }

    #[Test]
    public function addsPublishActionForWorkspaceAwareRecord(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->workspace = 1;
        $GLOBALS['BE_USER'] = $backendUser;

        $record = $this->createMock(RecordInterface::class);
        $record->method('getMainType')->willReturn('tx_news_domain_model_news');
        $record->method('getUid')->willReturn(42);

        $this->tcaSchemaFactory->method('has')->with('tx_news_domain_model_news')->willReturn(true);
        $schema = $this->createMock(TcaSchema::class);
        $schema->method('isWorkspaceAware')->willReturn(true);
        $this->tcaSchemaFactory->method('get')->with('tx_news_domain_model_news')->willReturn($schema);

        $normalizedParams = $this->createMock(NormalizedParams::class);
        $normalizedParams->method('getRequestUri')->willReturn('/typo3/module/web/list?id=12');

        $primary = new ComponentGroup('primary');
        $editButton = new LinkButton();
        $primary->add('edit', $editButton);
        $secondary = new ComponentGroup('secondary');
        $request = (new ServerRequest('https://example.org/typo3/module/web/list'))
            ->withAttribute('normalizedParams', $normalizedParams);
        $event = new ModifyRecordListRecordActionsEvent($primary, $secondary, $record, $this->createMock(DatabaseRecordList::class), $request);

        ($this->subject)($event);

        self::assertTrue($event->hasAction('publish_record', ActionGroup::primary));
        $action = $event->getAction('publish_record', ActionGroup::primary);
        self::assertInstanceOf(LinkButton::class, $action);
        self::assertSame('Publish record', $action->getTitle());
    }

    #[Test]
    public function setsCorrectTitleForPagesTable(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->workspace = 1;
        $GLOBALS['BE_USER'] = $backendUser;

        $record = $this->createMock(RecordInterface::class);
        $record->method('getMainType')->willReturn('pages');
        $record->method('getUid')->willReturn(5);

        $this->tcaSchemaFactory->method('has')->with('pages')->willReturn(true);
        $schema = $this->createMock(TcaSchema::class);
        $schema->method('isWorkspaceAware')->willReturn(true);
        $this->tcaSchemaFactory->method('get')->with('pages')->willReturn($schema);

        $primary = new ComponentGroup('primary');
        $secondary = new ComponentGroup('secondary');
        $request = new ServerRequest('https://example.org/typo3/module/web/list');
        $event = new ModifyRecordListRecordActionsEvent($primary, $secondary, $record, $this->createMock(DatabaseRecordList::class), $request);

        ($this->subject)($event);

        self::assertTrue($event->hasAction('publish_record', ActionGroup::primary));
        $action = $event->getAction('publish_record', ActionGroup::primary);
        self::assertInstanceOf(LinkButton::class, $action);
        self::assertSame('Publish page', $action->getTitle());
    }

    #[Test]
    public function addsPublishActionWithGermanLanguageService(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->workspace = 1;
        $GLOBALS['BE_USER'] = $backendUser;

        $lang = $this->createMock(\TYPO3\CMS\Core\Localization\LanguageService::class);
        $lang->method('sL')->willReturnCallback(static function (string $key): string {
            return match ($key) {
                'LLL:EXT:publish_page_button/Resources/Private/Language/locallang.xlf:button.publishRecord' => 'Datensatz veröffentlichen',
                'LLL:EXT:publish_page_button/Resources/Private/Language/locallang.xlf:button.publishPage' => 'Seite veröffentlichen',
                default => '',
            };
        });
        $GLOBALS['LANG'] = $lang;

        $record = $this->createMock(RecordInterface::class);
        $record->method('getMainType')->willReturn('tx_news');
        $record->method('getUid')->willReturn(42);

        $this->tcaSchemaFactory->method('has')->with('tx_news')->willReturn(true);
        $schema = $this->createMock(TcaSchema::class);
        $schema->method('isWorkspaceAware')->willReturn(true);
        $this->tcaSchemaFactory->method('get')->with('tx_news')->willReturn($schema);

        $primary = new ComponentGroup('primary');
        $secondary = new ComponentGroup('secondary');
        $request = new ServerRequest('https://example.org/typo3/module/web/list');
        $event = new ModifyRecordListRecordActionsEvent($primary, $secondary, $record, $this->createMock(DatabaseRecordList::class), $request);

        ($this->subject)($event);

        self::assertTrue($event->hasAction('publish_record', ActionGroup::primary));
        $action = $event->getAction('publish_record', ActionGroup::primary);
        self::assertInstanceOf(LinkButton::class, $action);
        self::assertSame('Datensatz veröffentlichen', $action->getTitle());
    }
}
