## Library for accessing mBank PL transaction service

Suitable for checking for new transactions. Implemented methods:

* login
* list accounts
* list recent operations
* logout

## Requirements

* PHP 5.3 or higher
* [cURL](http://www.php.net/manual/book.curl.php) extension

## Installation

Install library from composer:

```json
{
    "require": {
        "bgaluszka/mbank": "dev-master"
    }
}
```

## Example usage

### Recent operations for all accounts

```php
<?php
// load the autoload.php from composer
require 'vendor/autoload.php';

$mbank = new \bgaluszka\Mbank\Mbank();
$mbank->login('id', 'password');

foreach (array('individual', 'business') as $profile) {
    $mbank->profile($profile);

    foreach ($mbank->accounts() as $account) {
        echo "{$account['name']} {$account['value']} {$account['currency']}\n";

        foreach ($mbank->operations($account['iban']) as $operation) {
            echo "{$operation['title']} {$operation['value']} {$operation['currency']}\n";
        }
    }
}

$mbank->logout();
```

### Search account

```php
<?php
// load the autoload.php from composer
require 'vendor/autoload.php';

$mbank = new \bgaluszka\Mbank\Mbank();
$mbank->login('id', 'password');

$operations = $mbank->operations('00 1111 2222 3333 4444 5555 6666', array(
    'AmountFrom' => -10000.01,
    'AmountTo' => 10000.01,
    'periodFrom' => '01.01.2014',
    'periodTo' => '31.12.2014',
    // 1 page contains about 25 operations, set it to 2 to get 50, 3 to 75 and so on
    'pagesCount' => 2,
));

foreach ($operations as $operation) {
    echo "{$operation['title']} {$operation['value']} {$operation['currency']}\n";
}

$mbank->logout();
```
