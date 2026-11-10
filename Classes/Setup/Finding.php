<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Setup;

/**
 * Ein Befund von `publishpage:check`, mit dem, was dagegen hilft.
 */
final class Finding
{
    public const string OK = 'ok';
    public const string INFO = 'info';
    public const string WARNING = 'warning';
    public const string ERROR = 'error';

    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly string $hint = '',
    ) {
    }
}
