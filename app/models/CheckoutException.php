<?php

declare(strict_types=1);

namespace App\Models;

/**
 * An order action was refused for a reason the user can act on:
 * checkout (empty cart, item unavailable, not enough stock, prices
 * changed) or admin status changes (invalid or stale transition).
 * Messages are safe to show on screen.
 */
final class CheckoutException extends \RuntimeException
{
    /** @param list<string> $messages */
    public function __construct(private readonly array $messages)
    {
        parent::__construct(implode(' ', $messages));
    }

    /** @return list<string> */
    public function messages(): array
    {
        return $this->messages;
    }
}
