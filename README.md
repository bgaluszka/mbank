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

try {
    $mbank = new \bgaluszka\Mbank\Mbank();
    $mbank->login('id', 'password');
    
    try {
        foreach (array('individual', 'business') as $profile) {
            $mbank->profile($profile);
        
            foreach ($mbank->accounts() as $account) {
                printf("%s %s %s\n", $account['name'], $account['value'], $account['currency']);
        
                foreach ($mbank->operations($account['iban']) as $operation) {
                	printf("%s %s %s\n", $operation['title'], $operation['value'], $operation['currency']);
                }
            }
        }
    } catch (Exception $e) {
        echo "Failed accessing profile: {$e->getMessage()}\n";
    }
    
    $mbank->logout();
    
} catch (Exception $e) {
	echo $e->getMessage();
}
```

### Search account

```php
<?php
// load the autoload.php from composer
require 'vendor/autoload.php';

try {
    $mbank = new \bgaluszka\Mbank\Mbank();
    $mbank->login('id', 'password');
    
    $operations = $mbank->operations('00 1111 2222 3333 4444 5555 6666', array(
        'SearchText' => 'Tytuł przelewu',
        'AmountFrom' => -10000.01,
        'AmountTo' => 10000.01,
        'periodFrom' => '01.01.2014',
        'periodTo' => '31.12.2014',
        // 1 page contains 25 operations, set it to 2 to get 50, 3 to 75 and so on
        'pagesCount' => 2,
    ));
    
    foreach ($operations as $operation) {
        printf("%s %s %s\n", $operation['title'], $operation['value'], $operation['currency']);
    }
    
    $mbank->logout();

} catch (Exception $e) {
	echo $e->getMessage();
}
```
