<?php

declare(strict_types=1);

use GbWebDe\PublishPageButton\Controller\PublishPageController;

return [
    // Backend-Routen pruefen das Token in der URL, ein fremder Link kann also nichts veroeffentlichen
    'publish_page_button' => [
        'path' => '/publish-page-button/publish',
        'target' => PublishPageController::class . '::publishAction',
    ],
];
