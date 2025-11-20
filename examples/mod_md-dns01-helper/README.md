# Apache mod_md dns-01 examples

This directory contains example scripts that integrate the PHP DNS clients
from this repository with [Apache httpd mod_md](https://httpd.apache.org/docs/2.4/mod/mod_md.html)
for the `dns-01` ACME challenge.

The scripts implement the interface expected by `MDChallengeDns01`:
they are called with:

```text
setup <domain> <token>
teardown <domain> <token>
teardown <domain>
```

and are responsible for creating and removing the corresponding
_acme-challenge.<domain> TXT record

## INWX helper (inwx-modmd-acme-helper.php)

This script uses `myInwxApiClient.php` from the repository root to manage DNS
records at INWX. It is intended to be used as `MDChallengeDns01` program.

### Requirements

- Apache httpd with `mod_md` enabled
- PHP CLI including php-xmlrpc and php-curl
- INWX account and API user with **minimal permissions** for the relevant zones

### Example Apache configuration

```apache
MDomain non-wildcard-example.org www.non-wildcard-example.org
MDCertificateAgreement accepted
#MDContactEmail #If not defined ServerAdmin will be used
#MDPrivateKeys secp256r1 rsa3072
MDPrivateKeys secp256r1
<IfModule ssl_module>
<VirtualHost *:443>
        ServerAdmin webmaster@non-wildcard-example.org
        ServerName non-wildcard-example.org
        ServerAlias www.non-wildcard-example.org

        SSLEngine on
        ...

</VirtualHost>
</IfModule>


<MDomain example.com *.example.com>
    MDCAChallenges dns-01
    MDChallengeDns01 /usr/local/bin/inwx-modmd-acme-helper.php
    MDRetryDelay 310
</MDomain>
LogLevel md:debug
<IfModule ssl_module>
<VirtualHost *:443>
        ServerAdmin webmaster@example.com
        ServerName example.com
        ServerAlias *.example.com
        SSLEngine on
        ...

</VirtualHost>
</IfModule>
```

