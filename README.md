# Advanced DNS
It collects DNS results using dig command.

### Supported DNS Query Types

- A
- AAAA
- CNAME
- MX
- NS
- SOA
- TXT

### Installation

```
composer require gurkanbicer/advanced-dns
```

```php
require 'vendor/autoload.php';
use \Gurkanbicer\AdvancedDns\AdvancedDns;
```

### Samples
#### Sample 1:
```php
$domain = new AdvancedDns('getdns.sh');
$result = $domain->lookup('NS', '8.8.8.8');
var_dump($result);
```
Output:
```
array(4) {
  ["type"]=>
  string(2) "NS"
  ["status"]=>
  string(7) "NOERROR"
  ["nameserver"]=>
  string(7) "8.8.8.8"
  ["response"]=>
  array(2) {
    ["amit.ns.cloudflare.com"]=>
    array(2) {
      ["ttl"]=>
      int(21599)
      ["data"]=>
      array(1) {
        [0]=>
        string(13) "173.245.59.63"
      }
    }
    ["april.ns.cloudflare.com"]=>
    array(2) {
      ["ttl"]=>
      int(21599)
      ["data"]=>
      array(1) {
        [0]=>
        string(13) "173.245.58.66"
      }
    }
  }
}
```
#### Sample 2:
```php
$domain = new AdvancedDns('getdns.sh');
$result = $domain->authorityNameserverLookup();
var_dump($result);
```
Output:
```
array(4) {
  ["type"]=>
  string(8) "DOMAINNS"
  ["response"]=>
  array(2) {
    ["amit.ns.cloudflare.com"]=>
    array(2) {
      ["ttl"]=>
      int(86400)
      ["data"]=>
      array(1) {
        [0]=>
        string(13) "173.245.59.63"
      }
    }
    ["april.ns.cloudflare.com"]=>
    array(2) {
      ["ttl"]=>
      int(86400)
      ["data"]=>
      array(1) {
        [0]=>
        string(13) "173.245.58.66"
      }
    }
  }
  ["status"]=>
  string(7) "NOERROR"
  ["nameserver"]=>
  string(9) "a2.nic.sh"
}
```
### Error Handling

```php
// if there is no error
if ($result !== false && $result['status'] == 'NOERROR') {
    var_dump($result);
} 
```
If there is no response that your queried host:
```
array(4) {
  ["type"]=>
  string(4) "AAAA"
  ["status"]=>
  string(7) "NOERROR"
  ["nameserver"]=>
  string(7) "8.8.8.8"
  ["response"]=>
  array(0) {
  }
}
```
If somethings goes bad or if has not in the list that your query type, output will be like:
```
bool(false)
```