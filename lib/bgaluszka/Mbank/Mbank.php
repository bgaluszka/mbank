<?php

namespace bgaluszka\Mbank;

class Mbank
{
    protected $curl;

    protected $tab, $token;

    protected $document;

    protected $xpath;

    protected $opts = array();

    public $url = 'https://online.mbank.pl/pl';

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
            CURLOPT_URL => $this->url . '/Login',
        );
        $response = $this->curl($opts);

        $opts = array(
            CURLOPT_URL => $this->url . '/Account/JsonLogin',
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
            CURLOPT_URL => $this->url,
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
            CURLOPT_URL => $this->url . '/LoginMain/Account/JsonActivateProfile',
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
            CURLOPT_URL => $this->url . '/Accounts/Accounts/List',
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

    public function operations($iban = null)
    {
        if ($iban) {
            $opts = array(
                CURLOPT_URL => $this->url . '/MyDesktop/Desktop/SetNavigationToAccountHistory',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => array(
                    'accountNumber' => $iban,
                ),
            );

            $this->curl($opts);
        }

        $opts = array(
            CURLOPT_URL => $this->url . '/Pfm/TransactionHistory',
        );

        $response = $this->curl($opts);

        // http://php.net/manual/en/domdocument.loadhtml.php#95251
        $this->load('<?xml encoding="UTF-8">' . $response);

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

    public function logout()
    {
        $opts = array(
            CURLOPT_URL => $this->url . '/Account/Logout',
        );

        return $this->curl($opts);
    }

    public function setopt($opts)
    {
        $this->opts += $opts;
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
