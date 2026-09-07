<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three actors described in the PRD. Backed by strings so the column stays
 * human-readable in the database, and so adding a role never renumbers rows.
 */
enum UserRole: string
{
    case Customer = 'customer';
    case Seller = 'seller';
    case Admin = 'admin';

    /** Roles allowed to own products. */
    public function canSell(): bool
    {
        return $this === self::Seller || $this === self::Admin;
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
