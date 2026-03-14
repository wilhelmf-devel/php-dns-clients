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
 *   php copy-internetx-2-inwx.php [--dry-run] [--ask-mfa]
 *
 * Configuration:
 *   Copy examples/config.ini.example to examples/config.ini and set:
 *   - [inwx] user / pass / sandbox
 *   - [internetx] user / pass / context or apikey
 *
 * Runtime note:
 *   If your InternetX account uses MFA, run the script with --ask-mfa and it
 *   will ask for the current 6 digit token at runtime.
 */

// === Require dependencies ===
require_once __DIR__ . '/../myInternetXApiClient.php';
require_once __DIR__ . '/../myInwxApiClient.php';

// === Parse arguments ===
$dryRun = in_array('--dry-run', $argv);
$askMfa = in_array('--ask-mfa', $argv);

// === 1. Load credentials ===
$configFile = __DIR__ . '/config.ini';
$config = @parse_ini_file($configFile, true, INI_SCANNER_TYPED);
if (!is_array($config)) {
	echo "Missing or unreadable config file: {$configFile}\n";
	echo "Copy examples/config.ini.example to examples/config.ini and adjust it.\n";
	exit(1);
}

$inwxUser = (string)($config['inwx']['user'] ?? '');
$inwxPass = (string)($config['inwx']['pass'] ?? '');
$inwxSandbox = !empty($config['inwx']['sandbox']);

$ixUser = (string)($config['internetx']['user'] ?? '');
$ixPass = (string)($config['internetx']['pass'] ?? '');
$ixContext = (string)($config['internetx']['context'] ?? '');
$ixApiKey = (string)($config['internetx']['apikey'] ?? '');

if ($inwxUser === '' || $inwxPass === '' || $ixContext === '') {
	echo "Config file is missing required INWX or InternetX values.\n";
	exit(1);
}

if ($ixApiKey === '' && ($ixUser === '' || $ixPass === '')) {
	echo "InternetX config requires either user/pass or apikey.\n";
	exit(1);
}

// === 2. Ask for domain name ===
echo "Enter the domain name (e.g. example.com): ";
$handle = fopen("php://stdin", "r");
$domain = trim(fgets($handle));
fclose($handle);

// === 3. Create API client objects ===
$ixToken = null;
if ($ixApiKey === '' && $askMfa) {
	echo "Enter the current InternetX MFA code: ";
	$handle = fopen("php://stdin", "r");
	$input = trim(fgets($handle));
	fclose($handle);

	if (!preg_match('/^\d{6}$/', $input)) {
		echo "InternetX MFA token must be 6 digits.\n";
		exit(1);
	}
	$ixToken = $input;
}

// Note that INWX offers sandbox setups with independent URLs and users
$inwx = new myInwxApiClient($inwxUser, $inwxPass, $inwxSandbox);
$internetX = new myInternetXApiClient(
	$ixApiKey === '' ? $ixUser : null,
	$ixApiKey === '' ? $ixPass : null,
	$ixContext,
	$ixApiKey === '' ? $ixToken : $ixApiKey
);


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
$records = $internetX->ZoneListRecords($domain);
if (empty($records) || !is_array($records)) {
	echo "No records found or ZoneListRecords failed. Exiting.\n";
	exit(1);
}

// === 8. Upload records to INWX (with NS/zone check and dry run) ===
echo "Processing records...\n";
$addedCount = 0;
foreach ($records as $record) {
	$type = strtoupper($record['type'] ?? '');
	if ($type === '') continue;

	// Skip SOA always
	if ($type === 'SOA') continue;

	// Skip NS if it's for the zone root (e.g., '@' or matches the domain name)
	$name = $record['name'] ?? '@';
	$fqdn = ($name === '@') ? $domain : rtrim($name . '.' . $domain, '.');
	if ($type === 'NS' && ($name === '@' || $fqdn === $domain)) {
		continue;
	}

	$content = $record['value'] ?? ($record['content'] ?? null);
	if ($content === null) continue;

	$ttl = isset($record['ttl']) ? (int)$record['ttl'] : 3600;
	if ($ttl<300) $ttl=300; // Min TTL of INWX is 300
	$prio = null;
	if (isset($record['pref'])) $prio = (int)$record['pref'];
	else if (isset($record['prio'])) $prio = (int)$record['prio'];

	// Fallback for payloads where prio is encoded in value (e.g., '10 mail.example.com.')
	if ($prio === null && in_array($type, ['MX', 'SRV']) && preg_match('/^(\d+)\s+(.*)$/', $content, $matches)) {
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
