<?php
/**
 * Example: Copy DNS Zone from InternetX to INWX
 *
 * This script demonstrates how to use the `myInternetXApiClient` and `myInwxApiClient`
 * classes to copy a DNS zone (all DNS records except SOA and apex NS) from
 * an InternetX account to an INWX account.
 *
 * Features:
 *   - Checks that the zone exists on InternetX, and does not exist yet on INWX
 *   - Optionally runs in dry-run mode to preview actions
 *   - Creates the zone on INWX and transfers all records (except SOA/apex NS)
 *   - Verifies and prints the number of records transferred
 *
 * Usage:
 *   php copy-internetx-2-inwx.php [--dry-run]
 *
 * Note: You must set your credentials for both providers before running!
 */

// === Require dependencies ===
require_once 'php-dns-clients/myInternetXApiClient.php';
require_once 'php-dns-clients/myInwxApiClient.php';

// === Parse arguments ===
$dryRun = in_array('--dry-run', $argv);

// === 1. Define API credentials ===
// Putting the credentials into the script is
// not neccessarly recommended for production 
$inwxUser = 'your_inwx_username';
$inwxPass = 'your_inwx_password';

$ixUser = 'your_internetx_username';
$ixPass = 'your_internetx_password';
$ixContext = '10'; // SchlundTech

// === 2. Ask for domain name ===
echo "Enter the domain name (e.g. example.com): ";
$handle = fopen("php://stdin", "r");
$domain = trim(fgets($handle));
fclose($handle);

// === 3. Create API client objects ===
echo "Enter the MFA code for InternetX if set: ";
$handle = fopen("php://stdin", "r");
$mfa = trim(fgets($handle));
fclose($handle);

if (!preg_match('/^\d{6}$/', $mfa)) {
	$mfa = null;
}

// Note that INWX offers sandbox setups with independent URLs and users
$inwx = new myInwxApiClient($inwxUser, $inwxPass);
$internetX = new myInternetXApiClient($ixUser, $ixPass, $ixContext,$mfa);


// Normalize: remove any trailing dot
$domain = rtrim($domain, '.');

if (empty($domain)) {
	echo "No domain provided. Exiting.\n";
	exit(1);
}

echo $dryRun ? "[DRY-RUN MODE ENABLED]\n" : "";

// === 4. Check if zone exists on Schlund ===
$ixZones = $internetX->ZonesList();
if (!in_array($domain, $ixZones)) {
	echo "Zone '$domain' does not exist on InternetX. Exiting.\n";
	exit(1);
}

// === 5. Check if zone exists on INWX ===
$inwxZones = $inwx->ZonesList();
if (in_array($domain, $inwxZones)) {
	echo "Zone '$domain' already exists on INWX. Exiting.\n";
	exit(1);
}

// === 6. Create the zone on INWX ===
echo "Creating zone on INWX...\n";
if (!$dryRun) {
	try {
		$inwx->ZoneCreate($domain);
	} catch (Exception $e) {
		echo "Failed to create zone on INWX: " . $e->getMessage() . "\n";
		exit(1);
	}
} else {
	echo "[Dry Run] Would create zone '$domain' on INWX.\n";
}

// === 7. Fetch the zone from Schlund ===
echo "Fetching zone records from InternetX...\n";
$records = $internetX->Zone_axfr($domain);
if (empty($records)) {
	echo "No records found or AXFR failed. Exiting.\n";
	exit(1);
}

// === 8. Upload records to INWX (with NS/zone check and dry run) ===
echo "Processing records...\n";
$addedCount = 0;
foreach ($records as $record) {
	$type = strtoupper($record['type']);

	// Skip SOA always
	if ($type === 'SOA') continue;

	// Skip NS if it's for the zone root (e.g., '@' or matches the domain name)
	$fqdn = rtrim($record['FQDN'], '.');
	if ($type === 'NS' && ($record['name'] === '@' || $fqdn === $domain)) {
		continue;
	}

	$name = $record['name'];
	$content = $record['content'];
	$ttl = (int) $record['ttl'];
	$prio = null;
	// $prio = $record['prio'] ?? null; // Prio not given seperatrly with the axfr function from InternetX
	//
	// Extract prio for MX and SRV (e.g., '10 mail.example.com.')
	if (in_array($type, ['MX', 'SRV']))
		if (preg_match('/^(\d+)\s+(.*)$/', $content, $matches)) {
			$prio = (int)$matches[1];
			$content = $matches[2];
		}


	if ($dryRun) {
		echo "[Dry Run] Would add: {$name}. {$type} {$content} TTL={$ttl}";
		if ($prio !== null) echo " PRIO={$prio}";
		echo "\n";
		$addedCount++;
	} else {
		try {
			$inwx->ZoneAddRecord($domain, $name, $type, $content, $ttl, $prio);
			$addedCount++;
		} catch (Exception $e) {
			echo "Failed to add record: {$name} {$type} {$ttl} {$prio} {$content} - " . $e->getMessage() . "\n";
		}
	}
}

if ($dryRun) {
	echo "[Dry Run] Would have added $addedCount records to INWX.\n";
} else {
	echo "Uploaded $addedCount records to INWX.\n";
}

// === 9. Confirm using ZoneListRecords ===
if ($dryRun) {
	echo "[Dry Run] Skipping final record verification.\n";
} else {
	$inwxRecords = $inwx->ZoneListRecords($domain);
	if (!empty($inwxRecords)) {
		echo "Zone '$domain' successfully copied to INWX with " . count($inwxRecords) . " record(s).\n";
	} else {
		echo "Zone appears on INWX but contains no records. Something went wrong.\n";
	}
}
