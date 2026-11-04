<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\EventListener;

use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * "Seite veroeffentlichen" im Seitenmodul, im Visual Editor und im EditorialFlow-Board (EXT:editorial_flow), sobald der Redakteur in einem Workspace ist.
 * Immer sichtbar statt nur bei Aenderungen: Der Visual Editor speichert ohne Neuladen, der Knopf kaeme sonst zu spaet.
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
    ) {
    }

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        $request = $event->getRequest();
        $module = $request->getAttribute('module');
        $pageId = (int)($request->getQueryParams()['id'] ?? 0);
        $modules = $this->packageManager->isPackageActive('editorial_flow')
            ? [...self::MODULES, self::EDITORIAL_FLOW_MODULE]
            : self::MODULES;
        if (!$module instanceof ModuleInterface || !in_array($module->getIdentifier(), $modules, true) || $pageId <= 0
            || (int)($GLOBALS['BE_USER']->workspace ?? 0) <= 0
        ) {
            return;
        }
        $button = $this->componentFactory->createLinkButton()
            ->setHref((string)$this->uriBuilder->buildUriFromRoute('publish_page_button', [
                'id' => $pageId,
                'returnUrl' => $request->getAttribute('normalizedParams')?->getRequestUri(),
            ]))
            ->setTitle('Seite veroeffentlichen')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-cloud-upload', IconSize::SMALL));
        $buttons = $event->getButtons();
        $buttons[ButtonBar::BUTTON_POSITION_LEFT][0][] = $button;
        $event->setButtons($buttons);
    }
}
