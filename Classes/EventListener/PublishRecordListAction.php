<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * "Datensatz veroeffentlichen" in der Datensatzliste (RecordList / web_list)
 * fuer alle workspace-faehigen Tabellen.
 */
#[AsEventListener(identifier: 'publish-page-button/record-list-action')]
final readonly class PublishRecordListAction
{
    public function __construct(
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
        private UriBuilder $uriBuilder,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {
    }

    public function __invoke(ModifyRecordListRecordActionsEvent $event): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $workspace = $backendUser instanceof BackendUserAuthentication ? (int)$backendUser->workspace : 0;
        if ($workspace <= 0) {
            return;
        }

        $record = $event->getRecord();
        $table = $record->getMainType();
        $recordUid = $record->getUid();

        if ($recordUid <= 0 || !$this->tcaSchemaFactory->has($table) || !$this->tcaSchemaFactory->get($table)->isWorkspaceAware()) {
            return;
        }

        $request = $event->getRequest();
        $returnUrl = $request instanceof ServerRequestInterface
            ? ($request->getAttribute('normalizedParams')?->getRequestUri() ?? (string)$request->getUri())
            : (string)$request->getUri();

        $title = $table === 'pages'
            ? $this->translate('button.publishPage', 'Publish page')
            : $this->translate('button.publishRecord', 'Publish record');
        $button = $this->componentFactory->createLinkButton()
            ->setHref((string)$this->uriBuilder->buildUriFromRoute('publish_page_button', [
                'table' => $table,
                'id' => $recordUid,
                'returnUrl' => $returnUrl,
            ]))
            ->setTitle($title)
            ->setIcon($this->iconFactory->getIcon('actions-cloud-upload', IconSize::SMALL));

        $event->setAction($button, 'publish_record', ActionGroup::primary, after: 'edit');
    }

    private function translate(string $key, string $default): string
    {
        $lang = $GLOBALS['LANG'] ?? null;
        if ($lang instanceof LanguageService) {
            $translated = $lang->sL('LLL:EXT:publish_page_button/Resources/Private/Language/locallang.xlf:' . $key);
            if ($translated !== '') {
                return $translated;
            }
        }
        return $default;
    }
}
