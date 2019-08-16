<?php
/**
 * It collects DNS results using dig command.
 *
 * @package AdvancedDns
 * @author Gürkan Biçer <gurkan@grkn.co>
 * @link https://github.com/gurkanbicer/advanced-dns
 * @license MIT (https://opensource.org/licenses/MIT)
 * @version 0.2
 */

namespace Gurkanbicer\AdvancedDns;
use Symfony\Component\Process\Process;

Class AdvancedDns
{
    protected $domain;
    protected $tld;
    protected $errors = [];
    protected $authorityServers = [];
    protected $supportedTypes = [
        'A', 'AAAA', 'CNAME', 'MX', 'NS', 'SOA', 'TXT'
    ];

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
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
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
        $command = 'dig +nocmd +noall +multiline +answer +comments {HOST} {TYPE} {NS}';
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
        $command = 'dig +nocmd +noall +multiline +authority +additional +comments {HOST} {TYPE} {NS}';
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
    public function authorityNameserverLookup()
    {
        try {
            if ($this->hasError())
                throw new \Exception('It has errors on construct. Please check it with getErrors function.');

            $this->authorityServers = $this->getAuthorityServers();
            if ($this->authorityServers === false)
                throw new \Exception('Could not get any response about authority servers.');

            $command = $this->getAuthorityCmd($this->domain, 'NS', $this->authorityServers[0]);
            $process = new Process($command);
            $process->setTimeout(1);
            $process->run();

            if (!$process->isSuccessful())
                throw new \Exception($process->getErrorOutput());

            $outputSnapshot = $process->getOutput();

            if ($outputSnapshot == '')
                throw new \Exception('Could not get any response.');

            $explOutput = explode("\n", $outputSnapshot);

            // fetch and clean comments from output
            $comments = [];
            for ($i = 0; $i < 6; $i++) {
                if ($explOutput[$i] != '') {
                    $comments[] = $explOutput[$i];
                    unset($explOutput[$i]);
                } else {
                    unset($explOutput[$i]);
                }
            }
            $explComments = explode(',', $comments[1]);

            // DNS Server Response
            $status = trim(str_replace('status:', '', $explComments[1]));

            // resort output
            ksort($explOutput);

            if ($status == 'NOERROR') {
                // clean comments
                $lines = [];
                foreach ($explOutput as $item) {
                    if ($item != ''
                        && substr($item, 0, 1) != ';') {
                        $lines[] = str_ireplace(["\t\t", "\t"], " ", $item);
                    }
                }

                $actualResults = [];
                $actualResults['type'] = 'DOMAINNS';
                $countAType = 0;

                foreach ($lines as $line) {
                    $explLine = explode(' ', $line);

                    if ($explLine[3] == 'NS') {
                        $host = rtrim($explLine[4], '.');
                        if (!isset($actualResults['response'][$host])) {
                            $actualResults['response'][$host] = [
                                'ttl' => (int)$explLine[1],
                                'data' => []
                            ];
                        }
                    }
                    if ($explLine[3] == 'A') {
                        $countAType++;
                        $host = rtrim($explLine[0], '.');
                        $actualResults['response'][$host]['data'][] = $explLine[4];
                    }
                }

                // if domain pointing different nameservers
                if ($countAType == 0) {
                    foreach ($actualResults['response'] as $key => $value) {
                        $command2 = $this->getAnswerCmd($key, 'A', null);
                        $command2 = str_ireplace('+comments', '', $command2);
                        $process2 = new Process($command2);
                        $process2->setTimeout(1);
                        $process2->run();

                        if ($process2->isSuccessful()) {
                            $outputSnapshot2 = $process2->getOutput();
                            if ($outputSnapshot2 === '')
                                continue;
                            else
                                $explResult2 = explode("\n", $outputSnapshot2);

                            $lines2 = [];
                            foreach ($explResult2 as $item2) {
                                if ($item2 != '') {
                                    $lines2[] = str_ireplace(["\t\t", "\t"], " ", $item2);
                                }
                            }

                            foreach ($lines2 as $line2) {
                                $explLine2 = explode(' ', $line2);
                                $actualResults['response'][$key]['data'][] = $explLine2[4];
                            }
                        }
                    }
                }

                $actualResults['status'] = $status;
                $actualResults['nameserver'] = $this->authorityServers[0];
                return $actualResults;
            } else {
                return [
                    'type' => 'DOMAINNS',
                    'response' => [],
                    'status' => $status,
                    'nameserver' => $this->authorityServers[0],
                ];
            }

        } catch (\Exception $exception) {
            $this->errors[] = $exception->getMessage();
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
        try {
            if ($this->hasError())
                throw new \Exception('It has errors on construct. Please check it with getErrors function.');

            if (array_search($type, $this->supportedTypes) === false)
                throw new \Exception('That query not supported yet.');

            $command = $this->getAnswerCmd($this->domain, $type, $nameserver);
            $process = new Process($command);
            $process->setTimeout(1);
            $process->run();

            if (!$process->isSuccessful())
                throw new \Exception($process->getErrorOutput());

            $outputSnapshot = $process->getOutput();

            if ($outputSnapshot == '')
                throw new \Exception('Could not get any response.');

            $explOutput = explode("\n", $outputSnapshot);

            // fetch and clean comments from output
            $comments = [];
            for ($i = 0; $i < 6; $i++) {
                if ($explOutput[$i] != '') {
                    $comments[] = $explOutput[$i];
                    unset($explOutput[$i]);
                } else {
                    unset($explOutput[$i]);
                }
            }
            $explComments = explode(',', $comments[1]);

            // DNS Server Response
            $status = trim(str_replace('status:', '', $explComments[1]));

            // resort output
            ksort($explOutput);

            if ($status == 'NOERROR') {
                // clean comments
                $lines = [];
                foreach ($explOutput as $item) {
                    if ($item != ''
                        && substr($item, 0, 1) != ';') {
                        $lines[] = str_ireplace(["\t\t", "\t"], " ", $item);
                    }
                }

                $actualResults = [];
                $actualResults['type'] = $type;
                $actualResults['status'] = $status;
                $actualResults['nameserver'] = $nameserver;
                $actualResults['response'] = [];
                switch ($type) {
                    case 'A':
                        foreach ($lines as $line) {
                            $explLine = explode(' ', $line);
                            if ($explLine[3] == 'A') {
                                $actualResults['response'][] = [
                                    'ttl' => (int)$explLine[1],
                                    'data' => $explLine[4]
                                ];
                            }
                        }
                        break;
                    case 'AAAA':
                        foreach ($lines as $line) {
                            $explLine = explode(' ', $line);
                            if ($explLine[3] == 'AAAA') {
                                $actualResults['response'][] = [
                                    'ttl' => (int)$explLine[1],
                                    'data' => $explLine[4]
                                ];
                            }
                        }
                        break;
                    case 'CNAME':
                        foreach ($lines as $line) {
                            $explLine = explode(' ', $line);
                            if ($explLine[3] == 'CNAME') {
                                $actualResults['response'][] = [
                                    'ttl' => (int)$explLine[1],
                                    'data' => rtrim($explLine[4], '.')
                                ];
                            }
                        }
                        break;
                    case 'MX':
                        foreach ($lines as $line) {
                            $explLine = explode(' ', $line);
                            if ($explLine[3] == 'MX') {
                                $actualResults['response'][] = [
                                    'ttl' => (int)$explLine[1],
                                    'priority' => (int)$explLine[4],
                                    'data' => rtrim($explLine[5], '.')
                                ];
                            }
                        }
                        break;
                    case 'NS':
                        foreach ($lines as $line) {
                            $explLine = explode(' ', $line);
                            if ($explLine[3] == 'NS') {
                                $host = rtrim($explLine[4], '.');
                                if (!isset($actualResults['response'][$host])) {
                                    $actualResults['response'][$host] = [
                                        'ttl' => (int)$explLine[1],
                                        'data' => []
                                    ];

                                    $command2 = $this->getAnswerCmd($host, 'A', null);
                                    $command2 = str_ireplace('+comments', '', $command2);
                                    $process2 = new Process($command2);
                                    $process2->setTimeout(1);
                                    $process2->run();

                                    if ($process2->isSuccessful()) {
                                        $outputSnapshot2 = $process2->getOutput();
                                        if ($outputSnapshot2 === '')
                                            continue;
                                        else
                                            $explResult2 = explode("\n", $outputSnapshot2);

                                        $lines2 = [];
                                        foreach ($explResult2 as $item2) {
                                            if ($item2 != '') {
                                                $lines2[] = str_ireplace(["\t\t", "\t"], " ", $item2);
                                            }
                                        }

                                        foreach ($lines2 as $line2) {
                                            $explLine2 = explode(' ', $line2);
                                            $actualResults['response'][$host]['data'][] = $explLine2[4];
                                        }
                                    }
                                }
                            }
                        }
                        break;
                    case 'SOA':
                        $explLine1 = explode(' ', $lines[0]);
                        if ($explLine1[3] == 'SOA') {
                            $actualResults['response']['primaryNs'] = rtrim($explLine1[4], '.');
                            $actualResults['response']['hostmaster'] = rtrim($explLine1[5], '.');
                            unset($lines[0]);
                            foreach ($lines as $line) {
                                if (stristr($line, ';')) {
                                    $explLine2 = explode(';', $line);
                                    if (stristr($explLine2[1], 'serial')) {
                                        $actualResults['response']['serial'] = (int)trim($explLine2[0]);
                                    } elseif (stristr($explLine2[1], 'refresh')) {
                                        $actualResults['response']['refresh'] = (int)trim($explLine2[0]);
                                    } elseif (stristr($explLine2[1], 'retry')) {
                                        $actualResults['response']['retry'] = (int)trim($explLine2[0]);
                                    } elseif (stristr($explLine2[1], 'expire')) {
                                        $actualResults['response']['expire'] = (int)trim($explLine2[0]);
                                    } elseif (stristr($explLine2[1], 'minimum')) {
                                        $actualResults['response']['minimumTtl'] = (int)trim($explLine2[0]);
                                    }
                                }
                            }
                        }
                        break;
                    case 'TXT':
                        foreach ($lines as $line) {
                            $explLine = explode(' ', $line);
                            if ($explLine[3] == 'TXT') {
                                $ttl = $explLine[1];
                                unset($explLine[0], $explLine[1], $explLine[2], $explLine[3]);
                                ksort($explLine);
                                $data = implode(' ', $explLine);
                                $actualResults['response'][] = [
                                    'ttl' => (int)$ttl,
                                    'data' => trim($data, '"'),
                                ];
                            }
                        }
                        break;
                    default:
                        $actualResults['response'] = [];
                }

                return $actualResults;

            } else {
                return [
                    'type' => $type,
                    'response' => [],
                    'status' => $status,
                    'nameserver' => $nameserver,
                ];
            }

        } catch (\Exception $exception) {
            $this->errors[] = $exception->getMessage();
            return false;
        }
    }

}

/* path: ~src/AdvancedDns.php */