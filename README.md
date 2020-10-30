# Aggregate (WIP)

Laravel Eloquent allows you to query the count of a relationship using `withCount`. Aggregate extends Eloquent by adding `withSum`, `withAvg`, `withMin` and `withMax`.

This is based off the work in [dwightwatson/aggregate](https://github.com/dwightwatson/aggregate).
which is based off the work in [`laravel/framework#25319`](https://github.com/laravel/framework/pull/25319).

## Installation

## Usage

```php
Order::withSum('items.id as total_items', 'items.price', 'items.quantity');
```

```php
Order::withSum([
    'items.id as total_items', 
    'items.price',
    'items.quantity', 
    'items.quantity as downloadable_count' => function($query) {
        $query->where('is_downloadable', true);
    }, 
]);
```

```php
Order::withAggregate([
    'products as product_names' => \DB::raw('GROUP_CONCAT(name)'),
]);
```

```php
Order::withAggregate([
    'products' => 'count',
    'products.* AS count_total' => 'count',
    'products.comment AS count_commented' => 'count',
    'products.quantity' => 'sum',
    'products.price as custom_price_aggr' => function($query) {
        $query->select(\DB::raw('SUM(product_orders.quantity*product_orders.price)'));
    },
    'products.discount' => 'MAX',
    'products.quantity as quantity_list' => 'group_concat',
]);
```

### Testing

``` bash
vendor/bin/phpunit
```
