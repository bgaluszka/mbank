<?php
/**
 * Export as CSV
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

	// export criteria
	$criteria = array(
		'daterange_from_day'           => '1',
		'daterange_from_month'         => date('m'),
		'daterange_from_year'          => date('Y'),
		'daterange_to_day'             => date('d'),
		'daterange_to_month'           => date('m'),
		'daterange_to_year'            => date('Y'),

		// we want to export incoming transfers only
		'accoperlist_typefilter_group' => Mbank::TRANS_INCOMING,
	);
	// all transaction since beginning of given month
	$csv = $mbank->export(MBANK_IBAN, $criteria);

	$mbank->logout();

	// you probably want to convert that to UTF-8
	$csv = iconv('WINDOWS-1250', 'UTF-8', $csv);

	file_put_contents('mbank.csv', $csv);

} catch (\RuntimeException $e) {
	echo "{$e->getMessage()}\n";
}
