<?php

declare(strict_types=1);

namespace LbHosting\Api;

/**
 * Raised for any LogicBoxes API failure: transport error, non-JSON body, or an
 * error payload. The message is always safe to show an admin (it never
 * contains the API key); lifecycle code decides whether a client sees it.
 */
class ApiException extends \RuntimeException
{
    /** @var array<string,mixed>|null */
    private ?array $payload;

    private int $httpStatus;

    /** @param array<string,mixed>|null $payload */
    public function __construct(string $message, int $httpStatus = 0, ?array $payload = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
        $this->payload = $payload;
        $this->httpStatus = $httpStatus;
    }

    /** @return array<string,mixed>|null */
    public function payload(): ?array
    {
        return $this->payload;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** True when LogicBoxes said "no such record" (customer/order lookups). */
    public function isNotFound(): bool
    {
        if ($this->httpStatus === 404) {
            return true;
        }
        // Normalise curly apostrophes so "doesn't" / "doesn’t" match alike.
        $msg = strtolower(str_replace(["\u{2019}", '`'], "'", $this->getMessage()));

        return str_contains($msg, 'not found')
            || str_contains($msg, 'no entity found')
            || str_contains($msg, 'no order')
            || str_contains($msg, 'does not exist')
            || str_contains($msg, "doesn't exist")
            || str_contains($msg, 'doesnt exist')
            || str_contains($msg, 'invalid customer')
            || str_contains($msg, 'no orders found')
            || str_contains($msg, 'no record');
    }
}
