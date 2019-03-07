<?php

namespace bgaluszka\Mbank;

/**
 * Library for accessing mBank (mbank.pl) transaction service
 *
 * Class Mbank
 *
 * @package bgaluszka\Mbank
 */
class Mbank
{
	const TRANS_ALL              = 'ALL000000'; // Wszystkie
	const TRANS_ARRIVED          = 'ABO000000'; // Uznania rachunku
	const TRANS_SENT             = 'CAR000000'; // Obciążenia rachunku
	const TRANS_OUTGOING         = 'TRO111000'; // Przelewy wychodzące
	const TRANS_INCOMING         = 'TRI111000'; // Przelewy przychodzące
	const TRANS_OWN              = 'TIH111000'; // Przelewy własne
	const TRANS_IRS              = 'TUS111000'; // Przelewy podatkowe
	const TRANS_ZUS              = 'TRZ101000'; // Przelewy do ZUS
	const TRANS_CARD_TRANSACTION = 'LDS100000'; // Operacje kartowe
	const TRANS_CASH_DEPOSIT     = 'CAI100000'; // Wpłaty gotówkowe
	const TRANS_CASH_WITHDRAWAL  = 'CAO100000'; // Wypłaty gotówkowe
	const TRANS_INTEREST         = 'INT000000'; // Kapitalizacja odsetek
	const TRANS_FEES             = 'COM100000'; // Prowizje i opłaty


	/**
	 * Corporate account type
	 */
	const ACCOUNT_TYPE_BUSINESS   = 'business';

	/**
	 * Individual's account type
	 */
	const ACCOUNT_TYPE_INDIVIDUAL = 'individual';



	/**
	 * Returns all known and supported account types. Note this does not mean
	 * you own all these types of accounts!
	 *
	 * @return array
	 */
	public function getAllAccountTypes()
	{
		return array(
			self::ACCOUNT_TYPE_INDIVIDUAL,
			self::ACCOUNT_TYPE_BUSINESS,
		);
	}

	/** @var resource */
    protected $curl;

    /** @var string */
    protected $tab = null;

    /** @var string|null */
    protected $token = null;

    /** @var \DOMDocument */
    protected $document;

    /** @var \DOMXPath */
    protected $xpath;

    /** @var array */
    protected $opts = array();

    /** @var string string */
    public $url = 'https://online.mbank.pl';


	/**
	 * Mbank constructor.
	 */
    public function __construct()
    {
        $this->curl = curl_init();

        $this->opts = array(
            CURLOPT_URL => null,
            CURLOPT_POST => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => dirname(dirname(dirname(__DIR__))) . '/crt/cacert.pem',
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1,
            // http://blog.volema.com/curl-rce.html
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            // http://stackoverflow.com/a/1490482
            CURLOPT_COOKIEJAR  => (PHP_OS === 'Windows') ? 'null' : '/dev/null',
        );

        $this->document = new \DOMDocument('1.0', 'UTF-8');
        $this->document->preserveWhiteSpace = false;
    }

    public function __destruct()
    {
        curl_close($this->curl);
    }

	/**
	 * Starts mBank session
	 *
	 * @param string $username
	 * @param string $password
	 *
	 * @return bool
	 *
	 * @throws \RuntimeException
	 */
    public function login($username, $password)
    {
        $response = $this->curl(array(
	        CURLOPT_URL => $this->url . '/pl/Login',
        ));

        $response = $this->curl(array(
	        CURLOPT_URL => $this->url . '/pl/Account/JsonLogin',
	        CURLOPT_POST => true,
	        CURLOPT_POSTFIELDS => array(
		        'UserName' => $username,
		        'Password' => $password,
		        'Seed' => '',
		        'Lang' => '',
	        ),
        ));

        if (empty($response['successful'])) {
            throw new \RuntimeException('login() failed');
        }

        $this->tab = $response['tabId'];
        $response = $this->curl(array(
	        CURLOPT_URL => $this->url . '/pl',
        ));

        $this->load($response);

        $this->token = $this->xpath->evaluate('string(//meta[@name="__AjaxRequestVerificationToken"]/@content)');

        return true;
    }

	/**
	 * @param string $profile
	 *
	 * @return mixed
	 *
	 * @throws \InvalidArgumentException
	 */
    public function profile($profile)
    {
        $profiles = array(
	        self::ACCOUNT_TYPE_INDIVIDUAL => 'I',
	        self::ACCOUNT_TYPE_BUSINESS   => 'F',
        );

        if (!array_key_exists($profile, $profiles)) {
            throw new \InvalidArgumentException('Invalid profile');
        }

	    return $this->curl(array(
		    CURLOPT_URL        => $this->url . '/pl/LoginMain/Account/JsonActivateProfile',
		    CURLOPT_POST => true,
		    CURLOPT_POSTFIELDS => array(
			    'profileCode' => $profiles[ $profile ],
		    ),
	    ));
    }

	/**
	 * Lists your accounts
	 *
	 * @return array
	 */
    public function accounts()
    {
	    /** @var array $response */
        $response = $this->curl(array(
	        CURLOPT_URL => $this->url . '/pl/Accounts/Accounts/List',
	        CURLOPT_POST => true,
	        CURLOPT_POSTFIELDS => array(),
	        CURLOPT_HTTPHEADER => array('X-Requested-With: XMLHttpRequest'),
        ));

        $accounts = array();

        foreach ($response['properties'] as $key => $property) {
            if (in_array($key, array('CurrentAccountsList', 'SavingAccountsList'))) {
            	/** @var array $property */
	            foreach ($property as $account) {
                    $accounts[$account['cID']] = array(
                        'profile' => $response['properties']['profile'],
                        'name' => $account['cProductName'],
                        'iban' => $account['cAccountNumberForDisp'],
                        'value' => $account['mAvailableBalance'],
                        'balance' => $account['mBalance'],
                        'currency' => $account['cCurrency'],
                    );
                }
            }
        }

        return $accounts;
    }

	/**
	 * Lists account operations
	 *
	 * @param string|null $iban optional IBAN of account to get operations for
	 * @param array       $criteria
	 *
	 * @return array
	 */
    public function operations($iban = null, array $criteria = array())
    {
        if ($iban) {
            $this->curl(array(
	            CURLOPT_URL => $this->url . '/pl/MyDesktop/Desktop/SetNavigationToAccountHistory',
	            CURLOPT_POST => true,
	            CURLOPT_POSTFIELDS => array(
		            'accountNumber' => $iban,
	            ),
	            CURLOPT_HTTPHEADER => array('X-Requested-With: XMLHttpRequest'),
            ));
        }

        $response = $this->curl(array(
	        CURLOPT_URL => $this->url . '/pl/Pfm/TransactionHistory',
        ));

        // http://php.net/manual/en/domdocument.loadhtml.php#95251
        $this->load('<?xml encoding="UTF-8">' . $response);

        if ($criteria) {
            $nodes = $this->xpath->query('//input[@name="ProductIds[]"][@checked]');

            $products = array();
            foreach ($nodes as $node) {
                $products[] = $this->xpath->evaluate('string(@value)', $node);
            }

            if (count($products) > 0) {
	            $criteria = json_encode(array_merge(array(
		            'ProductIds' => $products,
	            ), $criteria));

                $response = $this->curl(array(
	                CURLOPT_URL => $this->url . '/pl/Pfm/TransactionHistory/TransactionList',
	                CURLOPT_POST => true,
	                CURLOPT_POSTFIELDS => $criteria,
	                CURLOPT_HTTPHEADER => array(
		                'Content-Type: application/json',
		                'X-Requested-With: XMLHttpRequest',
	                ),
                ));

                $this->load('<?xml encoding="UTF-8">' . $response);
            }
        }

        $nodes = $this->xpath->query('//ul[@class="content-list-body"]/li');

        $operations = array();
        foreach ($nodes as $node) {
            $operations[] = array(
	            'id' => $this->xpath->evaluate('string(@data-id)', $node),
	            'type' => trim($this->xpath->evaluate('string(header/div[@class="column type"])', $node)),
	            'released' => date('Y-m-d', strtotime($this->xpath->evaluate('string(header/div[@class="column date"])', $node))),
	            'title' => trim($this->xpath->evaluate('string(header/div[@class="column description"]/span/span/@data-original-title)', $node)),
	            'category' => trim($this->xpath->evaluate('string(header/div[@class="column category"]/div[1]/span)', $node)),
	            'value' => self::toFloat($this->xpath->evaluate('string(header/div[@class="column amount"]/strong)', $node)),
	            'currency' => $this->xpath->evaluate('string(@data-currency)', $node),
            );
        }

        return $operations;
    }

	/**
	 * @param string $iban IBAN of account you want to export
	 * @param array  $params
	 *
	 * @return array
	 *
	 * @throws \InvalidArgumentException
	 */
    public function export($iban, array $params = array())
    {
        $response = $this->curl(array(
	        CURLOPT_URL => $this->url . '/csite/account_oper_list.aspx',
        ));

        $this->load($response);

        $form = $this->xpath->query('//form[@id="MainForm"]')->item(1);

        $nodes = $this->xpath->query('//select[@id="MenuAccountsCombo"]/option');

        $ibans = array();

        foreach ($nodes as $node) {
            $option = preg_replace('/[^\d]/', '', $node->textContent);
            $value = $this->xpath->evaluate('string(@value)', $node);

            $ibans[$option] = $value;
        }

        $iban = preg_replace('/[^\d]/', '', $iban);

        if (!isset($ibans[$iban])) {
            throw new \InvalidArgumentException('Invalid IBAN');
        }

        $params = array(
            //'__PARAMETERS' => $this->xpath->evaluate('string(.//input[@name="__PARAMETERS"]/@value)', $form),
            '__PARAMETERS' => $ibans[$iban],
            '__STATE' => $this->xpath->evaluate('string(.//input[@name="__STATE"]/@value)', $form),
            '__VIEWSTATE' => $this->xpath->evaluate('string(.//input[@name="__VIEWSTATE"]/@value)', $form),
            '__EVENTVALIDATION' => $this->xpath->evaluate('string(.//input[@name="__EVENTVALIDATION"]/@value)', $form),
        ) + $params;

        $params += array(
            'rangepanel_group' => 'daterange_radio',
            'daterange_from_day' => '1',
            'daterange_from_month' => date('m'),
            'daterange_from_year' => date('Y'),
            'daterange_to_day' => date('d'),
            'daterange_to_month' => date('m'),
            'daterange_to_year' => date('Y'),
            'accoperlist_typefilter_group' => 'ALL000000',
            'accoperlist_amountfilter_amountmin' => '',
            'accoperlist_amountfilter_amountmax' => '',
            'accoperlist_title_title' => '',
            'accoperlist_nameaddress_nameAddress' => '',
            'accoperlist_account_account' => '',
            'accoperlist_KS_KS' => '',
            'accoperlist_VS_VS' => '',
            'accoperlist_SS_SS' => '',
            'export_oper_history_check' => 'on',
            'export_oper_history_format' => 'CSV',
        );

        $accoperlist_typefilter_group = array(
	        self::TRANS_ALL => 'Wszystkie',
	        self::TRANS_ARRIVED => 'Uznania rachunku',
	        self::TRANS_SENT => 'Obciążenia rachunku',
	        self::TRANS_INCOMING => 'Przelewy przychodzące',
	        self::TRANS_OUTGOING => 'Przelewy wychodzące',
	        self::TRANS_OWN => 'Przelewy własne',
	        self::TRANS_IRS => 'Przelewy podatkowe',
	        self::TRANS_ZUS => 'Przelewy do ZUS',
	        self::TRANS_CARD_TRANSACTION => 'Operacje kartowe',
	        self::TRANS_CASH_DEPOSIT => 'Wpłaty gotówkowe',
	        self::TRANS_CASH_WITHDRAWAL => 'Wypłaty gotówkowe',
	        self::TRANS_INTEREST => 'Kapitalizacja odsetek',
	        self::TRANS_FEES => 'Prowizje i opłaty',
	        'CRE100000' => 'Operacje na kredycie',
	        'TDI111000' => 'Przelew z/na r-ek brokerski',
	        'TFX111000' => 'Transakcje walutowe',
	        'TRS000000' => 'Regularne oszczędzanie',

        );

        if (!isset($accoperlist_typefilter_group[$params['accoperlist_typefilter_group']])) {
            throw new \InvalidArgumentException('Invalid accoperlist_typefilter_group parameter');
        }

        $export_oper_history_format = array(
            'CSV' => 'CSV',
            'HTML' => 'HTML',
            'PDF' => 'PDF',
        );

        if (!isset($export_oper_history_format[$params['export_oper_history_format']])) {
            throw new \InvalidArgumentException('Invalid export_oper_history_format parameter');
        }

        return $this->curl(array(
	        CURLOPT_URL => $this->url . '/csite/printout_oper_list.aspx',
	        CURLOPT_POST => true,
	        CURLOPT_POSTFIELDS => http_build_query($params),
        ));
    }

	/**
	 * Returns your bank contact list
	 *
	 * @return array
	 */
    public function contacts()
    {
	    $response = $this->curl(array(
		    CURLOPT_URL        => $this->url . '/pl/AddressBook/Data/GetContactListForAddressBook',
		    CURLOPT_HTTPHEADER => array(
			    'Content-Type: application/json',
			    'X-Requested-With: XMLHttpRequest',
		    ),
	    ));
        $response = isset($response['records']) ? $response['records'] : [];

        return $response;
    }

	/**
	 * @param string $contact_id
	 *
	 * @return array
	 */
    public function contact_details($contact_id)
    {
        $params = array(
            'contactId' => $contact_id,
        );
        $params = json_encode($params);

	    $response = $this->curl(array(
		    CURLOPT_URL        => $this->url . '/pl/AddressBook/Data/GetContactDetails',
		    CURLOPT_POST => true,
		    CURLOPT_POSTFIELDS => $params,
		    CURLOPT_HTTPHEADER => array(
			    'Content-Type: application/json',
			    'X-Requested-With: XMLHttpRequest',
		    ),
	    ));

        return $response;
    }

	/**
	 * @param string      $contact_id
	 * @param string      $transfer_id
	 * @param float|null  $amount
	 * @param string|null $title
	 *
	 * @return array
	 *
	 * @throws \InvalidArgumentException
	 */
    public function transfer_prepare($contact_id, $transfer_id, $amount = null, $title = null)
    {
        trigger_error('Experimental feature');

        $params = array(
            'extraFormData' => null,
            'recipientId' => $contact_id,
            'templateId' => $transfer_id,
        );
	    $response = $this->curl(array(
		    CURLOPT_URL        => $this->url . '/pl/MyTransfer/TransferDomestic/PrepareTransferDomestic',
		    CURLOPT_POST => true,
		    CURLOPT_POSTFIELDS => json_encode($params),
		    CURLOPT_HTTPHEADER => array(
			    'Content-Type: application/json',
			    'X-Requested-With: XMLHttpRequest',
		    ),
	    ));

        $amount = $amount ?: $response['formData']['amount'];
        $title = $title ?: $response['formData']['title'];

        if (empty($amount) || !is_numeric($amount)) {
            throw new \InvalidArgumentException('Invalid amount');
        }

        if (empty($title)) {
            throw new \InvalidArgumentException('Invalid title');
        }

        $formData = array(
            //'accountParams' => $fromAccount['accountParams'],
            //'additionalOptions' => $response['formData']['additionalOptions'],
            'additionalOptions' => [
                'sendConfirmation' => false,
                //'sendConfirmationOptions' => ['example@example.com'],
                'sendSmsOnFail' => false,
            ],
            //'address' => $response['formData']['address'],
            //'addToBasket' => $response['formData']['addToBasket'],
            'amount' => $amount,
            //'BIC' => null,
            //'changedFromAcc' => false,
            //'currencies' => $response['formData']['defaultData']['currency'],
            'currency' => $response['formData']['defaultData']['currency'],
            'date' => $response['formData']['defaultData']['date'],
            //'deactivateDateField' => false,
            'deliveryTime' => $response['formData']['activeTransferMode'],
            //'deliveryTimeUpdate' => [
            //    $response['formData']['activeTransferMode'] => $response['formData']['transferModes'][$response['formData']['activeTransferMode']],
            //],
            //'dtmsg' => $response['formData']['transferModes'][$response['formData']['activeTransferMode']]['doneTime'],
            //'formType' => $response['formData']['formType'],
            'fromAccount' => $response['formData']['defaultData']['fromAccount'],
            //'isDefined' => $response['formData']['defaultData']['isDefined'],
            //'isFutureDate' => false,
            //'isRepeatableTransfer' => '',
            //'isSeriesOfTransfers' => '',
            //'isTrusted' => $response['formData']['isTrusted'],
            //'lastCheckedValues' => [
            //    'amount' => $amount,
            //    'currencies' => $response['formData']['defaultData']['currency'],
            //    'date' => $response['formData']['defaultData']['date'],
            //    'fromAccount' => $response['formData']['defaultData']['fromAccount'],
            //    'isDefined' => $response['formData']['defaultData']['isDefined'],
            //    'isTrusted' => $response['formData']['isTrusted'],
            //    'toAccount' => $response['formData']['toAccount'],
            //],
            //'perfToken' => $response['formData']['perfToken'],
            //'recipientName' => $response['formData']['defaultData']['recipientName'],
            //'repeatableTransfer' => [
            //    'calendarMessages' => '',
            //    'calendars' => [
            //        'dateFrom' => $response['formData']['defaultData']['date'],
            //        'dateTo' => $response['formData']['defaultData']['date'],
            //    ],
            //    'repeatRow' => [
            //        'period' => 'm',
            //        'untilFurtherNotice' => true,
            //        'value' => '1',
            //    ],
            //],
            //'sender' => $fromAccount['activeCoownerId'],
            //'seriesOfTransfers' => [[
            //    'amount' => $amount,
            //    'date' => $response['formData']['defaultData']['date'],
            //    'title' => $title,
            //]],
            //'showRepeatFromBasketWarning' => false,
            //'srcAccChangeToTemplate' => false,
            //'submitButtons' => [
            //    'addToBasket' => '',
            //    'cancel' => '',
            //    'submit' => '',
            //],
            //'templateId' => $transfer_id,
            'title' => $title,
            'toAccount' => $response['formData']['toAccount'],
        );

        $params = array(
            'formData' => $formData,
            'recipientId' => $contact_id,
            'templateId' => $transfer_id,
        );

	    $response = $this->curl(array(
		    CURLOPT_URL        => $this->url . '/pl/MyTransfer/TransferDomestic/IntermediateSubmitTransferDomestic',
		    CURLOPT_POST => true,
		    CURLOPT_POSTFIELDS => json_encode($params),
		    CURLOPT_HTTPHEADER => array(
			    'Content-Type: application/json',
			    'X-Requested-With: XMLHttpRequest',
		    ),
	    ));

        if ($response['authType'] !== 'none') {
            throw new \InvalidArgumentException('Invalid client_id, requires authorization');
        }

        //if ($amount > $response['summaryData']['additionalInfo']['fromAccount']['balance']) {
        //    throw new \InvalidArgumentException('Invalid amount, exceeds balance');
        //}

        return $formData;
    }

	/**
	 * @param $contact_id
	 * @param $transfer_id
	 * @param $transfer
	 *
	 * @return bool
	 */
    public function transfer_submit($contact_id, $transfer_id, $transfer)
    {
        trigger_error('Experimental feature');

        $params = array(
            'formData' => $transfer,
            'recipientId' => $contact_id,
            'templateId' => $transfer_id,
        );
        $params = json_encode($params);

	    $response = $this->curl(array(
		    CURLOPT_URL        => $this->url . '/pl/MyTransfer/TransferDomestic/FinalSubmitTransferDomestic',
		    CURLOPT_POST => true,
		    CURLOPT_POSTFIELDS => $params,
		    CURLOPT_HTTPHEADER => array(
			    'Content-Type: application/json',
			    'X-Requested-With: XMLHttpRequest',
		    ),
	    ));

        return (isset($response['summary']['fromAccount']) && isset($response['summary']['toAccount']));
    }

	/**
	 * Money transfer
	 * @param string $iban
	 * @param  $amount
	 * @param string $title
	 *
	 * @return bool
	 */
    public function transfer($iban, $amount, $title = 'Przelew środków')
    {
        trigger_error('Experimental feature');

        foreach ($this->contacts() as $contact) {
            if ($contact = $this->contact_details($contact['id'])) {
                foreach ($contact['transfers'] as $transfer) {
                    if ($transfer['isTrusted']) {
                        if (isset($transfer['receiverAccountNumber'])) {
                            $receiver = $transfer['receiverAccountNumber'];
                        } elseif ($transfer['departmentAccountNumber']) {
                            $receiver = $transfer['departmentAccountNumber'];
                        } else {
                            $receiver = null;
                        }

                        if ($receiver) {
                            $receiver = preg_replace('/[^\d]/', '', $receiver);
                            $iban = preg_replace('/[^\d]/', '', $iban);

                            if ($receiver === $iban) {
                                $data = $this->transfer_prepare($contact['contactId'], $transfer['id'], $amount, $title);

                                //$data['additionalOptions']['sendConfirmation'] = true;
                                //$data['additionalOptions']['sendConfirmationOptions'] = ['bgaluszka@kint.pl'];

                                return $this->transfer_submit($contact['contactId'], $transfer['id'], $data);
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

	/**
	 * Downloads MT940 report for given period.
	 *
	 * NOTE: you MUST have MT940 reporting active for that account.
	 *
	 * @param string $iban      Account IBAN number.
	 * @param string $startDate Report start date (in 'DD.MM.YYYY' format).
	 * @param string $endDate   Optional report end date (in 'DD.MM.YYYY' format). If not specified, Report for startDate only is returned.
	 *
	 * @return string
	 */
	public function mt940_get($iban, $startDate, $endDate = null)
	{
		if ($endDate === null) {
			$endDate = $startDate;
		}

		$query_params = array(
			'AccountNumber'    => $iban,
			'Period'           => 'Nonstandard',
			'ReportPeriodFrom' => $startDate,
			'ReportPeriodTo'   => $endDate,
		);

		$response = $this->curl(array(
			CURLOPT_URL => $this->url . '/pl/Pfm/Reports/DownloadMT940Report?' . http_build_query($query_params),
		));

		return $response;
	}

	/**
	 * @return array
	 */
	public function logout()
	{
		$this->token = null;
		$this->tab = null;

		return $this->curl(array(
				CURLOPT_URL => $this->url . '/pl/Account/Logout',
			)
		);
	}

	/**
	 * @param array $opts
	 */
    public function setopt(array $opts)
    {
        $this->opts = $opts + $this->opts;
    }

	/**
	 * @param array $opts optional array of cURL extension options to pass to its curl_exec()
	 *
	 * @return array
	 *
	 * @throws \RuntimeException
	 */
    protected function curl(array $opts = array())
    {
        $opts += $this->opts;

	    if (isset($this->token)) {
//        if ($this->token !== null) {
            // seems like value of this doesn't matter
            $opts[CURLOPT_HTTPHEADER][] = "X-Request-Verification-Token: {$this->token}";
        }

        if ($this->tab !== null) {
            // seems like it has to be the same as the one in cookie but value doesn't matter
            $opts[CURLOPT_HTTPHEADER][] = "X-Tab-Id: {$this->tab}";
        }

	    curl_setopt_array($this->curl, $opts);

        $response = curl_exec($this->curl);

        if ($json = json_decode($response, true)) {
            $response = $json;
        }

        if ($error = curl_error($this->curl)) {
            throw new \RuntimeException("curl() failed - {$error}");
        }

        $code = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

        if ($code >= 400) {
            $exception = "curl() failed - HTTP Status Code {$code}";

            if (isset($response['message'])) {
                $exception = "{$exception} ({$response['message']})";
            }

            throw new \RuntimeException($exception);
        }

        return $response;
    }

	/**
	 * @param string $html
	 *
	 * @return void
	 *
	 * @throws \RuntimeException
	 */
    protected function load($html)
    {
	    /** @noinspection PhpUsageOfSilenceOperatorInspection */
	    if (!@$this->document->loadHTML($html)) {
            throw new \RuntimeException('loadHTML() failed');
        }

        $this->xpath = new \DOMXPath($this->document);
        //$this->xpath->registerNamespace('php', 'http://php.net/xpath');
        //$this->xpath->registerPHPFunctions(array('preg_match'));
    }

	/**
	 * @param string $string
	 *
	 * @return float
	 */
    protected static function toFloat($string)
    {
        $pr = array(
            '/[^\-\d,]/' => '',
            '/,/' => '.',
        );

        return (float)preg_replace(array_keys($pr), $pr, $string);
    }
}
