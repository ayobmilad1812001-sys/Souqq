<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for business-rule violations.
 *
 * These are expected outcomes, not bugs: a shopper racing another shopper for
 * the last unit is normal marketplace behaviour. Each subclass declares the
 * HTTP status it maps to and any machine-readable context the client needs, and
 * the handler registered in bootstrap/app.php renders them uniformly. Services
 * can therefore fail loudly (aborting the surrounding DB transaction) without
 * any controller needing a try/catch.
 */
abstract class DomainException extends RuntimeException
{
    /** @var array<string, mixed> */
    protected array $context = [];

    public function status(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
