<?php
/**
 * Fetch MT940 report file
 */
require_once 'vendor/autoload.php';

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

	// All operations between start and end date. To get operations for
	// just one day, simply omit end date argument.
	$startDate = '12.01.2019';
	$endDate = '21.01.2019';
	$mt940 = $mbank->mt940_get(MBANK_IBAN, $startDate, $endDate);

	$mbank->logout();

	file_put_contents('mt940.txt', $mt940);

} catch (\RuntimeException $e) {
	echo "{$e->getMessage()}\n";
}
