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

If dns server don't respond or query not successful, false will return. If query successful, results will return as an array.

```php
$domain = new AdvancedDns('getdns.sh');
$result = $domain->lookup('NS', '8.8.8.8');
var_dump($result);
```

```php
$domain = new AdvancedDns('getdns.sh');
$result = $domain->lookup('A', '8.8.4.4');
var_dump($result);
```

```php
$domain = new AdvancedDns('getdns.sh');
$result = $domain->authorityNameserverLookup();
var_dump($result);
```
