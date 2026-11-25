<?php

declare(strict_types=1);

namespace LbHosting\Support;

/** Tests only: thrown instead of echoing a standalone page and exiting. */
final class OutputSignal extends \RuntimeException
{
    public string $html;

    public function __construct(string $html)
    {
        parent::__construct('output');
        $this->html = $html;
    }
}
