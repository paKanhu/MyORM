# pakanhu/myorm

pakanhu/myorm is a simple and extensible PHP ORM library to work with MySQL database. It has an expressive query builder to read data with multi-table support.

## Installation

Install with composer `composer require pakanhu/myorm`

## Usage

First, create a "Database" instance. It creates a static database connection, which will be used by "Model" internally.

```PHP
new \paKanhu\MyOrm\Database(
    'db_host',
    'db_name',
    'db_username',
    'db_password',
    'db_timeZone'
);
```

Extend your model from "paKanhu\MyOrm\Model". Database column names should be in snake case and corresponding properties in your class should be in camel case.

```PHP
class Product extends \paKanhu\MyOrm\Model
{
    protected $id;          // DB Column Name - id (Primary Key)
    protected $sku;         // DB Column Name - sku
    protected $name;        // DB Column Name - name
    protected $price;       // DB Column Name - price
    protected $categoryId;  // DB Column Name - category_id
    protected $createdAt;   // DB Column Name - created_at

    // Table name is mandatory
    protected static $tableName = 'products';

    // Define if primary key in your table is different than "id"
    // protected static $primaryColumn = 'sku';

    /*
    Define if table contains fractional timestamp.
    This is required for workaround a PHP PDO bug while dealing with
    fractional timestamp in MySQL (TIMESTAMP(>0)).
    If it's defined a new property named 'createdAtFractional' will be
    created having fractional timestamp value.
    */
    // protected static $fractionalTimestamps = ['createdAt'];

    // Define to direct access of property outside of class
    // protected static $guarded = ['categoryId'];
}

// To get product with id 100
$product = Product::getById(100);

// To get product with unique sku SKU-123
$product = Product::getBySku('SKU-123', ['isUnique' => true]);

// To get all products of category id 10
$products = Product::getByCategoryId(10);
// is same as
$products = Product::getAll([
    'where' => [[
        'column' => 'categoryId',   // Property name
        'value' => 10,
        // 'condition' => '!=', // MySQL condition (Default is =)
    ]]
]);

// To get 5 new products of category id 10
$products = Product::getAll([
    'where' => [[
        'column' => 'categoryId',
        'value' => 10,
    ]],
    'orderBy' => [
        'column' => 'createdAt',
        'mode' => 'DESC'
    ],
    'limit' => [
        'rowCount' => 5,
        // 'offset' => 10,  // To set offset (Default is 0)
    ]
]);

// To get all products of category id 10 with category details
$products = Product::getByCategoryId(10, [
    'with' => [[
        'column' => 'categoryId',
        'class' => Category::class,
        'property' => 'category',

        // Required when table is not joined with primary key of other table
        // 'foreignColumn' => 'categoryUniqueId',

        // Required for one-to-many relationship (Default value - true)
        // 'isUnique' => false,

        // Complete 'filters' array can be passed as it's in 'getAll' method
        // 'filters' => [],
    ]]
]);
// echo $products[0]->category->name;

// To get all products of category id 10 with only category name
$products = Product::getByCategoryId(10, [
    'with' => [[
        'column' => 'categoryId',
        'class' => Category::class,
        'property' => 'category',
        'filters' => [
            // If 'select' filter is used, then result will be in array
            'select' => ['name']
        ],
    ]]
]);
// echo $products[0]->category['name'];

// To get all product names
$products = Product::getAll([
    'select' => 'name',
    'onlyValues' => true
]);
// echo $products[0];

// To get all products of category id 10, 11, 12 AND name starts with 'Apple'
$products = Product::getAll([
    'where' => [[
        'column' => 'categoryId',
        'condition' => 'IN'
        'value' => [10, 11, 12],
    ], [
        'column' => 'name',
        'condition' => 'LIKE',
        'value' => 'Apple%',
    ]]
]);

// To get all products of category id 10, 11, 12 OR name starts with 'Apple'
$products = Product::getAll([
    'whereOr' => [[
        'column' => 'categoryId',
        'condition' => 'IN'
        'value' => [10, 11, 12],
    ], [
        'column' => 'name',
        'condition' => 'LIKE',
        'value' => 'Apple%',
    ]]
]);

/*
To get all products of category id 10 and name starts with 'Apple' OR
category id 11 and name starts with 'Google'.
SQL Query Representation: WHERE (category_id = 10 AND name LIKE 'Apple%') OR
(category_id = 11 AND name LIKE 'Google%')
*/
$products = Product::getAll([
    'where' => [
        'operator' => 'OR',
        'operands' => [[
            'operator' => 'AND',
            'operands' => [[
                'column' => 'categoryId',
                'value' => 10
            ], [
                'column' => 'name',
                'condition' => 'LIKE',
                'value' => 'Apple%'
            ]]
        ], [
            'operator' => 'AND',
            'operands' => [[
                'column' => 'categoryId',
                'value' => 11
            ], [
                'column' => 'name',
                'condition' => 'LIKE',
                'value' => 'Google%'
            ]]
        ]]
    ]
]);
```
