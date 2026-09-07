<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\OrderStatus;
use Symfony\Component\HttpFoundation\Response;

final class InvalidStatusTransitionException extends DomainException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        $this->context = [
            'from' => $from->value,
            'to' => $to->value,
            'allowed' => array_map(
                static fn (OrderStatus $status): string => $status->value,
                $from->allowedTransitions()
            ),
        ];

        parent::__construct("An order cannot move from [{$from->value}] to [{$to->value}].");
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
