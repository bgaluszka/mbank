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

## Usage examples

### Recent operations for all accounts

```php
<?php
// load the autoload.php from composer
require 'vendor/autoload.php';

const MBANK_LOGIN = 'YOUR-LOGIN';
const MBANK_PASSWORD = 'YOUR-PASSWORD';

try {
    $mbank = new \bgaluszka\Mbank\Mbank();
    
    // Use const or config. Do not pass l/p directly in login() call or
    // it will be exposed when unhandled exception happen 
   	$mbank->login(MBANK_LOGIN, MBANK_PASSWORD);
    
    try {
        foreach ($mbank->getAllAccountTypes() as $profile) {
            $mbank->profile($profile);
        
            foreach ($mbank->accounts() as $account) {
                printf("%s %s %s\n", $account['name'], $account['value'], $account['currency']);
        
                foreach ($mbank->operations($account['iban']) as $operation) {
                	printf("%s %s %s\n", $operation['title'], $operation['value'], $operation['currency']);
                }
            }
        }
    } catch (\Exception $e) {
        echo "Failed accessing profile: {$e->getMessage()}\n";
    }
    
    $mbank->logout();
    
} catch (\Exception $e) {
	echo "{$e->getMessage()}\n";
}
```

### Search account

```php
<?php
// load the autoload.php from composer
require 'vendor/autoload.php';

const MBANK_LOGIN = 'YOUR-LOGIN';
const MBANK_PASSWORD = 'YOUR-PASSWORD';
const MBANK_IBAN = 'ACCOUNT-IBAN';

try {
    $mbank = new \bgaluszka\Mbank\Mbank();
    
    // Use const or config. Do not pass l/p directly in login() call or
    // it will be exposed when unhandled exception happen 
   	$mbank->login(MBANK_LOGIN, MBANK_PASSWORD);
    
    $operations = $mbank->operations(MBANK_IBAN, array(
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

} catch (\Exception $e) {
	echo "{$e->getMessage()}\n";
}
```

### Export as CSV

```php
<?php
require_once 'vendor/autoload.php';

use bgaluszka\Mbank\Mbank;

const MBANK_LOGIN = 'YOUR-LOGIN';
const MBANK_PASSWORD = 'YOUR-PASSWORD';
const MBANK_IBAN = 'ACCOUNT-IBAN';

try {
	$mbank = new \bgaluszka\Mbank\Mbank();
	$mbank->login(MBANK_LOGIN, MBANK_PASSWORD);

	// all transaction since beginning of given month
	$csv = $mbank->export(MBANK_IBAN,
		
		// export criterias
		array(
			'daterange_from_day'   => '1',
			'daterange_from_month' => date('m'),
			'daterange_from_year'  => date('Y'),
			'daterange_to_day'     => date('d'),
			'daterange_to_month'   => date('m'),
			'daterange_to_year'    => date('Y'),

		    // we want to export incoming transfers only
		    'accoperlist_typefilter_group' => Mbank::TRANS_INCOMING,
		));

	$mbank->logout();

	// you probably want to convert that to UTF-8
	$csv = iconv('WINDOWS-1250', 'UTF-8', $csv);

    file_put_contents('mbank.csv', $csv);

} catch (\Exception $e) {
	echo "{$e->getMessage()}\n";
}
```