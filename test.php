<?php
require 'vendor/autoload.php';
use \Gurkanbicer\AdvancedDns\AdvancedDns;
$domain = new AdvancedDns('getdns.sh');
$result = $domain->lookup('NS', '8.8.8.8');
var_dump($result);
$domain = new AdvancedDns('getdns.sh');
$result = $domain->authorityNameserverLookup();
var_dump($result);