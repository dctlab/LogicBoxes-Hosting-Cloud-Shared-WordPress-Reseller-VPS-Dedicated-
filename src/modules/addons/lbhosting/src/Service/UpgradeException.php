<?php

declare(strict_types=1);

namespace LbHosting\Service;

/**
 * A change request that cannot go ahead for a reason the CLIENT may be told
 * (not available, invalid value, already in progress). Its message is safe to
 * show in the client area; any other exception gets a generic message there.
 */
final class UpgradeException extends \RuntimeException
{
}
