<?php
/**
 * Fetch MT940 summary
 */
require_once 'vendor/autoload.php';

use bgaluszka\Mbank\Mbank;

//const MBANK_LOGIN = 'YOUR-LOGIN';
//const MBANK_PASSWORD = 'YOUR-PASSWORD';
//const MBANK_IBAN = 'ACCOUNT-IBAN';

try {
	$mbank = new \bgaluszka\Mbank\Mbank();

	// WARNING: Use constants, env variables or external configuration to
	// store your bank credentials. Do NOT pass your l/p directly to login()
	// method unless you want them to be exposed in case of any exception.
	$mbank->login(MBANK_LOGIN, MBANK_PASSWORD);

	// Returns summary of operations between start and end date. To get summary
	// for just a single day, omit $endDate (or pass null)
	$startDate = '12.01.2019';
	$endDate = '21.01.2019';
	$summary = $mbank->mt940_summary(MBANK_IBAN, $startDate, $endDate);

	$mbank->logout();

	print_r($summary);

} catch (\RuntimeException $e) {
	echo "{$e->getMessage()}\n";
}