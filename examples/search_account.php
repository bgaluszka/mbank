<?php
/**
 * Search account
 */
require 'vendor/autoload.php';

use bgaluszka\Mbank\Mbank;

const MBANK_LOGIN = 'YOUR-LOGIN';
const MBANK_PASSWORD = 'YOUR-PASSWORD';
const MBANK_IBAN = 'ACCOUNT-IBAN';
const MBANK_DFP = 'ACCOUNT-DFP';
const MBANK_COOKIE8 = 'ACCOUNT-COOKIE8';

try {
	$mbank = new Mbank();

	// WARNING: Use constants, env variables or external configuration to
	// store your bank credentials. Do NOT pass your l/p directly to login()
	// method unless you want them to be exposed in case of any exception.
	$mbank->login(MBANK_LOGIN, MBANK_PASSWORD, MBANK_DFP, MBANK_COOKIE8);

	$criteria = array(
		'SearchText' => 'Tytuł przelewu',
		'AmountFrom' => -10000.01,
		'AmountTo'   => 10000.01,
		'periodFrom' => '01.01.2014',
		'periodTo'   => '31.12.2014',

		// 1 page contains 25 operations, set it to 2 to get 50, 3 to 75 and so on
		'pagesCount' => 2,
	);

	$operations = $mbank->operations(MBANK_IBAN, $criteria);

	foreach ($operations as $operation) {
		printf("%s %s %s\n", $operation['title'], $operation['value'], $operation['currency']);
	}

	$mbank->logout();

} catch (\RuntimeException $e) {
	echo "{$e->getMessage()}\n";
}
