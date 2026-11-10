<?php

declare(strict_types=1);

namespace GbWebDe\PublishPageButton\Setup;

/**
 * Wird geworfen, bevor irgendetwas geschrieben ist. Die Meldung richtet sich an den Menschen an der
 * Kommandozeile: Sie nennt die Option und was stattdessen einzugeben ist.
 */
final class InvalidSetupOptions extends \InvalidArgumentException
{
}
