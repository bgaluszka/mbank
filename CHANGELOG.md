* 2019-03-08
  * Added mt940_summary() feature (Marcin Orlowski)
  * Separated example scripts from README (Marcin Orlowski)
  * Main library code cleanup (Marcin Orlowski)
  * Added DOM extension to library dependencies (Marcin Orlowski)

* 2019-03-07
  * Added support for fetching MT940 reports (Marcin Orlowski)
  * Now throws \RuntimeException in place of too generic \Exception (Marcin Orlowski)
  * Replaced `[]` leftovers with plain `array()` to stay compatibile with PHP 5.3 (Marcin Orlowski)
  * Fixed `curl()` to always return `array` and made `load()` autoconvert imput array to string (Marcin Orlowski)