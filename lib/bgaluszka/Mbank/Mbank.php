<?php

namespace bgaluszka\Mbank;

class Mbank
{
    protected $curl;

    /** @var string */
    protected $tab;

    /** @var string */
    protected $token;

    /** @var \DOMDocument */
    protected $document;

    /** @var \DOMXPath */
    protected $xpath;

    /** @var array */
    protected $opts = array();

    /** @var string string */
    public $url = 'https://online.mbank.pl';


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
	 * @param string $username
	 * @param string $password
	 *
	 * @return bool
	 *
	 * @throws \Exception
	 */
    public function login($username, $password)
    {
        $opts = array(
            CURLOPT_URL => $this->url . '/pl/Login',
        );
        $response = $this->curl($opts);

        $opts = array(
            CURLOPT_URL => $this->url . '/pl/Account/JsonLogin',
            CURLOPT_POSTFIELDS => array(
                'UserName' => $username,
                'Password' => $password,
                'Seed' => '',
                'Lang' => '',
            ),
        );
        $response = $this->curl($opts);

        if (empty($response['successful'])) {
            throw new \Exception('login() failed');
        }

        $this->tab = $response['tabId'];

        $opts = array(
            CURLOPT_URL => $this->url . '/pl',
        );
        $response = $this->curl($opts);

        $this->load($response);

        $this->token = $this->xpath->evaluate('string(//meta[@name="__AjaxRequestVerificationToken"]/@content)');

        return true;
    }

	/**
	 * @param string $profile
	 *
	 * @return mixed
	 */
    public function profile($profile)
    {
        $profiles = array(
            'individual' => 'I',
            'business' => 'F',
        );

        if (!array_key_exists($profile, $profiles)) {
            throw new \InvalidArgumentException('Invalid profile (individual|business)');
        }

        $opts = array(
            CURLOPT_URL => $this->url . '/pl/LoginMain/Account/JsonActivateProfile',
            CURLOPT_POSTFIELDS => array(
                'profileCode' => $profiles[$profile],
            ),
        );

        return $this->curl($opts);
    }

	/**
	 * @return array
	 */
    public function accounts()
    {
        $opts = array(
            CURLOPT_URL => $this->url . '/pl/Accounts/Accounts/List',
            CURLOPT_POSTFIELDS => array(),
            CURLOPT_HTTPHEADER => array('X-Requested-With: XMLHttpRequest'),
        );

        $response = $this->curl($opts);

        $accounts = array();

        foreach ($response['properties'] as $key => $property) {
            if (in_array($key, array('CurrentAccountsList', 'SavingAccountsList'))) {
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
            $opts = array(
                CURLOPT_URL => $this->url . '/pl/MyDesktop/Desktop/SetNavigationToAccountHistory',
                CURLOPT_POSTFIELDS => array(
                    'accountNumber' => $iban,
                ),
                CURLOPT_HTTPHEADER => array('X-Requested-With: XMLHttpRequest'),
            );

            $this->curl($opts);
        }

        $opts = array(
            CURLOPT_URL => $this->url . '/pl/Pfm/TransactionHistory',
        );

        $response = $this->curl($opts);

        // http://php.net/manual/en/domdocument.loadhtml.php#95251
        $this->load('<?xml encoding="UTF-8">' . $response);

        if ($criteria) {
            $nodes = $this->xpath->query('//input[@name="ProductIds[]"][@checked]');

            $products = array();

            foreach ($nodes as $node) {
                $products[] = $this->xpath->evaluate('string(@value)', $node);
            }

            if ($products) {
                $criteria = array_merge(array(
                    'ProductIds' => $products,
                ), $criteria);

                $criteria = json_encode($criteria);

                $opts = array(
                    CURLOPT_URL => $this->url . '/pl/Pfm/TransactionHistory/TransactionList',
                    CURLOPT_POSTFIELDS => $criteria,
                    CURLOPT_HTTPHEADER => array(
                        'Content-Type: application/json',
                        'X-Requested-With: XMLHttpRequest',
                    ),
                );

                $response = $this->curl($opts);

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
                'value' => self::tofloat($this->xpath->evaluate('string(header/div[@class="column amount"]/strong)', $node)),
                'currency' => $this->xpath->evaluate('string(@data-currency)', $node),
            );
        }

        return $operations;
    }

	/**
	 * @param string $iban
	 * @param array  $params
	 *
	 * @return array
	 *
	 * @throws \InvalidArgumentException
	 */
    public function export($iban, array $params = array())
    {
        $opts = array(
            CURLOPT_URL => $this->url . '/csite/account_oper_list.aspx',
        );

        $response = $this->curl($opts);

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
            'ALL000000' => 'Wszystkie',
            'ABO000000' => 'Uznania rachunku',
            'CAR000000' => 'Obciążenia rachunku',
            'TRI111000' => 'Przelewy przychodzące',
            'TRO111000' => 'Przelewy wychodzące',
            'TIH111000' => 'Przelewy własne',
            'TUS111000' => 'Przelewy podatkowe',
            'TRZ101000' => 'Przelewy do ZUS',
            'LDS100000' => 'Operacje kartowe',
            'CRE100000' => 'Operacje na kredycie',
            'CAI100000' => 'Wpłaty gotówkowe',
            'CAO100000' => 'Wypłaty gotówkowe',
            'INT000000' => 'Kapitalizacja odsetek',
            'COM100000' => 'Prowizje i opłaty',
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

        $opts = array(
            CURLOPT_URL => $this->url . '/csite/printout_oper_list.aspx',
            CURLOPT_POSTFIELDS => http_build_query($params),
        );

        return $this->curl($opts);
    }

	/**
	 * Returns your bank contact list
	 *
	 * @return array
	 */
    public function contacts()
    {
        $opts = array(
            CURLOPT_URL => $this->url . '/pl/AddressBook/Data/GetContactListForAddressBook',
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'X-Requested-With: XMLHttpRequest',
            ),
        );

        $response = $this->curl($opts);
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

        $opts = array(
            CURLOPT_URL => $this->url . '/pl/AddressBook/Data/GetContactDetails',
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'X-Requested-With: XMLHttpRequest',
            ),
        );

        $response = $this->curl($opts);

        return $response;
    }

	/**
	 * @param string $contact_id
	 * @param string $transfer_id
	 * @param float|null $amount
	 * @param string|null $title
	 *
	 * @return array
	 */
    public function transfer_prepare($contact_id, $transfer_id, $amount = null, $title = null)
    {
        trigger_error('Experimental feature');

        $params = array(
            'extraFormData' => null,
            'recipientId' => $contact_id,
            'templateId' => $transfer_id,
        );
        $params = json_encode($params);

        $opts = array(
            CURLOPT_URL => $this->url . '/pl/MyTransfer/TransferDomestic/PrepareTransferDomestic',
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'X-Requested-With: XMLHttpRequest',
            ),
        );

        $response = $this->curl($opts);

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
        $params = json_encode($params);

        $opts = array(
            CURLOPT_URL => $this->url . '/pl/MyTransfer/TransferDomestic/IntermediateSubmitTransferDomestic',
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'X-Requested-With: XMLHttpRequest',
            ),
        );

        $response = $this->curl($opts);

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

        $opts = array(
            CURLOPT_URL => $this->url . '/pl/MyTransfer/TransferDomestic/FinalSubmitTransferDomestic',
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'X-Requested-With: XMLHttpRequest',
            ),
        );

        $response = $this->curl($opts);

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
	 * @return array
	 */
    public function logout()
    {
        $opts = array(
            CURLOPT_URL => $this->url . '/pl/Account/Logout',
        );

        return $this->curl($opts);
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
	 * @throws \Exception
	 */
    protected function curl(array $opts = array())
    {
    	// If CURLOPT_POSTFIELDS we enforce CURLOPT_POST
	    if (array_key_exists(CURLOPT_POSTFIELDS, $opts)) {
		    $opts[CURLOPT_POST] = true;
	    }
        $opts += $this->opts;

        if (isset($this->token)) {
            // seems like value of this doesn't matter
            $opts[CURLOPT_HTTPHEADER][] = "X-Request-Verification-Token: {$this->token}";
        }

        if (isset($this->tab)) {
            // seems like it has to be the same as the one in cookie but value doesn't matter
            $opts[CURLOPT_HTTPHEADER][] = "X-Tab-Id: {$this->tab}";
        }

        curl_setopt_array($this->curl, $opts);

        $response = curl_exec($this->curl);

        if ($json = json_decode($response, true)) {
            $response = $json;
        }

        if ($error = curl_error($this->curl)) {
            throw new \Exception("curl() failed - {$error}");
        }

        $code = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

        if ($code >= 400) {
            $exception = "curl() failed - HTTP Status Code {$code}";

            if (isset($response['message'])) {
                $exception = "{$exception} ({$message})";
            }

            throw new \Exception($exception);
        }

        return $response;
    }

	/**
	 * @param string $html
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
    protected function load($html)
    {
        if (!@$this->document->loadHTML($html)) {
            throw new \Exception('loadHTML() failed');
        }

        $this->xpath = new \DOMXPath($this->document);
        //$this->xpath->registerNamespace('php', 'http://php.net/xpath');
        //$this->xpath->registerPHPFunctions(array('preg_match'));
    }

	/**
	 * @param string $string
	 *
	 * @return void
	 *
	 * @return float
	 */
    protected static function tofloat($string)
    {
        $pr = array(
            '/[^\-\d,]/' => '',
            '/,/' => '.',
        );

        return (float) preg_replace(array_keys($pr), $pr, $string);
    }
}
