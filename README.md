Library for accessing mBank PL transaction service
--------------------------------------------------

Suitable for checking for new transactions. Implemented methods:

* login
* list accounts
* list operations (last ~14 days)
* logout

Requirements
------------

* PHP 5.3 or higher
* [cURL](http://www.php.net/manual/book.curl.php) extension

Installation
------------

Install library from composer:

```json
{
    "require": {
        "bgaluszka/mbank": "dev-master"
    }
}
```

Example usage
-------------

```php
<?php
// load the autoload.php from composer
require 'vendor/autoload.php';

$mbank = new \bgaluszka\Mbank\Mbank();
$mbank->login('id', 'password');
$mbank->profile('individual');

foreach ($mbank->accounts() as $account) {
    echo "{$account['name']} {$account['value']} {$account['currency']}\n";

    foreach ($mbank->operations($account['iban']) as $operation) {
        echo "{$operation['description']} {$operation['value']} {$account['currency']}\n";
    }
}

$mbank->logout();
```
