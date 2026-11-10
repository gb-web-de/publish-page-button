<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\EventListener;

use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * "Seite veroeffentlichen" im Seitenmodul, im Visual Editor und im EditorialFlow-Board (EXT:editorial_flow)
 * sowie "Datensatz veroeffentlichen" in der FormEngine (record_edit) fuer alle workspace-faehigen Tabellen.
 */
#[AsEventListener(identifier: 'publish-page-button/button')]
final readonly class PublishPageButton
{
    private const array MODULES = ['web_layout', 'web_edit'];
    private const string EDITORIAL_FLOW_MODULE = 'web_editorialflow';

    public function __construct(
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
        private UriBuilder $uriBuilder,
        private PackageManager $packageManager,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {
    }

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        $workspace = (int)($GLOBALS['BE_USER']->workspace ?? 0);
        if ($workspace <= 0) {
            return;
        }

        $request = $event->getRequest();
        $returnUrl = $request->getAttribute('normalizedParams')?->getRequestUri();

        // 1. Page module, Visual Editor or EditorialFlow
        $module = $request->getAttribute('module');
        $pageId = (int)($request->getQueryParams()['id'] ?? 0);
        $modules = $this->packageManager->isPackageActive('editorial_flow')
            ? [...self::MODULES, self::EDITORIAL_FLOW_MODULE]
            : self::MODULES;

        if ($module instanceof ModuleInterface && in_array($module->getIdentifier(), $modules, true) && $pageId > 0) {
            $button = $this->componentFactory->createLinkButton()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute('publish_page_button', [
                    'id' => $pageId,
                    'returnUrl' => $returnUrl,
                ]))
                ->setTitle('Seite veroeffentlichen')
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-cloud-upload', IconSize::SMALL));
            $buttons = $event->getButtons();
            $buttons[ButtonBar::BUTTON_POSITION_LEFT][0][] = $button;
            $event->setButtons($buttons);
            return;
        }

        // 2. FormEngine (record_edit) for workspace-aware tables
        $route = $request->getAttributes()['route'] ?? null;
        $routeIdentifier = $route instanceof Route ? $route->getOption('_identifier') : '';
        $edit = $request->getQueryParams()['edit'] ?? [];

        if (($routeIdentifier === 'record_edit' || !empty($edit)) && is_array($edit)) {
            $table = '';
            $recordId = 0;
            foreach ($edit as $t => $records) {
                if (is_array($records)) {
                    foreach ($records as $rId => $cmd) {
                        if (is_numeric($rId) && (int)$rId > 0 && $cmd === 'edit') {
                            $table = (string)$t;
                            $recordId = (int)$rId;
                            break 2;
                        }
                    }
                }
            }

            if ($table !== '' && $recordId > 0 && $this->tcaSchemaFactory->has($table) && $this->tcaSchemaFactory->get($table)->isWorkspaceAware()) {
                $title = $table === 'pages' ? 'Seite veroeffentlichen' : 'Datensatz veroeffentlichen';
                $button = $this->componentFactory->createLinkButton()
                    ->setHref((string)$this->uriBuilder->buildUriFromRoute('publish_page_button', [
                        'table' => $table,
                        'id' => $recordId,
                        'returnUrl' => $returnUrl,
                    ]))
                    ->setTitle($title)
                    ->setShowLabelText(true)
                    ->setIcon($this->iconFactory->getIcon('actions-cloud-upload', IconSize::SMALL));

                $buttons = $event->getButtons();
                $buttons[ButtonBar::BUTTON_POSITION_LEFT][3][] = $button;
                $event->setButtons($buttons);
            }
        }
    }
}
