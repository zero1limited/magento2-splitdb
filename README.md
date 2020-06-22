# magento2-splitdb
Ability to split database traffic to 1 or more readers. Allowing you to take advantage of an AWS reader endpoint for example.

## Installation
These steps are intended to be carried out in a staging/development environment. 
If you don't have, look at [MDOQ](https://www.mdoq.io) which can provide multiple development environments quickly and cheaply.

1. `composer require zero1/magento2-splitdb`
2. `php bin/magento module:enable Zero1_SplitDb`
3. `php bin/magento setup:upgrade`
4. `php bin/magento deploy:mode:set production`

## Configuration
To use separate endpoints for reading and writing to the database. You need to configure the endpoints in `app/etc/env.php`

Before
```php
'db' => [
    'table_prefix' => '',
    'connection' => [
        'default' => [
            'host' => '[DB_HOST]',
            'dbname' => '[DB_NAME]',
            'username' => '[DB_USERNAME]',
            'password' => '[DB_PASSWORD]',
            'model' => 'mysql4',
            'engine' => 'innodb',
            'initStatements' => 'SET NAMES utf8;',
            'active' => '1',
        ]
    ]
],
```
After
```php
'db' => [
    'table_prefix' => '',
    'connection' => [
        'default' => [
            'host' => '[DB_HOST]',
            'dbname' => '[DB_NAME]',
            'username' => '[DB_USERNAME]',
            'password' => '[DB_PASSWORD]',
            'model' => 'mysql4',
            'engine' => 'innodb',
            'initStatements' => 'SET NAMES utf8;',
            'active' => '1',
            'slaves' => [
                [
                    'host' => '[DB_READER_1_HOST]',
                    'username' => '[DB_READER_1_USERNAME]',
                    'password' => '[DB_READER_1_PASSWORD]',
                ],
                [
                    'host' => '[DB_READER_2_HOST]',
                    'username' => '[DB_READER_2_USERNAME]',
                    'password' => '[DB_READER_2_PASSWORD]',
                ]
            ]
        ]
    ]
],
```

- `slaves`: you can configure as many as you want. (Though with AWS you would only need to specify the single reader endpoint).
  The configuration for each slave is merged over the base config. So each slave will inherit all config values not defined.
  Each request will be locked to a single reader. (This is to stop multiple connections being opened)
  
**N.B:** Don't forget to flush cache after updating the `app/etc/env.php` file and clear opache.

## TODO
- Reader exclusions
  DB/Adapter/Pdo/MysqlProxy.php:114 this is required because Magento saves the model, then instantly tries to load it. 
  Need to locate the offending code or find another way around.

## Debug
To debug which queries are going to which endpoint while the platform is in production mode
add 
```php
'log_level' => \Monolog\Logger::INFO,
```
to the `default` node  
then run `tail -f var/log/system.log | grep Zero1_SplitDb` an request some pages.