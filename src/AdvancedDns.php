<?php
/**
 * It collects DNS results using dig command.
 *
 * @package AdvancedDns
 * @author Gürkan Biçer <gurkan@grkn.co>
 * @link https://github.com/gurkanbicer/advanced-dns
 * @license MIT (https://opensource.org/licenses/MIT)
 * @version 0.1
 */

namespace Gurkanbicer\AdvancedDns;
use Symfony\Component\Process\Process;

Class AdvancedDns
{
    protected $domain;
    protected $tld;
    protected $errors = [];

    public function __construct($domain)
    {
        try {
            if (!is_string($domain) || $domain == '')
                throw new \Exception('Domain is required.');

            $this->domain = $this->sanitizeDomain($domain);
            $this->tld = $this->fetchTld($domain);

        } catch (\Exception $exception) {
            $this->errors[] = $exception->getMessage();
            $this->domain = false;
            $this->tld = false;
        }
    }

    /**
     * @param $domain
     * @return string
     */
    protected function sanitizeDomain($domain)
    {
        $domain = strtolower($domain);
        $domain = str_replace(['http://', 'https://'], '', $domain);
        return $domain;
    }

    /**
     * @param $domain
     * @return string
     */
    protected function fetchTld($domain)
    {
        $explDomain = explode('.', $domain);
        if (count($explDomain) > 1) {
            $tld = end($explDomain);
            return strtolower($tld);
        }
    }

    /**
     * @return bool
     */
    protected function hasError()
    {
        if (!empty($this->errors)) return true;
        else return false;
    }

    /**
     * @param $host
     * @param $type
     * @param null $nameserver
     * @return string
     */
    protected function getAnswerCmd($host, $type, $nameserver = null)
    {
        if ($type == "SOA" || $type == "TXT")
            $command = 'dig +nocmd +noall +multiline +answer +short {HOST} {TYPE} {NS}';
        else
            $command = 'dig +nocmd +noall +multiline +answer {HOST} {TYPE} {NS}';
        $command = str_ireplace(['{HOST}', '{TYPE}'], [$host, $type], $command);
        if (!is_null($nameserver))
            $command = str_ireplace('{NS}', '@' . $nameserver, $command);
        return $command;
    }

    /**
     * @param $host
     * @param $type
     * @param null $nameserver
     * @return string
     */
    protected function getAuthorityCmd($host, $type, $nameserver = null)
    {
        $command = 'dig +nocmd +noall +multiline +authority +additional {HOST} {TYPE} {NS}';
        $command = str_ireplace(['{HOST}', '{TYPE}'], [$host, $type], $command);
        if (!is_null($nameserver))
            $command = str_ireplace('{NS}', '@' . $nameserver, $command);
        return $command;
    }

    /**
     * @param $tld
     * @return string
     */
    protected function getRootCmd($tld)
    {
        $command = 'dig +nocmd +noall +multiline +answer +short NS {HOST}';
        $command = str_ireplace('{HOST}', $tld, $command);
        return $command;
    }

    /**
     * @return array|bool
     */
    protected function getAuthorityServers()
    {
        if ($this->hasError() === false) {
            $command = $this->getRootCmd($this->tld);
            $process = new Process($command);
            $process->setTimeout(1);
            $process->run();
            if (!$process->isSuccessful()) {
                return false;
            } else {
                $rawResult = $process->getOutput();
                if ($rawResult === '')
                    return false;
                else
                    $explResult = explode("\n", $rawResult);

                $actualResult = [];
                foreach ($explResult as $item) {
                    if ($item != '')
                        $actualResult[] = rtrim($item, '.');
                }
                shuffle($actualResult);
                return $actualResult;
            }
        } else {
            return false;
        }
    }

    /**
     * @return array|bool
     */
    public function authorityNameserverLookup() {
        if ($this->hasError() === false) {
            $nameservers = $this->getAuthorityServers();
            if ($nameservers === false)
                return false;

            $command = $this->getAuthorityCmd($this->domain, 'NS', $nameservers[0]);
            $process = new Process($command);
            $process->setTimeout(1);
            $process->run();

            if (!$process->isSuccessful()) {
                return false;
            } else {
                $rawResult = $process->getOutput();
                if ($rawResult === '')
                    return false;
                else
                    $explResult = explode("\n", $rawResult);

                $lines = [];
                foreach ($explResult as $item) {
                    if ($item != '') {
                        $lines[] = str_ireplace(["\t\t", "\t"], " ", $item);
                    }
                }

                $actualResult = [];
                $actualResult['type'] = 'DOMAINNS';

                $countAType = 0;
                foreach ($lines as $line) {
                    $explLine = explode(' ', $line);
                    if ($explLine[3] == 'NS') {
                        $host = rtrim($explLine[4], '.');
                        if (!isset($actualResult['response'][$host])) {
                            $actualResult['response'][$host] = [
                                'ttl' => (int) $explLine[1],
                                'data' => []
                            ];
                        }
                    }

                    if ($explLine[3] == 'A') {
                        $countAType++;
                        $host = rtrim($explLine[0], '.');
                        $actualResult['response'][$host]['data'][] = $explLine[4];
                    }
                }

                if ($countAType == 0) {
                    foreach ($actualResult['response'] as $key => $value) {
                        $command2 = $this->getAnswerCmd($key, 'A', null);
                        $process2 = new Process($command2);
                        $process2->setTimeout(1);
                        $process2->run();

                        if ($process2->isSuccessful()) {
                            $rawResult2 = $process2->getOutput();
                            if ($rawResult2 === '')
                                return false;
                            else
                                $explResult2 = explode("\n", $rawResult2);

                            $lines2 = [];
                            foreach ($explResult2 as $item2) {
                                if ($item2 != '') {
                                    $lines2[] = str_ireplace(["\t\t", "\t"], " ", $item2);
                                }
                            }

                            foreach ($lines2 as $line2) {
                                $explLine2 = explode(' ', $line2);
                                $actualResult['response'][$key]['data'][] = $explLine2[4];
                            }
                        }
                    }
                }

                $actualResult['nameserver'] = $nameservers[0];
                return $actualResult;
            }
        } else {
            return false;
        }
    }

    /**
     * @param string $type
     * @param null $nameserver
     * @return array|bool
     */
    public function lookup($type = '', $nameserver = null)
    {
        if ($this->hasError() === false) {
            $command = $this->getAnswerCmd($this->domain, $type, $nameserver);
            $process = new Process($command);
            $process->setTimeout(1);
            $process->run();
            if (!$process->isSuccessful()) {
                return false;
            } else {
                $rawResult = $process->getOutput();
                if ($rawResult === '')
                    return false;
                else
                    $explResult = explode("\n", $rawResult);

                $lines = [];
                foreach ($explResult as $item) {
                    if ($item != '') {
                        $lines[] = str_ireplace(["\t\t", "\t"], " ", $item);
                    }
                }

                switch ($type) {
                    case 'A':
                        $actualResult = [];
                        $actualResult['type'] = $type;
                        foreach ($lines as $line) {
                            $explLine = explode(' ', $line);
                            if ($explLine[3] == 'A') {
                                $actualResult['response'][] = [
                                    'ttl' => (int) $explLine[1],
                                    'data' => $explLine[4]
                                ];
                            }
                        }
                        $actualResult['nameserver'] = $nameserver;
                        return $actualResult;
                        break;
                    case 'AAAA':
                        $actualResult = [];
                        $actualResult['type'] = $type;
                        foreach ($lines as $line) {
                            $explLine = explode(' ', $line);
                            if ($explLine[3] == 'AAAA') {
                                $actualResult['response'][] = [
                                    'ttl' => (int) $explLine[1],
                                    'data' => $explLine[4]
                                ];
                            }
                        }
                        $actualResult['nameserver'] = $nameserver;
                        return $actualResult;
                        break;
                    case 'CNAME':
                        $actualResult = [];
                        $actualResult['type'] = $type;
                        foreach ($lines as $line) {
                            $explLine = explode(' ', $line);
                            if ($explLine[3] == 'CNAME') {
                                $actualResult['response'][] = [
                                    'ttl' => (int) $explLine[1],
                                    'data' => rtrim($explLine[4], '.'),
                                ];
                            }
                        }
                        return $actualResult;
                        break;
                    case 'MX':
                        $actualResult = [];
                        $actualResult['type'] = $type;
                        foreach ($lines as $line) {
                            $explLine = explode(' ', $line);
                            if ($explLine[3] == 'MX') {
                                $actualResult['response'][] = [
                                    'ttl' => (int) $explLine[1],
                                    'priority' => (int) $explLine[4],
                                    'data' => rtrim($explLine[5], '.')
                                ];
                            }
                        }
                        $actualResult['nameserver'] = $nameserver;
                        return $actualResult;
                        break;
                    case 'NS':
                        $actualResult = [];
                        $actualResult['type'] = $type;
                        foreach ($lines as $line) {
                            $explLine = explode(' ', $line);
                            if ($explLine[3] == 'NS') {
                                $actualResult['response'][] = [
                                    'ttl' => (int) $explLine[1],
                                    'data' => rtrim($explLine[4], '.')
                                ];
                            }
                        }
                        $actualResult['nameserver'] = $nameserver;
                        return $actualResult;
                        break;
                    case 'SOA':
                        $actualResult = [];
                        $actualResult['type'] = $type;
                        foreach ($lines as $line) {
                            $explLine = explode(' ', $line);
                            $actualResult['response'] = [
                                'primaryNs' => rtrim($explLine[0], '.'),
                                'hostmaster' => rtrim($explLine[1], '.'),
                                'serial' => (int) $explLine[2],
                                'refresh' => (int) $explLine[3],
                                'retry' => (int) $explLine[4],
                                'expire' => (int) $explLine[5],
                                'minimumTtl' => (int) $explLine[6]
                            ];
                        }
                        $actualResult['nameserver'] = $nameserver;
                        return $actualResult;
                        break;
                    case 'TXT':
                        $actualResult = [];
                        $actualResult['type'] = $type;
                        foreach ($lines as $line) {
                            $actualResult['response'][] = [
                                'data' => trim($line, '"')
                            ];
                        }
                        $actualResult['nameserver'] = $nameserver;
                        return $actualResult;
                        break;
                    default:
                        return false;
                }
            }
        } else {
            return false;
        }
    }
}

/* path: ~src/AdvancedDns.php */