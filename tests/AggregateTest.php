<?php

namespace Watson\Aggregate\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;

class AggregateTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        DB::table('orders')->insert([
            'reference' => 'ref_1',
        ]);

        DB::table('product_orders')->insert([
            ['name' => 'Product 1', 'quantity' => 1, 'price' => '1500', 'order_id' => 1, 'comment' => null, 'discount' => 0],
            ['name' => 'Product 2', 'quantity' => 2, 'price' => '1000', 'order_id' => 1, 'comment' => 'foo bar', 'discount' => 15],
            ['name' => 'Product 3', 'quantity' => 3, 'price' => '1200', 'order_id' => 1, 'comment' => null, 'discount' => 13],
            ['name' => 'Product 4', 'quantity' => 4, 'price' => '1900', 'order_id' => 1, 'comment' => 'comment', 'discount' => 0],
        ]);
    }

    // public function testWithCount()
    // {
    //     $actual = Order::withCounty('products')->first();

    //     $expected = DB::select(
    //         DB::raw('select (select count(*) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_count" from "orders"')
    //     )[0];

    //     $this->assertEquals($expected->products_count, $actual->products_count);
    // }



    // public function testWithCountWithColumn()
    // {
    //     $actual = Order::withCounty('products.comment')->first();

    //     $expected = DB::select(
    //         DB::raw('select (select count(comment) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_count_comment" from "orders"')
    //     )[0];

    //     $this->assertEquals($expected->products_count_comment, $actual->products_count_comment);
    // }

    public function testWithAggregate()
    {
        $actual = Order::withAggregate([
            'products.quantity' => 'group_concat'
        ])->first();

        $expected = DB::select(
            DB::raw('select (select group_concat(quantity) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_quantity_group_concat" from "orders"')
        )[0];

        $this->assertEquals($expected->products_quantity_group_concat, $actual->products_quantity_group_concat);
        $this->assertEquals('1,2,3,4', $actual->products_quantity_group_concat);
    }

    public function testWithAggregateWithAlias()
    {
        $actual = Order::withAggregate([
            'products.quantity as quantity_list' => 'group_concat'
        ])->first();

        $expected = DB::select(
            DB::raw('select (select group_concat(quantity) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "quantity_list" from "orders"')
        )[0];

        $this->assertEquals($expected->quantity_list, $actual->quantity_list);
        $this->assertEquals('1,2,3,4', $actual->quantity_list);
    }

    public function testWithAggregateForCountVariants()
    {
        $sqls = [];

        $sqls[] = Order::withAggregate([
            'products' => 'count',
        ])->toSql();

        $sqls[] = Order::withAggregate([
            'products.*' => 'count',
        ])->toSql();

        $sqls[] = Order::withAggregate([
            'products as products_count' => 'count',
        ])->toSql();

        $sqls[] = Order::withAggregate([
            'products.* as products_count' => 'count',
        ])->toSql();

        $this->assertCount(1, collect($sqls)->unique()->all());

    }

    public function testWithAggregateWithMixedStyles()
    {
        DB::enableQueryLog();

        $actual = Order::withAggregate([
            'products' => 'count', // no dot
            'products.* AS count_total' => 'count',
            'products.comment AS count_commented' => 'count',
            'products.quantity' => 'sum',
            'products.price as custom_price_aggr' => function($query) {
                $query->select(\DB::raw('SUM(product_orders.quantity*product_orders.price)'));
            },
            'products.discount' => 'MAX',
            'products.quantity as quantity_list' => 'group_concat',
        ])
        // ->dd()
        ->first();

        // dd($actual->toArray());

        $this->assertCount(1, DB::getQueryLog());

        $this->assertEquals('ref_1', $actual->reference);
        $this->assertEquals(4, $actual->count_total);
        $this->assertEquals(2, $actual->count_commented);
        $this->assertEquals(4, $actual->products_count);
        $this->assertEquals(10, $actual->products_quantity_sum);
        $this->assertEquals(14700, $actual->custom_price_aggr);
        $this->assertEquals(15, $actual->products_discount_max);
        $this->assertEquals('1,2,3,4', $actual->quantity_list);
    }

    public function testWithSum()
    {
        $actual = Order::withSum('products.quantity')
        ->first();

        $expected = DB::select(
            DB::raw('select (select sum(quantity) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_quantity_sum" from "orders"')
        )[0];

        $this->assertEquals($expected->products_quantity_sum, $actual->products_quantity_sum);
    }

    public function testWithSumAsList()
    {
        $actual = Order::withSum('products.id', 'products.quantity', 'products.price')
        ->first();

        // dd($actual->toArray());
        // dd($actual->products_quantity_sum);

        $this->assertEquals('ref_1', $actual->reference);
        $this->assertEquals(10, $actual->products_id_sum);
        $this->assertEquals(10, $actual->products_quantity_sum);
        $this->assertEquals(5600, $actual->products_price_sum);

    }

    public function testWithSumAsArray()
    {
        $actual = Order::withSum(['products.id', 'products.quantity', 'products.price'])
        ->first();

        // dd($actual->toArray());

        $this->assertEquals('ref_1', $actual->reference);
        $this->assertEquals(10, $actual->products_id_sum);
        $this->assertEquals(10, $actual->products_quantity_sum);
        $this->assertEquals(5600, $actual->products_price_sum);
    }

    public function testWithSumWithWhereConstraints()
    {
        $actual = Order::withSum([
            'products.price' => function($query) {
                $query->where('discount', '>', 0);
            },
        ])
        // ->dd()
        ->first();

        $this->assertEquals(2200, $actual->products_price_sum);
    }

    public function testWithSumWithCustomSelect()
    {
        $actual = Order::withSum([
            'products.price' => function($query) {
                $query->select(\db::raw('SUM(discount*price/100)'));
            },
        ])
        ->first();

        $this->assertEquals(306, $actual->products_price_sum);
    }

    public function testWithAvg()
    {
        $actual = Order::withAvg('products.price')->first();

        $this->assertEquals(1400, $actual->products_price_avg);
    }

    public function testWithAvgWithAlias()
    {
        $actual = Order::withAvg('products.price AS mean_price')->first();

        $this->assertEquals(1400, $actual->mean_price);
    }

    public function testWithAvgWithConstraints()
    {
        $actual = Order::withAvg([
            'products.price AS mean_price_with_discounts' => function($query) {
                $query->where('discount', '>', 0);
            },
        ])
        // ->dd()
        ->first();

        $this->assertEquals(1100, $actual->mean_price_with_discounts);
    }

    public function testWithMin()
    {
        $actual = Order::withMin('products.price')->first();

        $expected = DB::select(
            DB::raw('select (select min(price) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_price_min" from "orders"')
        )[0];

        $this->assertEquals($expected->products_price_min, $actual->products_price_min);
    }

    // public function testWithMax()
    // {
    //     $actual = Order::withMax('products.price')->first();

    //     $expected = DB::select(
    //         DB::raw('select (select max(price) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_max_price" from "orders"')
    //     )[0];

    //     $this->assertEquals($expected->products_max_price, $actual->products_max_price);
    // }

    // public function testWithArray()
    // {
    //     $actual = Order::withArray([
    //         'products.name',
    //         'products.id as product_ids',
    //     ])
    //     ->first();

    //     // dd($actual->toArray());

    //     $this->assertEqualsCanonicalizing(
    //         ['imac', 'galaxy s9', 'Apple Watch'],
    //         $actual->products_array_name
    //     );

    //     $this->assertEqualsCanonicalizing(
    //         [1, 2, 3],
    //         $actual->product_ids
    //     );
    // }

    // public function testWithMinAndAlias()
    // {
    //     $actual = Order::withMin('products.price as min_price')->first();

    //     $expected = DB::select(
    //         DB::raw('select (select min(price) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "min_price" from "orders"')
    //     )[0];

    //     $this->assertEquals($expected->min_price, $actual->min_price);
    // }

    // public function testWithMaxWithAliasWithWhere()
    // {
    //     $actual = Order::withMax(['products.price as higher_price' => function ($query) {
    //         $query->where('quantity', '>', 1);
    //     }])->first();

    //     $expected = DB::select(
    //         DB::raw('select (select max(price) from "product_orders" where "orders"."id" = "product_orders"."order_id" and "quantity" > 1) as "higher_price" from "orders"')
    //     )[0];

    //     $this->assertEquals($expected->higher_price, $actual->higher_price);
    // }

    // public function testWithSumPricesAndCountQ2uantityWithAliases()
    // {
    //     $actual = Order::withSum('products.price as order_price')->withSum('products.quantity as order_products_count')->withCount('products')->first();

    //     $expected = DB::select(
    //         DB::raw('select (select sum(price) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "order_price", (select sum(quantity) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "order_products_count", (select count(*) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_count" from "orders"')
    //     )[0];

    //     $this->assertEquals($expected->order_price, $actual->order_price);
    //     $this->assertEquals($expected->products_count, $actual->products_count);
    //     $this->assertEquals($expected->order_products_count, $actual->order_products_count);
    // }

    // public function testWithMultiple()
    // {
    //     $actual = Order::withSum([
    //         'products.price',
    //         'products.quantity as total_quantity',
    //         'products.price as special_sum' => function($query) {
    //             $query->where('name', 'LIKE', '%apple%');
    //         },
    //     ])
    //     ->withSum('products.quantity as order_products_count')
    //     ->withCount('products')
    //     ->first();

    //     // $expected = DB::select(
    //     //     DB::raw(...)
    //     // )[0];

    //     // $this->assertEquals($expected->products_price_sum, $actual->order_price);
    //     // $this->assertEquals($expected->total_quantity, $actual->products_count);
    //     // $this->assertEquals($expected->order_products_count, $actual->order_products_count);
    //     $this->assertEquals(3700, $actual->products_sum_price);
    //     $this->assertEquals(6, $actual->total_quantity);
    //     $this->assertEquals(1200, $actual->special_sum);
    //     $this->assertEquals(6, $actual->order_products_count);
    //     $this->assertEquals(3, $actual->products_count);
    // }

    // public function testWithAggregateWithRaw()
    // {
    //     $actual = Order::withAggregate(
    //         'products as total_cost',
    //         DB::raw('SUM(quantity * price)')
    //     )
    //     ->first();

    //     $expected = DB::select(
    //         DB::raw('select (select SUM(quantity * price) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "total_cost" from "orders"')
    //     )[0];

    //     $this->assertEquals($expected->total_cost, $actual->total_cost);
    //     $this->assertEquals(7100, $actual->total_cost);
    // }

    // public function testWithAggregateWithMultipleRaws()
    // {
    //     $actual = Order::withAggregate(
    //         [
    //             'products as total_cost' => function($query) {
    //                 // $query->selectRaw('SUM(quantity * price)');
    //                 $query->select(\DB::raw('SUM(quantity * price)'));
    //             },
    //             // Caution: also the column gets lost:
    //             'products.foo_bar_stupid as max_row_discount' => function($query) {
    //                 $query->select(\DB::raw('MAX(quantity * price * discount/100)'))
    //                 ->where('discount', '>', 0);
    //             },
    //         ],
    //         '__THIS_AGGR_FUNC_GETS_LOST__' // TODO: this param $aggregate is usually something like "MIN" or "SUM"
    //     )
    //     ->first();

    //     $expected = DB::select(
    //         DB::raw('
    //             select
    //             (select SUM(quantity * price) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "total_cost"
    //             from "orders"')
    //     )[0];

    //     $this->assertEquals($expected->total_cost, $actual->total_cost);
    //     $this->assertEquals(7100, $actual->total_cost);

    //     $this->assertEquals(468, $actual->max_row_discount);
    // }
}

class Order extends Model
{
    protected $casts = [
        'products_array_name' => 'array',
        'product_ids' => 'array',
    ];

    public function products()
    {
        return $this->hasMany(ProductOrder::class, 'order_id');
    }
}

class ProductOrder extends Model
{
    //
}
