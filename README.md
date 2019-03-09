## Library for accessing mBank PL transaction service

 Suitable for checking for new transactions. Supported features:

 * list available accounts, 
 * list recent operations on the accounts,
 * MT940 support (requires mBank's MT940 reports to be enabled for the account)
   * MT940 file report fetch for specified date range
   * MT940 based transaction summary for specified date range

## Requirements

 * PHP 5.3 or higher
 * Extensions:
   * [cURL](http://www.php.net/manual/book.curl.php) 
   * [DOM](http://php.net/manual/en/book.dom.php) 

## Installation

Install library from composer:

    {
        "require": {
            "bgaluszka/mbank": "dev-master"
        }
    }

## Usage examples

For usage examples, please see scripts in [examples/](examples/) directory. 
