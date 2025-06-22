# PHP DNS Clients

A simple and lightweight collection of single-file PHP classes for managing DNS zones and records via the APIs of various DNS providers.  
No autoloaders, no dependencies – just drop in the class you need.

## Supported Providers

- INWX (implemented)
- InternetX (Schlund, Leonex, ...) (implemented)
- Hetzner (implemented)
- [More to come: Nameshield, AWS Route53, Cloudfront]

## Features

- Minimal, readable PHP code
- One class per provider, one file each
- Focused on DNS zone and record management (list, add, delete, clone)
- No external dependencies

## Example: Using the INWX Client

```php
require 'myInwxApiClient.php';

$USER = 'your_inwx_username';
$PW = 'your_inwx_password';
$client = new myInwxApiClient($USER, $PW, true); // true = sandbox

// List all DNS zones
$zones = $client->ZonesList();
print_r($zones);

// Add a new A record
$client->ZoneAddRecord('example.com', 'www', 'A', '192.0.2.10');
```

## Why?

Most vendor APIs are overly complex, use auto-loaders, or require extra dependencies.
This project aims to provide “drop-in” single-file classes for scripting, CLI tools, and hobbyists.

## Contributions

Pull requests for new DNS vendors or improvements are welcome!
Please keep the spirit: single file, no dependencies, focus on DNS zone/record management.

## License

MIT
