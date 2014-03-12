<?php
/*
 * Copyright (c) Bartosz Gałuszka
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is furnished
 * to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace Mbank;

class Mbank
{
    protected $curl;

    protected $cookie;

    protected $document;

    protected $xpath;

    protected $opts = array();

    const URL = 'https://www.mbank.com.pl';

    public function __construct()
    {
        $this->curl = curl_init();

        $this->opts = array(
            CURLOPT_URL => null,
            CURLOPT_POST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => array('Expect:'),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSLVERSION => 3,
            // http://blog.volema.com/curl-rce.html
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_COOKIE => &$this->cookie,
        );

        $this->document = new \DOMDocument();
        $this->document->preserveWhiteSpace = false;
    }

    public function __destruct()
    {
        curl_close($this->curl);
    }

    public function login($customer, $password)
    {
        $opts = array(
            CURLOPT_URL => self::URL,
            CURLOPT_REFERER => 'http://www.mbank.pl/login/',
        );
        $this->curl($opts);

        $opts = array(
            CURLOPT_URL => self::URL . '/logon.aspx',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => array(
                '__PARAMETERS' => $this->xpath->evaluate('string(//input[@name="__PARAMETERS"]/@value)'),
                '__STATE' => $this->xpath->evaluate('string(//input[@name="__STATE"]/@value)'),
                '__VIEWSTATE' => $this->xpath->evaluate('string(//input[@name="__VIEWSTATE"]/@value)'),
                '__EVENTVALIDATION' => $this->xpath->evaluate('string(//input[@name="__EVENTVALIDATION"]/@value)'),
                'customer' => $customer,
                'password' => $password,
                'seed' => $this->xpath->evaluate('string(//input[@name="seed"]/@value)'),
            ),
        );
        $this->curl($opts);

        if (!preg_match('/mBank2(?!\s+NotValid)/', $this->cookie)) {
            throw new \Exception('login() failed');
        }

        return true;
    }

    public function accounts()
    {
        $opts = array(
            CURLOPT_URL => self::URL . '/accounts_list.aspx',
        );
        $this->curl($opts);

        $accounts = array();

        $nodes = $this->xpath->query('//div[@id="AccountsGrid"]/ul/li[p[@class="Account"]/a]');

        foreach ($nodes as $node) {
            $onclick = $this->xpath->evaluate('string(p[@class="Amount"]/a/@onclick)', $node);
            preg_match("/'POST','(?<parameters>[^']+)'/", $onclick, $matches);
            $parameters = $matches['parameters'];

            $name = $this->xpath->evaluate('string(p[@class="Account"]/a)', $node);
            $iban = preg_replace('/[^\d]/', '', $name);
            $name = preg_replace('/[\d ]{26,}/', '', $name);

            $value = $this->xpath->evaluate('string(p[@class="Amount"]/span)', $node);
            preg_match('/ (?<currency>\w{3})$/', $value, $matches);
            $currency = $matches['currency'];
            $value = self::tofloat($value);

            $balance = $this->xpath->evaluate('string(p[@class="Amount"]/a)', $node);
            $balance = self::tofloat($balance);

            $accounts[$iban] = array(
                'name' => $name,
                'iban' => $iban,
                'value' => $value,
                'balance' => $balance,
                'currency' => $currency,
                'operations_params' => array(
                    '__PARAMETERS' => $parameters,
                    '__STATE' => $this->xpath->evaluate('string(//input[@name="__STATE"]/@value)'),
                    '__VIEWSTATE' => $this->xpath->evaluate('string(//input[@name="__VIEWSTATE"]/@value)'),
                ),
            );
        }

        return $accounts;
    }

    public function operations($account)
    {
        $opts = array(
            CURLOPT_URL => self::URL . '/account_oper_list.aspx',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $account['operations_params'],
        );
        $this->curl($opts);

        $operations = array();

        $nodes = $this->xpath->query('//div[@id="account_operations"]/ul/li[p[@class="Date"]/span]');

        foreach ($nodes as $node) {
            $type = $this->xpath->evaluate('string(p[@class="OperationDescription"]/a)', $node);
            $type = trim($type);

            $elements = $this->xpath->evaluate('count(p[@class="OperationDescription"]/span)', $node);

            if ($elements == 3) {
                $name = '';
                $iban = $this->xpath->evaluate('string(p[@class="OperationDescription"]/span[1])', $node);
                $title = $this->xpath->evaluate('string(p[@class="OperationDescription"]/span[2])', $node);
            } elseif ($elements == 4) {
                $name = $this->xpath->evaluate('string(p[@class="OperationDescription"]/span[1])', $node);
                $iban = $this->xpath->evaluate('string(p[@class="OperationDescription"]/span[2])', $node);
                $title = $this->xpath->evaluate('string(p[@class="OperationDescription"]/span[3])', $node);
            }

            $title = trim($title);
            $title = preg_replace('/\s{2,}/', "\n", $title);
            $iban = preg_replace('/[^\d]/', '', $iban);

            $value = $this->xpath->evaluate('string(p[@class="Amount"][1]/span)', $node);
            preg_match('/ (?<currency>\w{3})$/', $value, $matches);
            $currency = $matches['currency'];
            $value = self::tofloat($value);

            $balance = $this->xpath->evaluate('string(p[@class="Amount"][2]/span)', $node);
            $balance = self::tofloat($balance);

            $created = $this->xpath->evaluate('string(p[@class="Date"]/span[1])', $node);
            $created = date('Y-m-d', strtotime($created));

            $released = $this->xpath->evaluate('string(p[@class="Date"]/span[2])', $node);
            $released = date('Y-m-d', strtotime($released));

            $operations[] = compact(
                'type',
                'name',
                'title',
                'iban',
                'value',
                'balance',
                'currency',
                'created',
                'released'
            );
        }

        return $operations;
    }

    public function logout()
    {
        $opts = array(
            CURLOPT_URL => self::URL . '/logout.aspx',
        );
        $this->curl($opts);
    }

    public function setopt($opts)
    {
        $this->opts = $opts + $this->opts;
    }

    protected function curl($opts)
    {
        $opts += $this->opts;

        curl_setopt_array($this->curl, $opts);

        $response = curl_exec($this->curl);

        if (curl_errno($this->curl)) {
            throw new \Exception('curl() failed - ' . curl_error($this->curl));
        }

        $code = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

        if ($code != 200) {
            throw new \Exception("curl() failed - HTTP Status Code {$code}");
        }

        list($headers, $html) = explode("\r\n\r\n", $response, 2);

        // NOTE I don't want to use CURLOPT_COOKIEFILE
        if (preg_match_all('/Set-Cookie: (?<cookie>.*);/U', $headers, $matches)) {
            $this->cookie = implode(';', $matches['cookie']);
        }

        return $this->load($html);
    }

    protected function load($html)
    {
        if (!@$this->document->loadHTML($html)) {
            throw new \Exception('loadHTML() failed');
        }

        $this->xpath = new \DOMXPath($this->document);
        //$this->xpath->registerNamespace('php', 'http://php.net/xpath');
        //$this->xpath->registerPHPFunctions(array('preg_match'));

        $nosession = $this->xpath->evaluate('string(//div[@id="errorView"][@class="error noSession"]/fieldset/p[@class="message"])');

        if ($nosession) {
            throw new \Exception($nosession);
        }

        //$error = $this->xpath->evaluate('string(//div[@id="errorView"]/fieldset/p[@class="message"])');
        //if ($error) {
        //    throw new \Exception($error);
        //}
        return true;
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
