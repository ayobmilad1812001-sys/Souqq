<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Product;
use Symfony\Component\HttpFoundation\Response;

/**
 * Raised while holding a row lock on the product, so the reported
 * `available` figure is the authoritative committed value at that instant --
 * not a stale read the shopper saw minutes ago on the listing page.
 */
final class InsufficientStockException extends DomainException
{
    public function __construct(Product $product, int $requested, int $available)
    {
        $this->context = [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
            ],
            'requested' => $requested,
            'available' => $available,
        ];

        parent::__construct(sprintf(
            'Insufficient stock for [%s]. Requested %d, only %d available.',
            $product->name,
            $requested,
            $available
        ));
    }

    public function status(): int
    {
        // 409: the request was well-formed, but conflicts with current
        // inventory state. A retry with a smaller quantity may succeed.
        return Response::HTTP_CONFLICT;
    }
}
