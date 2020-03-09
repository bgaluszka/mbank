<?php
/**
 * Recent operations for all accounts
 */

require 'vendor/autoload.php';

use bgaluszka\Mbank\Mbank;

const MBANK_LOGIN = 'YOUR-LOGIN';
const MBANK_PASSWORD = 'YOUR-PASSWORD';
const MBANK_DFP = 'ACCOUNT-DFP';
const MBANK_COOKIE8 = 'ACCOUNT-COOKIE8';

try {
	$mbank = new Mbank();

	// WARNING: Use constants, env variables or external configuration to
	// store your bank credentials. Do NOT pass your l/p directly to login()
	// method unless you want them to be exposed in case of any exception.
	$mbank->login(MBANK_LOGIN, MBANK_PASSWORD, MBANK_DFP, MBANK_COOKIE8);

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

} catch (\RuntimeException $e) {
	echo "{$e->getMessage()}\n";
}
