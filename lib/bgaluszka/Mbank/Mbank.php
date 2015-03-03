<?php

namespace bgaluszka\Mbank;

class Mbank
{
    protected $curl;

    protected $tab, $token;

    protected $document;

    protected $xpath;

    protected $opts = array();

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
            CURLOPT_COOKIEJAR => '/dev/null',
        );

        $this->document = new \DOMDocument('1.0', 'UTF-8');
        $this->document->preserveWhiteSpace = false;
    }

    public function __destruct()
    {
        curl_close($this->curl);
    }

    public function login($username, $password)
    {
        $opts = array(
            CURLOPT_URL => $this->url . '/pl/Login',
        );
        $response = $this->curl($opts);

        $opts = array(
            CURLOPT_URL => $this->url . '/pl/Account/JsonLogin',
            CURLOPT_POST => true,
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

    public function profile($profile)
    {
        $profiles = array(
            'individual' => 'I',
            'business' => 'F',
        );

        if (!isset($profiles[$profile])) {
            throw new \Exception('Invalid profile (individual|business)');
        }

        $opts = array(
            CURLOPT_URL => $this->url . '/pl/LoginMain/Account/JsonActivateProfile',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => array(
                'profileCode' => $profiles[$profile],
            ),
        );

        return $this->curl($opts);
    }

    public function accounts()
    {
        $opts = array(
            CURLOPT_URL => $this->url . '/pl/Accounts/Accounts/List',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => array(),
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

    public function operations($iban = null, $criteria = array())
    {
        if ($iban) {
            $opts = array(
                CURLOPT_URL => $this->url . '/pl/MyDesktop/Desktop/SetNavigationToAccountHistory',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => array(
                    'accountNumber' => $iban,
                ),
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
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $criteria,
                    CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
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

    public function export($iban, $params = array())
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
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
        );

        return $this->curl($opts);
    }

    public function logout()
    {
        $opts = array(
            CURLOPT_URL => $this->url . '/pl/Account/Logout',
        );

        return $this->curl($opts);
    }

    public function setopt($opts)
    {
        $this->opts = $opts + $this->opts;
    }

    protected function curl($opts)
    {
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

        if (curl_errno($this->curl)) {
            throw new \Exception('curl() failed - ' . curl_error($this->curl));
        }

        $code = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

        if ($code >= 400) {
            throw new \Exception("curl() failed - HTTP Status Code {$code}");
        }

        if ($json = json_decode($response, true)) {
            return $json;
        } else {
            return $response;
        }
    }

    protected function load($html)
    {
        if (!@$this->document->loadHTML($html)) {
            throw new \Exception('loadHTML() failed');
        }

        $this->xpath = new \DOMXPath($this->document);
        //$this->xpath->registerNamespace('php', 'http://php.net/xpath');
        //$this->xpath->registerPHPFunctions(array('preg_match'));
    }

    protected static function tofloat($string)
    {
        $pr = array(
            '/[^\-\d,]/' => '',
            '/,/' => '.',
        );

        return (float) preg_replace(array_keys($pr), $pr, $string);
    }
}
