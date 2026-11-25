<?php

declare(strict_types=1);

namespace LbHosting\Support;

/**
 * Test seam only: when LBH_TESTING is defined, a redirect throws this instead
 * of calling exit, so the offline suite can assert where a client was sent.
 * Production never defines LBH_TESTING and always exits after the header.
 */
final class RedirectSignal extends \RuntimeException
{
    public string $url;

    public function __construct(string $url)
    {
        parent::__construct('redirect: ' . $url);
        $this->url = $url;
    }
}
