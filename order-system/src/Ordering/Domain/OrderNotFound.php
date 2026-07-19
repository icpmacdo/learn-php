<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * Unknown order id — or an order that belongs to ANOTHER customer: the
 * handlers throw the same exception for both, so existence is never leaked
 * across customers (part 2's existence-hiding policy; -> 404).
 */
final class OrderNotFound extends \DomainException
{
    public static function withId(OrderId $id): self
    {
        return new self(sprintf('Order %s not found.', $id->value));
    }
}
