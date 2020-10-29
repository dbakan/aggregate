<?php

namespace Watson\Aggregate\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class AggregateTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        DB::table('orders')->insert([
            'reference' => '12345678',
        ]);

        DB::table('product_orders')->insert([
            ['name' => 'imac', 'quantity' => '1', 'price' => '1500', 'order_id' => 1, 'comment' => null, 'discount' => 0],
            ['name' => 'galaxy s9', 'quantity' => '2', 'price' => '1000', 'order_id' => 1, 'comment' => 'foo bar', 'discount' => 15],
            ['name' => 'Apple Watch', 'quantity' => '3', 'price' => '1200', 'order_id' => 1, 'comment' => null, 'discount' => 13],
        ]);
    }

    public function testWithCount()
    {
        $actual = Order::withCounty('products')->first();

        $expected = DB::select(
            DB::raw('select (select count(*) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_count" from "orders"')
        )[0];

        $this->assertEquals($expected->products_count, $actual->products_count);
    }

    public function testWithCountWithColumn()
    {
        $actual = Order::withCounty('products.comment')->first();

        $expected = DB::select(
            DB::raw('select (select count(comment) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_count_comment" from "orders"')
        )[0];

        $this->assertEquals($expected->products_count_comment, $actual->products_count_comment);
    }

    public function testWithAggregate()
    {
        $actual = Order::withAggregate('products.quantity', 'group_concat')->first();

        $expected = DB::select(
            DB::raw('select (select group_concat(quantity) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_group_concat_quantity" from "orders"')
        )[0];

        $this->assertEquals($expected->products_group_concat_quantity, $actual->products_group_concat_quantity);
        $this->assertEquals('1,2,3', $actual->products_group_concat_quantity);
    }

    public function testWithSum()
    {
        $actual = Order::withSum('products.quantity')->first();

        $expected = DB::select(
            DB::raw('select (select sum(quantity) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_sum_quantity" from "orders"')
        )[0];

        $this->assertEquals($expected->products_sum_quantity, $actual->products_sum_quantity);
    }

    public function testWithAvg()
    {
        $actual = Order::withAvg('products.price')->first();

        $expected = DB::select(
            DB::raw('select (select avg(price) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_avg_price" from "orders"')
        )[0];

        $this->assertEquals($expected->products_avg_price, $actual->products_avg_price);
    }

    public function testWithMin()
    {
        $actual = Order::withMin('products.price')->first();

        $expected = DB::select(
            DB::raw('select (select min(price) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_min_price" from "orders"')
        )[0];

        $this->assertEquals($expected->products_min_price, $actual->products_min_price);
    }

    public function testWithMax()
    {
        $actual = Order::withMax('products.price')->first();

        $expected = DB::select(
            DB::raw('select (select max(price) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_max_price" from "orders"')
        )[0];

        $this->assertEquals($expected->products_max_price, $actual->products_max_price);
    }

    public function testWithArray()
    {
        $actual = Order::withArray([
            'products.name',
            'products.id as product_ids',
        ])
        ->first();

        // dd($actual->toArray());

        $this->assertEqualsCanonicalizing(
            ['imac', 'galaxy s9', 'Apple Watch'],
            $actual->products_array_name
        );

        $this->assertEqualsCanonicalizing(
            [1, 2, 3],
            $actual->product_ids
        );
    }

    public function testWithMinAndAlias()
    {
        $actual = Order::withMin('products.price as min_price')->first();

        $expected = DB::select(
            DB::raw('select (select min(price) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "min_price" from "orders"')
        )[0];

        $this->assertEquals($expected->min_price, $actual->min_price);
    }

    public function testWithMaxWithAliasWithWhere()
    {
        $actual = Order::withMax(['products.price as higher_price' => function ($query) {
            $query->where('quantity', '>', 1);
        }])->first();

        $expected = DB::select(
            DB::raw('select (select max(price) from "product_orders" where "orders"."id" = "product_orders"."order_id" and "quantity" > 1) as "higher_price" from "orders"')
        )[0];

        $this->assertEquals($expected->higher_price, $actual->higher_price);
    }

    public function testWithSumPricesAndCountQ2uantityWithAliases()
    {
        $actual = Order::withSum('products.price as order_price')->withSum('products.quantity as order_products_count')->withCount('products')->first();

        $expected = DB::select(
            DB::raw('select (select sum(price) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "order_price", (select sum(quantity) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "order_products_count", (select count(*) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "products_count" from "orders"')
        )[0];

        $this->assertEquals($expected->order_price, $actual->order_price);
        $this->assertEquals($expected->products_count, $actual->products_count);
        $this->assertEquals($expected->order_products_count, $actual->order_products_count);
    }

    public function testWithMultiple()
    {
        $actual = Order::withSum([
            'products.price',
            'products.quantity as total_quantity',
            'products.price as special_sum' => function($query) {
                $query->where('name', 'LIKE', '%apple%');
            },
        ])
        ->withSum('products.quantity as order_products_count')
        ->withCount('products')
        ->first();

        // $expected = DB::select(
        //     DB::raw(...)
        // )[0];

        // $this->assertEquals($expected->products_price_sum, $actual->order_price);
        // $this->assertEquals($expected->total_quantity, $actual->products_count);
        // $this->assertEquals($expected->order_products_count, $actual->order_products_count);
        $this->assertEquals(3700, $actual->products_sum_price);
        $this->assertEquals(6, $actual->total_quantity);
        $this->assertEquals(1200, $actual->special_sum);
        $this->assertEquals(6, $actual->order_products_count);
        $this->assertEquals(3, $actual->products_count);
    }

    public function testWithAggregateWithRaw()
    {
        $actual = Order::withAggregate(
            'products as total_cost',
            DB::raw('SUM(quantity * price)')
        )
        ->first();

        $expected = DB::select(
            DB::raw('select (select SUM(quantity * price) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "total_cost" from "orders"')
        )[0];

        $this->assertEquals($expected->total_cost, $actual->total_cost);
        $this->assertEquals(7100, $actual->total_cost);
    }

    public function testWithAggregateWithMultipleRaws()
    {
        $actual = Order::withAggregate(
            [
                'products as total_cost' => function($query) {
                    // $query->selectRaw('SUM(quantity * price)');
                    $query->select(\DB::raw('SUM(quantity * price)'));
                },
                // Caution: also the column gets lost:
                'products.foo_bar_stupid as max_row_discount' => function($query) {
                    $query->select(\DB::raw('MAX(quantity * price * discount/100)'))
                    ->where('discount', '>', 0);
                },
            ],
            '__THIS_AGGR_FUNC_GETS_LOST__' // TODO: this param $aggregate is usually something like "MIN" or "SUM"
        )
        ->first();

        $expected = DB::select(
            DB::raw('
                select
                (select SUM(quantity * price) from "product_orders" where "orders"."id" = "product_orders"."order_id") as "total_cost"
                from "orders"')
        )[0];

        $this->assertEquals($expected->total_cost, $actual->total_cost);
        $this->assertEquals(7100, $actual->total_cost);

        $this->assertEquals(468, $actual->max_row_discount);
    }
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
