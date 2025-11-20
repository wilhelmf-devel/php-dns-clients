#!/usr/bin/env php
<?php
/**
 * ACME helper for INWX DNS.
 *
 * Intended use:
 *   - For Apache httpd mod_md with MDChallengeDns01 using INWX as DNS provider.
 *   - Creates and removes _acme-challenge TXT records for ACME dns-01 validation.
 *
 * How mod_md calls this (conceptually):
 *   MDChallengeDns01Program "/path/to/this/script.php"
 *
 *   mod_md typically invokes:
 *     script.php setup   <domain> <token>
 *     script.php teardown <domain> <token>
 *
 * Manual usage (e.g. testing or emergency cleanup):
 *   ./script.php setup <domain> <token>
 *   ./script.php teardown <domain>           # delete ALL _acme-challenge TXT records
 *   ./script.php teardown <domain> <token>   # delete only records with NULL or matching content
 *
 * Security notes:
 *   - It is strongly recommended to create a dedicated INWX API user with the
 *     minimal permissions required to manage only the relevant DNS zones.
 */

require_once __DIR__ . '/myInwxApiClient.php';

$acmePrefix = "_acme-challenge";

/*
 * WARNING:
 *   These are placeholders. Do not put real credentials here in any public repo.
 *   Prefer something like:
 *     - environment variables
 *     - a local config file outside version control
 *     - a secrets manager
 */
$inwxUser    = 'USERNAME';
$inwxPass    = 'PASSWORD';
$inwxSandbox = false;

function fail($msg, $code = 1) {
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

function ok($msg = "") {
    if ($msg !== "") {
        fwrite(STDOUT, $msg . PHP_EOL);
    }
    exit(0);
}

/* --------------------------------------------------------------- */
/* Argument parsing                                                */
/* --------------------------------------------------------------- */
if ($argc < 3) {
    fail("Usage: setup <domain> <token> | teardown <domain> [token]");
}

$action = strtolower($argv[1]);
$domain = strtolower($argv[2]);
$token  = $argv[3] ?? null;

if (!in_array($action, ["setup", "teardown"], true)) {
    fail("Invalid action. Must be 'setup' or 'teardown'.");
}

/* --------------------------------------------------------------- */
/* INWX client init                                                */
/* --------------------------------------------------------------- */
$api = new myInwxApiClient($inwxUser, $inwxPass, $inwxSandbox);
if (!$api) {
    fail("INWX API login failed");
}

/* --------------------------------------------------------------- */
/* Check if the domain exists                                      */
/* --------------------------------------------------------------- */
$domainInfo = $api->ZonesList();
if (!is_array($domainInfo) || !in_array($domain, $domainInfo, true)) {
    fail("Domain '$domain' not found in INWX account");
}

/* --------------------------------------------------------------- */
/* SETUP: create TXT record                                        */
/* --------------------------------------------------------------- */
if ($action === "setup") {
    if ($token === null || $token === "") {
        fail("Missing token for setup");
    }

    if (!$api->ZoneAddRecord($domain, $acmePrefix, 'TXT', $token, 300)) {
        fail("Failed to create TXT record for " . $acmePrefix . "." . $domain);
    }

    // DNS propagation check
    // Since sometime caches get in our way this is not always helpful.
    // Nevertheless we should at least wait 90 seconds (18 runs).
    // Recommended would be a bit over 300 seconds (e.g. 61-65 runs).
    $fqdn         = $acmePrefix . "." . $domain;
    $maxRuns      = 65;
    $sleepSeconds = 5;

    for ($i = 0; $i < $maxRuns; $i++) {
        sleep($sleepSeconds);

        // suppress warnings if resolver is unhappy
        $dnsRecords = @dns_get_record($fqdn, DNS_TXT);

        if ($dnsRecords === false || !is_array($dnsRecords)) {
            // no usable result this round, try again
            continue;
        }

        foreach ($dnsRecords as $r) {
            // dns_get_record() for TXT typically uses 'txt' for the value
            if (isset($r['txt']) && $r['txt'] === $token) {
                ok("TXT record created and visible in DNS for {$fqdn}.");
            }
        }
    }

    // Since Let's encrypt is not checking the cache of our internal nameserver we exit
    // with OK even if we did not find the DNS entry
    ok(
        "TXT record '{$fqdn}' with the expected token did not become visible " .
        "in local DNS after " . ($maxRuns * $sleepSeconds) . " seconds."
    );
}

/* --------------------------------------------------------------- */
/* TEARDOWN: need to find matching record(s) and delete them all   */
/* --------------------------------------------------------------- */
if ($action === "teardown") {

    $records = $api->ZoneListRecords($domain);

    if (!is_array($records)) {
        fail("Could not read records for domain '$domain'");
    }

    $count = 0;
    $okDel = 0;

    foreach ($records as $rec) {
        if (
            isset($rec["name"], $rec["type"], $rec["content"], $rec["id"]) &&
            $rec["name"] === $acmePrefix . "." . $domain &&
            $rec["type"] === "TXT"
        ) {
            $matchesContent =
                ($token === null) ||            // no token: delete all _acme-challenge TXT
                $rec["content"] === $token;     // or matching token

            if ($matchesContent) {
                $count++;
                if ($api->ZoneDeleteRecordId($rec["id"])) {
                    $okDel++;
                }
            }
        }
    }

    if ($count === 0) {
        fail("Record not found");
    }
    if ($count > $okDel) {
        fail("Deleted only " . $okDel . " of " . $count . " records.");
    }

    ok("Deleted all " . $okDel . " records.");
}
