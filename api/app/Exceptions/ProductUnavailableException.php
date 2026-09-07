<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Product;

/**
 * Raised when a cart references a product that has since been deactivated or
 * deleted by its seller. Checked inside the order transaction so a stale cart
 * can never turn into an order for an unpublished product.
 */
final class ProductUnavailableException extends DomainException
{
    public function __construct(Product $product)
    {
        $this->context = [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
            ],
        ];

        parent::__construct("The product [{$product->name}] is no longer available for purchase.");
    }
}
