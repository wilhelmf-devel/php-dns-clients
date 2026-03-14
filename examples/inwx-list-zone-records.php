#!/usr/bin/env php
<?php
/**
 * Query INWX DNS records across all zones.
 *
 * Examples:
 *   php inwx-list-zone-records.php --type=MX
 *   php inwx-list-zone-records.php --type=TXT --name=@
 *   php inwx-list-zone-records.php --name=www
 *   php inwx-list-zone-records.php --name=autoconfigure
 *   php inwx-list-zone-records.php --spf
 *   php inwx-list-zone-records.php --domain=example.com --type=SRV
 *   php inwx-list-zone-records.php --type=TXT --name=@ --contains=v=spf1
 *   php inwx-list-zone-records.php --want=spf --want=mx --want=name:autodiscover --want=type:SRV,name:_autodiscover._tcp
 *
 * Configuration:
 *   Copy examples/config.ini.example to examples/config.ini and set
 *   the [inwx] user / pass / sandbox values.
 *
 * Output format:
 *   domain<TAB>name<TAB>type<TAB>ttl<TAB>prio<TAB>content<TAB>matched
 *
 * Example output:
 *   example.com<TAB>@<TAB>MX<TAB>3600<TAB>10<TAB>mx1.example.net.<TAB>mx
 *   example.net<TAB>@<TAB>TXT<TAB>3600<TAB><TAB>v=spf1 mx -all<TAB>spf
 */

require_once __DIR__ . '/../myInwxApiClient.php';

$args = array_slice($argv, 1);
$json = in_array('--json', $args, true);

$typeFilter = null;
$nameFilter = null;
$domainFilter = null;
$containsFilter = null;
$wants = [];

foreach ($args as $arg) {
    if (strpos($arg, '--type=') === 0) $typeFilter = strtoupper(substr($arg, 7));
    if (strpos($arg, '--name=') === 0) $nameFilter = substr($arg, 7);
    if (strpos($arg, '--domain=') === 0) $domainFilter = strtolower(rtrim(substr($arg, 9), '.'));
    if (strpos($arg, '--contains=') === 0) $containsFilter = substr($arg, 11);
    if (strpos($arg, '--want=') === 0) $wants[] = substr($arg, 7);
}

if (in_array('--spf', $args, true)) {
    $typeFilter = 'TXT';
    if ($containsFilter === null) $containsFilter = 'v=spf1';
}

function build_named_filter(string $label, ?string $type = null, ?string $name = null, ?string $contains = null): array
{
    return [
        'label' => $label,
        'type' => $type,
        'name' => $name,
        'contains' => $contains,
    ];
}

function parse_want_filter(string $want): array
{
    $want = trim($want);
    if ($want === '') throw new InvalidArgumentException('Empty --want filter');

    if (strcasecmp($want, 'spf') === 0) return build_named_filter('spf', 'TXT', null, 'v=spf1');
    if (strcasecmp($want, 'mx') === 0) return build_named_filter('mx', 'MX', null, null);

    if (strpos($want, 'name:') === 0) {
        $name = substr($want, 5);
        return build_named_filter('name:' . $name, null, $name, null);
    }

    $parts = array_map('trim', explode(',', $want));
    $filter = ['label' => $want, 'type' => null, 'name' => null, 'contains' => null];
    foreach ($parts as $part) {
        if ($part === '') continue;
        if (strpos($part, 'type:') === 0) $filter['type'] = strtoupper(substr($part, 5));
        elseif (strpos($part, 'name:') === 0) $filter['name'] = substr($part, 5);
        elseif (strpos($part, 'contains:') === 0) $filter['contains'] = substr($part, 9);
        else throw new InvalidArgumentException("Invalid --want fragment: {$part}");
    }

    if ($filter['type'] === null && $filter['name'] === null && $filter['contains'] === null) {
        throw new InvalidArgumentException("Invalid --want value: {$want}");
    }

    return $filter;
}

function record_matches_filter(string $type, string $name, string $domain, string $content, ?string $typeFilter, ?string $nameFilter, ?string $containsFilter): bool
{
    if ($typeFilter !== null && $type !== $typeFilter) return false;
    if (!record_name_matches($name, $domain, $nameFilter)) return false;
    if ($containsFilter !== null && stripos($content, $containsFilter) === false) return false;
    return true;
}

$namedFilters = [];
foreach ($wants as $want) $namedFilters[] = parse_want_filter($want);
if (empty($namedFilters)) {
    $namedFilters[] = build_named_filter('match', $typeFilter, $nameFilter, $containsFilter);
}

$configFile = __DIR__ . '/config.ini';
$config = @parse_ini_file($configFile, true, INI_SCANNER_TYPED);
if (!is_array($config)) {
    fwrite(STDERR, "Missing or unreadable config file: {$configFile}. Copy config.ini.example first.\n");
    exit(1);
}

$inwxUser = (string)($config['inwx']['user'] ?? '');
$inwxPass = (string)($config['inwx']['pass'] ?? '');
$sandbox = !empty($config['inwx']['sandbox']);

if ($inwxUser === '' || $inwxPass === '') {
    fwrite(STDERR, "Config file is missing required INWX credentials.\n");
    exit(1);
}

function record_name_matches(string $recordName, string $domain, ?string $wantedName): bool
{
    if ($wantedName === null || $wantedName === '') return true;

    $recordName = rtrim(strtolower($recordName), '.');
    $domain = rtrim(strtolower($domain), '.');
    $wantedName = rtrim(strtolower($wantedName), '.');

    if ($wantedName === '@') {
        return $recordName === '@' || $recordName === '' || $recordName === $domain;
    }

    if ($recordName === $wantedName) return true;
    if ($recordName === $wantedName . '.' . $domain) return true;

    return false;
}

function short_record_name(string $recordName, string $domain): string
{
    $recordName = rtrim($recordName, '.');
    $domain = rtrim($domain, '.');

    if ($recordName === '' || $recordName === '@' || strcasecmp($recordName, $domain) === 0) {
        return '@';
    }

    $suffix = '.' . $domain;
    if (strlen($recordName) > strlen($suffix) && strcasecmp(substr($recordName, -strlen($suffix)), $suffix) === 0) {
        return substr($recordName, 0, -strlen($suffix));
    }

    return $recordName;
}

$api = new myInwxApiClient($inwxUser, $inwxPass, $sandbox);
$zones = $api->ZonesList();
$rows = [];

foreach ($zones as $zone) {
    if ($domainFilter !== null && strtolower($zone) !== $domainFilter) continue;

    $records = $api->ZoneListRecords($zone);
    foreach ($records as $record) {
        $type = strtoupper((string)($record['type'] ?? ''));
        $name = (string)($record['name'] ?? '');
        $content = (string)($record['content'] ?? '');
        $ttl = isset($record['ttl']) ? (string)$record['ttl'] : '';
        $prio = isset($record['prio']) ? (string)$record['prio'] : '';
        $shortName = short_record_name($name, $zone);

        $matchedLabels = [];
        foreach ($namedFilters as $filter) {
            if (record_matches_filter($type, $name, $zone, $content, $filter['type'], $filter['name'], $filter['contains'])) {
                $matchedLabels[] = $filter['label'];
            }
        }
        if (empty($matchedLabels)) continue;

        $rows[] = [
            'domain' => $zone,
            'name' => $shortName,
            'type' => $type,
            'ttl' => $ttl,
            'prio' => $prio,
            'content' => $content,
            'matched' => implode(',', array_values(array_unique($matchedLabels))),
        ];
    }
}

usort($rows, function ($a, $b) {
    foreach (['domain', 'name', 'type', 'content'] as $key) {
        $cmp = strcmp((string)$a[$key], (string)$b[$key]);
        if ($cmp !== 0) return $cmp;
    }
    return 0;
});

if ($json) {
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

echo "domain\tname\ttype\tttl\tprio\tcontent\tmatched\n";
foreach ($rows as $row) {
    echo implode("\t", [
        $row['domain'],
        $row['name'],
        $row['type'],
        $row['ttl'],
        $row['prio'],
        $row['content'],
        $row['matched'],
    ]) . "\n";
}
