<?php

/*
|--------------------------------------------------------------------------
| Marketplace domain configuration
|--------------------------------------------------------------------------
| Business rules that operations staff tune without touching code.
| Monetary values are kept as strings so they never pass through a float.
*/

return [
    'shipping' => [
        // Flat fee charged per order.
        'flat_rate' => (string) env('SHIPPING_FLAT_RATE', '15.00'),
        // Orders whose subtotal reaches this amount ship for free.
        'free_threshold' => (string) env('SHIPPING_FREE_THRESHOLD', '500.00'),
    ],

    'orders' => [
        // A customer may self-cancel only while the order is still
        // pending/confirmed AND within this many minutes of creation.
        'cancellation_window_minutes' => (int) env('ORDER_CANCELLATION_WINDOW_MINUTES', 60),
    ],

    'cart' => [
        'max_quantity_per_item' => (int) env('CART_MAX_QUANTITY_PER_ITEM', 100),
    ],

    'cache' => [
        // TTL in seconds for cached catalogue reads.
        'ttl' => (int) env('PRODUCT_CACHE_TTL', 600),
    ],
];
