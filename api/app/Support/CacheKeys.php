<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Every Redis key the application writes is minted here.
 *
 * Cache invalidation is only trustworthy if the code that writes a key and the
 * code that forgets it agree on the exact string. Centralising key construction
 * removes the largest source of stale-cache bugs.
 */
final class CacheKeys
{
    public const CATEGORY_LIST = 'categories:all';

    /**
     * Version counter for the product-listing namespace.
     *
     * Redis has no efficient wildcard delete, and cache tags are unavailable on
     * the plain redis store. Instead every listing key embeds a version number;
     * incrementing the version orphans all previously cached listings at once
     * and they fall out naturally when their TTL expires.
     */
    public const PRODUCT_INDEX_VERSION = 'products:index:version';

    public static function product(int $productId): string
    {
        return "products:show:{$productId}";
    }

    public static function productRating(int $productId): string
    {
        return "products:rating:{$productId}";
    }

    /**
     * Product listings are cached per unique filter combination. The filter set
     * is hashed so key length stays bounded no matter how many filters arrive.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function productIndex(array $filters, int $version): string
    {
        ksort($filters);

        return sprintf(
            'products:index:v%d:%s',
            $version,
            md5(json_encode($filters, JSON_THROW_ON_ERROR))
        );
    }
}
