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

$mbank = new \Mbank\Mbank();
// not required but recommended
// you can obtain certs from http://curl.haxx.se/docs/caextract.html
$mbank->setopt(array(
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_CAINFO => dirname(__FILE__) . '/vendor/bgaluszka/mbank/crt/cacert.pem',
));
$mbank->login('id', 'password');

foreach ($mbank->accounts() as $account) {
    echo "{$account['name']} {$account['value']} {$account['currency']}\n";

    foreach ($mbank->operations($account) as $operation) {
        echo "{$operation['type']} {$operation['value']} {$operation['currency']}\n";
    }
}

$mbank->logout();
```
