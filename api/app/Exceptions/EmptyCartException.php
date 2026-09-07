<?php

declare(strict_types=1);

namespace App\Exceptions;

final class EmptyCartException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Your cart is empty. Add items before placing an order.');
    }
}
