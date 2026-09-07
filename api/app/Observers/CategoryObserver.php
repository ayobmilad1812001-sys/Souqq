<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Category;
use App\Services\CatalogCache;

final readonly class CategoryObserver
{
    public function __construct(private CatalogCache $cache) {}

    public function saved(Category $category): void
    {
        $this->cache->forgetCategories();
    }

    public function deleted(Category $category): void
    {
        $this->cache->forgetCategories();
    }
}
