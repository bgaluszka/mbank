## Library for accessing mBank PL transaction service

 Suitable for checking for new transactions. Supported features:

 * list available accounts, 
 * list recent operations on the accounts,
 * MT940 reports fetching (requires mBank's MT940 reports to be enabled for the account)

## Requirements

 * PHP 5.3 or higher
 * [cURL](http://www.php.net/manual/book.curl.php) extension

## Installation

Install library from composer:

    {
        "require": {
            "bgaluszka/mbank": "dev-master"
        }
    }

## Usage examples

For usage examples, please see scripts in [examples/](examples/) directory. 
