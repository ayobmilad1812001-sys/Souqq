<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The category list is small, read on nearly every catalogue request and
 * changes rarely -- the textbook case for a cached read.
 */
final readonly class CategoryService
{
    public function __construct(private CatalogCache $cache) {}

    /** @return Collection<int, Category> */
    public function all(): Collection
    {
        return $this->cache->rememberCategories(
            static fn (): Collection => Category::query()
                ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Category
    {
        return Category::query()->create([
            'name' => $attributes['name'],
            'slug' => $attributes['slug'] ?? $this->uniqueSlug($attributes['name']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Category $category, array $attributes): Category
    {
        if (isset($attributes['name']) && ! isset($attributes['slug'])) {
            // Renaming without an explicit slug keeps the old slug so existing
            // catalogue links and bookmarks do not break.
            unset($attributes['slug']);
        }

        $category->update($attributes);

        return $category->fresh();
    }

    /**
     * Categories with products cannot be deleted: the FK is restrictive, and
     * silently reassigning a sellers products to "Uncategorised" would be a
     * surprising side effect. The caller gets a clear 409 instead.
     */
    public function delete(Category $category): bool
    {
        if ($category->products()->exists()) {
            return false;
        }

        $category->delete();

        return true;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Category::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
