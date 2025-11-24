<?php
/**
 * myHetznerCloudApiClient
 *
 * Simple, dependency-free PHP client for the **new Hetzner Cloud DNS API**.
 *
 * NOTE:
 *   - This class uses the Hetzner Cloud DNS endpoints under https://api.hetzner.cloud/v1
 *   - It does NOT talk to the old / deprecated standalone Hetzner DNS API.
 *   - DNS data is managed via Zones and RRSets (record sets), as required by the new API.
 *
 * Main features:
 *   - List zones, get a single zone, create and delete zones.
 *   - Export and import zone files (BIND format).
 *   - List record sets (RRSets) in a zone.
 *   - Add / set / remove DNS records via RRSet actions.
 *
 * Design goals:
 *   - Keep the style close to your other php-dns-clients: single class, no external deps.
 *   - Only minimal changes required in existing code (constructor + method names are simple).
 *   - No hard dependency on numeric zone or RR IDs; all public methods work with zone name
 *     and RR name/type (the Cloud API already supports id_or_name).
 *
 * Built with support from OpenAI Codex.
 */
class myHetznerCloudApiClient
{
    /** @var string */
    protected $apiToken;

    /** @var string */
    protected $apiBase;

    /** @var array */
    protected $curlOptions = array();

    /** @var array|null Last decoded JSON response (for debugging) */
    protected $lastResponse;

    /** @var int|null Last HTTP status code */
    protected $lastStatusCode;

    /**
     * Constructor.
     *
     * @param string $apiToken     Hetzner Cloud API token (with DNS permissions).
     * @param string $apiBase      Base URL (default: https://api.hetzner.cloud/v1).
     * @param array  $curlOptions  Optional extra curl options (CURLOPT_* => value).
     *
     * @throws RuntimeException if curl extension is not available.
     */
    public function __construct($apiToken, $apiBase = 'https://api.hetzner.cloud/v1', array $curlOptions = array())
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The cURL extension is required for myHetznerCloudApiClient.');
        }

        $this->apiToken    = $apiToken;
        $this->apiBase     = rtrim($apiBase, '/');
        $this->curlOptions = $curlOptions;
    }

    /**
     * Returns last decoded JSON response from the API (for debugging / logging).
     *
     * @return array|null
     */
    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    /**
     * Returns last HTTP status code from the API (for debugging / logging).
     *
     * @return int|null
     */
    public function getLastStatusCode()
    {
        return $this->lastStatusCode;
    }

    /**
     * List zones.
     *
     * Wrapper for: GET /zones
     *
     * @param string|null $name   Optional exact zone name to filter (e.g. "example.com").
     * @param string|null $mode   Optional zone mode: "primary" or "secondary".
     * @param array       $extraQuery Optional additional query params (label_selector, sort, page, per_page).
     *
     * @return array List of zones (array of zone objects).
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function ZonesList($name = null, $mode = null, array $extraQuery = array()) : array
    {
        $query = $extraQuery;

        if ($name !== null)
            $query['name'] = $name;

	if ($mode !== null && in_array($mode,array("primary","secondary")))
            $query['mode'] = $mode;

	$result = $this->request('GET', '/zones', $query);

	if ($mode !== null) 
		return isset($result['zones']) ? $result['zones'] : array();
 
	$return = array();
        foreach ($result['zones'] as $zone) $return[$zone['id']]=$zone['name'];
	return $return;
    }

    public function ZoneInfo($name) : ?array
    {
	    $result=$this->ZonesList($name,true);
	    if (!is_array($result) || !isset($result[0]) || !is_array($result[0]))
		    return null;
	    return $result[0];
    }

    /**
     * Get a single zone by ID or name.
     *
     * Wrapper for: GET /zones/{id_or_name}
     *
     * @param string|int $idOrName Zone ID or exact zone name (e.g. "example.com").
     *
     * @return array Zone object.
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function ZoneGet($idOrName)
    {
        $result = $this->request('GET', '/zones/' . rawurlencode($idOrName));

        return isset($result['zone']) ? $result['zone'] : $result;
    }

    /**
     * Create a new zone.
     *
     * Wrapper for: POST /zones
     *
     * @param string     $name   Zone name (lowercase, no trailing dot; e.g. "example.com").
     * @param string     $mode   "primary" or "secondary". DNS editing via API requires "primary".
     * @param int|null   $ttl    Default zone TTL (seconds) or null to use API default (3600).
     * @param array|null $labels Optional associative array of labels.
     * @param array|null $primaryNameservers
     *     For secondary zones: array of ['address' => 'ip', 'port' => 53] structures.
     *
     * @return array Created zone object.
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function ZoneCreate($name, $mode = 'primary', $ttl = null, array $labels = null, array $primaryNameservers = null)
    {
        $body = array(
            'name' => strtolower(rtrim($name, '.')),
            'mode' => $mode,
        );

        if ($ttl !== null) {
            $body['ttl'] = (int)$ttl;
        }
        if ($labels !== null) {
            $body['labels'] = $labels;
        }
        if (!empty($primaryNameservers)) {
            $body['primary_nameservers'] = $primaryNameservers;
        }

        $result = $this->request('POST', '/zones', null, $body);

	// return isset($result['zone']) ? $result['zone'] : $result;

	// Nameservers are automatically added to the zone.
	if (!empty($result['zone']['id']) && $result['zone']['name']==$name)
		return $result['zone']['id'];
	else 
		return null;
    }

    /**
     * Delete a zone.
     *
     * Wrapper for: DELETE /zones/{id_or_name}
     *
     * @param string|int $idOrName Zone ID or name.
     *
     * @return array API response (contains an "action" object).
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function ZoneDelete($idOrName)
    {
        return $this->request('DELETE', '/zones/' . rawurlencode($idOrName));
    }

    /**
     * Export a zone file (BIND format) for a given zone.
     *
     * Wrapper for: GET /zones/{id_or_name}/zonefile
     *
     * @param string|int $idOrName Zone ID or name.
     *
     * @return string Zone file content.
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function ZoneExportZonefile($idOrName)
    {
        $result = $this->request('GET', '/zones/' . rawurlencode($idOrName) . '/zonefile');

        return isset($result['zonefile']) ? $result['zonefile'] : '';
    }
    public function Zone_axfr($idOrName) { return $this->ZoneExportZonefile($idOrName); }

    /**
     * Import a zone file (BIND format) into a zone, replacing all existing RRSets.
     *
     * Wrapper for: POST /zones/{id_or_name}/actions/import_zonefile
     *
     * @param string|int $idOrName Zone ID or name.
     * @param string     $zonefile Zone file contents.
     *
     * @return array API response (contains an "action" object).
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function ZoneImportZonefile($idOrName, $zonefile)
    {
        $body = array('zonefile' => $zonefile);

        return $this->request('POST', '/zones/' . rawurlencode($idOrName) . '/actions/import_zonefile', null, $body);
    }

    /**
     * List RRSets (record sets) in a zone.
     *
     * Wrapper for: GET /zones/{id_or_name}/rrsets
     *
     * @param string|int  $zoneIdOrName Zone ID or name.
     * @param string|null $name         Optional RRSet name (relative, e.g. "@", "www").
     * @param array|null  $types        Optional array of RR types to filter (e.g. ['A','AAAA']).
     * @param array       $extraQuery   Extra query params (label_selector, sort, page, per_page).
     *
     * @return array List of rrsets.
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function RRSetsList($zoneIdOrName, $name = null, array $types = null, array $extraQuery = array())
    {
        $query = $extraQuery;

        if ($name !== null) {
            $query['name'] = $this->normalizeRecordName($name);
        }
        if ($types !== null && count($types) > 0) {
            // Hetzner allows "type" multiple times: we emulate by array query param.
            // In practice, we just pass 'type' => $types; the caller's HTTP client should encode it properly.
            $query['type'] = $types;
        }

        $result = $this->request('GET', '/zones/' . rawurlencode($zoneIdOrName) . '/rrsets', $query);

        return isset($result['rrsets']) ? $result['rrsets'] : array();
    }

    /**
     * Get a single RRSet.
     *
     * Wrapper for: GET /zones/{id_or_name}/rrsets/{rr_name}/{rr_type}
     *
     * @param string|int $zoneIdOrName Zone ID or name.
     * @param string     $rrName       Relative RR name, e.g. "@", "www".
     * @param string     $rrType       RR type, e.g. "A", "MX", "TXT".
     *
     * @return array RRSet object.
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function RRSetGet($zoneIdOrName, $rrName, $rrType)
    {
        $rrName = $this->normalizeRecordName($rrName);
        $rrType = strtoupper($rrType);

        $path   = '/zones/' . rawurlencode($zoneIdOrName)
                . '/rrsets/' . rawurlencode($rrName)
                . '/' . rawurlencode($rrType);

        $result = $this->request('GET', $path);

        return isset($result['rrset']) ? $result['rrset'] : $result;
    }

    /**
     * Create a new RRSet with a set of records.
     *
     * Wrapper for: POST /zones/{id_or_name}/rrsets
     *
     * @param string|int       $zoneIdOrName Zone ID or name.
     * @param string           $rrName       Relative RR name, e.g. "@", "www".
     * @param string           $rrType       RR type, e.g. "A", "MX", "TXT".
     * @param string|array     $values       Single value string or array of value strings,
     *                                      or array of ['value' => '...', 'comment' => '...'].
     * @param int|null         $ttl          Optional TTL (seconds; null -> use zone default).
     * @param string|null      $comment      Optional comment applied to all values (if not specified per value).
     * @param array|null       $labels       Optional associative array of labels.
     *
     * @return array Created rrset + action.
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function RRSetCreate($zoneIdOrName, $rrName, $rrType, $values, $ttl = null, $comment = null, array $labels = null)
    {
        $rrName = $this->normalizeRecordName($rrName);
        $rrType = strtoupper($rrType);

        $body = array(
            'name'    => $rrName,
            'type'    => $rrType,
            'records' => $this->buildRecordsArray($values, $comment, $rrType),
        );

        if ($ttl !== null) {
            $body['ttl'] = (int)$ttl;
        }
        if ($labels !== null) {
            $body['labels'] = $labels;
        }

        $path   = '/zones/' . rawurlencode($zoneIdOrName) . '/rrsets';
        $result = $this->request('POST', $path, null, $body);

        return $result;
    }

    /**
     * Delete an entire RRSet.
     *
     * Wrapper for: DELETE /zones/{id_or_name}/rrsets/{rr_name}/{rr_type}
     *
     * @param string|int $zoneIdOrName Zone ID or name.
     * @param string     $rrName       Relative RR name, e.g. "@", "www".
     * @param string     $rrType       RR type.
     *
     * @return array API response (action object).
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function RRSetDelete($zoneIdOrName, $rrName, $rrType)
    {
        $rrName = $this->normalizeRecordName($rrName);
        $rrType = strtoupper($rrType);

        $path = '/zones/' . rawurlencode($zoneIdOrName)
              . '/rrsets/' . rawurlencode($rrName)
              . '/' . rawurlencode($rrType);

        return $this->request('DELETE', $path);
    }

    /**
     * Overwrite all records of an existing RRSet.
     *
     * Wrapper for: POST /zones/{id_or_name}/rrsets/{rr_name}/{rr_type}/actions/set_records
     *
     * @param string|int   $zoneIdOrName Zone ID or name.
     * @param string       $rrName       Relative RR name, e.g. "@", "www".
     * @param string       $rrType       RR type.
     * @param string|array $values       Single value string or array of value strings,
     *                                  or array of ['value' => '...', 'comment' => '...'].
     * @param int|null     $ttl          Optional TTL (seconds).
     * @param string|null  $comment      Optional comment applied to all values.
     *
     * @return array API response (action object).
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function RRSetSetRecords($zoneIdOrName, $rrName, $rrType, $values, $ttl = null, $comment = null)
    {
        $rrName = $this->normalizeRecordName($rrName);
        $rrType = strtoupper($rrType);

        $path = '/zones/' . rawurlencode($zoneIdOrName)
              . '/rrsets/' . rawurlencode($rrName)
              . '/' . rawurlencode($rrType)
              . '/actions/set_records';

        $body = array(
            'records' => $this->buildRecordsArray($values, $comment, $rrType),
        );

        if ($ttl !== null) {
            $body['ttl'] = (int)$ttl;
        }

        return $this->request('POST', $path, null, $body);
    }

    /**
     * Add records to an RRSet (create RRSet if it doesn’t exist).
     *
     * Wrapper for: POST /zones/{id_or_name}/rrsets/{rr_name}/{rr_type}/actions/add_records
     *
     * @param string|int   $zoneIdOrName Zone ID or name.
     * @param string       $rrName       Relative RR name, e.g. "@", "www".
     * @param string       $rrType       RR type.
     * @param string|array $values       Single value or array of values / record arrays.
     * @param int|null     $ttl          TTL (seconds), optional.
     * @param string|null  $comment      Optional comment applied to all added values.
     *
     * @return array API response (action object).
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function RRSetAddRecords($zoneIdOrName, $rrName, $rrType, $values, $ttl = null, $comment = null)
    {
        $rrName = $this->normalizeRecordName($rrName);
        $rrType = strtoupper($rrType);

        $path = '/zones/' . rawurlencode($zoneIdOrName)
              . '/rrsets/' . rawurlencode($rrName)
              . '/' . rawurlencode($rrType)
              . '/actions/add_records';

        $body = array(
            'records' => $this->buildRecordsArray($values, $comment, $rrType),
        );

        if ($ttl !== null) {
            $body['ttl'] = (int)$ttl;
        }

        return $this->request('POST', $path, null, $body);
    }

    /**
     * Remove specific records from an RRSet.
     *
     * Wrapper for: POST /zones/{id_or_name}/rrsets/{rr_name}/{rr_type}/actions/remove_records
     *
     * @param string|int   $zoneIdOrName Zone ID or name.
     * @param string       $rrName       Relative RR name, e.g. "@", "www".
     * @param string       $rrType       RR type.
     * @param string|array $values       Value(s) to remove; must match existing records.
     * @param string|null  $comment      Optional comment to match (if your existing records use comments).
     *
     * @return array API response (action object).
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function RRSetRemoveRecords($zoneIdOrName, $rrName, $rrType, $values, $comment = null)
    {
        $rrName = $this->normalizeRecordName($rrName);
        $rrType = strtoupper($rrType);

        $path = '/zones/' . rawurlencode($zoneIdOrName)
              . '/rrsets/' . rawurlencode($rrName)
              . '/' . rawurlencode($rrType)
              . '/actions/remove_records';

        $body = array(
            'records' => $this->buildRecordsArray($values, $comment, $rrType),
        );

        return $this->request('POST', $path, null, $body);
    }

    /**
     * Convenience: list "records" for a zone (returns the RRSet list).
     *
     * @param string|int  $zoneIdOrName Zone ID or name.
     * @param string|null $name         Optional RRSet name filter.
     * @param array|null  $types        Optional RR types filter.
     *
     * @return array List of rrsets.
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function ZoneListRecords($zoneIdOrName, $name = null, array $types = null)
    {
        return $this->RRSetsList($zoneIdOrName, $name, $types);
    }

    /**
     * Convenience: add a single DNS record (RR) via add_records.
     *
     * This will create the RRSet if needed; otherwise it appends to the existing RRSet.
     *
     * @param string|int $zoneIdOrName Zone ID or name.
     * @param string     $name         Relative RR name, e.g. "@", "www".
     * @param string     $type         RR type.
     * @param string     $value        Record content.
     * @param int|null   $ttl          TTL (seconds).
     * @param string|null $comment     Optional comment.
     *
     * @return array API response (action object).
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function ZoneAddRecord($zoneIdOrName, $name, $type, $value, $ttl = null, $comment = null)
    {
        return $this->RRSetAddRecords($zoneIdOrName, $name, $type, $value, $ttl, $comment);
    }

    /**
     * Convenience wrapper to keep compatibility with the old client: add multiple
     * records in one call by iterating over the provided entries.
     *
     * Expected entry keys (per record):
     *  - type (required)
     *  - name (defaults to "@")
     *  - value / values / records (one of these is required)
     *  - ttl (optional)
     *  - comment (optional)
     *
     * @param string|int $zoneIdOrName Zone ID or name.
     * @param array      $entries      Array of record definitions.
     *
     * @return array List of API responses per record.
     */
    public function ZoneAddRecords($zoneIdOrName, array $entries) : array
    {
        $responses = array();

        foreach ($entries as $idx => $entry) {
            if (!is_array($entry) || empty($entry['type'])) {
                $responses[$idx] = array('error' => 'Invalid entry: missing type');
                continue;
            }

            $name    = isset($entry['name']) ? $entry['name'] : '@';
            $values  = $entry['value']  ?? null;
            $values  = $entry['values'] ?? $values;
            $values  = $entry['records'] ?? $values;
            $ttl     = $entry['ttl'] ?? null;
            $comment = $entry['comment'] ?? null;

            if ($values === null) {
                $responses[$idx] = array('error' => 'Invalid entry: missing value/values/records');
                continue;
            }

            try {
                $responses[$idx] = $this->RRSetAddRecords($zoneIdOrName, $name, $entry['type'], $values, $ttl, $comment);
            } catch (\RuntimeException $e) {
                $responses[$idx] = array('error' => $e->getMessage());
            }
        }

        return $responses;
    }

    /**
     * Convenience: overwrite all records of an RRSet with a new value / list of values.
     *
     * @param string|int   $zoneIdOrName Zone ID or name.
     * @param string       $name         Relative RR name, e.g. "@", "www".
     * @param string       $type         RR type.
     * @param string|array $values       New value(s).
     * @param int|null     $ttl          TTL (seconds).
     * @param string|null  $comment      Optional comment.
     *
     * @return array API response (action object).
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function ZoneSetRecords($zoneIdOrName, $name, $type, $values, $ttl = null, $comment = null)
    {
        return $this->RRSetSetRecords($zoneIdOrName, $name, $type, $values, $ttl, $comment);
    }

    /**
     * Convenience: delete a record / RRSet.
     *
     * If $value is null, the entire RRSet is deleted (DELETE rrset).
     * If $value is not null, we call remove_records to remove that specific value.
     *
     * @param string|int      $zoneIdOrName Zone ID or name.
     * @param string          $name         Relative RR name, e.g. "@", "www".
     * @param string          $type         RR type.
     * @param string|array|null $value      Optional value(s) to remove. Null = delete whole RRSet.
     * @param string|null     $comment      Optional comment to match.
     *
     * @return array API response (action object).
     *
     * @throws RuntimeException on HTTP or API errors.
     */
    public function ZoneDeleteRecord($zoneIdOrName, $name, $type, $value = null, $comment = null)
    {
        if ($value === null) {
            // Delete the entire RRSet.
            return $this->RRSetDelete($zoneIdOrName, $name, $type);
        }

        // Remove only the specific record(s).
        return $this->RRSetRemoveRecords($zoneIdOrName, $name, $type, $value, $comment);
    }

    /**
     * Compatibility wrapper: delete multiple records or RRSets in one call.
     *
     * Each entry can specify:
     *  - type (required)
     *  - name (defaults to "@")
     *  - value/values/records (optional: omit to delete whole RRSet)
     *  - comment (optional)
     *
     * @param string|int $zoneIdOrName Zone ID or name.
     * @param array      $entries      Array of delete instructions.
     *
     * @return array List of API responses per entry.
     */
    public function ZoneDeleteRecords($zoneIdOrName, array $entries) : array
    {
        $responses = array();

        foreach ($entries as $idx => $entry) {
            if (!is_array($entry) || empty($entry['type'])) {
                $responses[$idx] = array('error' => 'Invalid entry: missing type');
                continue;
            }

            $name    = isset($entry['name']) ? $entry['name'] : '@';
            $values  = $entry['value']  ?? null;
            $values  = $entry['values'] ?? $values;
            $values  = $entry['records'] ?? $values;
            $comment = $entry['comment'] ?? null;

            try {
                $responses[$idx] = $this->ZoneDeleteRecord($zoneIdOrName, $name, $entry['type'], $values, $comment);
            } catch (\RuntimeException $e) {
                $responses[$idx] = array('error' => $e->getMessage());
            }
        }

        return $responses;
    }

    /**
     * Clone a zone into a new zone by name (compatibility with old client).
     *
     * @param string|int $fromZoneIdOrName Source zone ID or name.
     * @param string     $toDomain         Target zone name.
     *
     * @return bool True on success, false on failure.
     */
    public function ZoneClone($fromZoneIdOrName, $toDomain) : bool
    {
        // Reject if target already exists.
        try {
            $existing = $this->ZoneGet($toDomain);
            if (!empty($existing)) {
                return false;
            }
        } catch (\RuntimeException $e) {
            // Expected if zone does not exist; ignore.
        }

        $sourceZone = $this->ZoneGet($fromZoneIdOrName);
        if (empty($sourceZone) || empty($sourceZone['name'])) {
            return false;
        }

        $mode   = $sourceZone['mode'] ?? 'primary';
        $ttl    = $sourceZone['ttl']  ?? null;
        $labels = $sourceZone['labels'] ?? null;
        $primaryNameservers = $sourceZone['primary_nameservers'] ?? null;

        // Create target zone with full payload, then fall back to a minimal payload if needed.
        $createError = null;
        try {
            $newZoneId = $this->ZoneCreate($toDomain, $mode, $ttl, $labels, $primaryNameservers);
        } catch (\RuntimeException $e) {
            $createError = $e;
            $newZoneId = null;
        }

        if (empty($newZoneId)) {
            $fallbackBody = array(
                'name' => strtolower(rtrim($toDomain, '.')),
                'mode' => $mode ?? 'primary',
            );

            if (($fallbackBody['mode'] === 'secondary') && !empty($primaryNameservers)) {
                $fallbackBody['primary_nameservers'] = $primaryNameservers;
            }

            try {
                $result = $this->request('POST', '/zones', null, $fallbackBody);
                if (!empty($result['zone']['id'])) {
                    $newZoneId = $result['zone']['id'];
                }
            } catch (\RuntimeException $e) {
                if ($createError === null) {
                    $createError = $e;
                }
            }
        }

        if (empty($newZoneId)) {
            if ($createError !== null) {
                throw $createError;
            }
            return false;
        }

        // Copy rrsets, skipping SOA and apex NS as the API manages those.
        $rrsets = $this->ZoneListRecords($fromZoneIdOrName);
        foreach ($rrsets as $rrset) {
            $type = strtoupper($rrset['type']);
            $name = $rrset['name'];
            if ($type === 'SOA' || ($name === '@' && $type === 'NS')) {
                continue;
            }

            $ttlForClone = $rrset['ttl'] ?? ($sourceZone['ttl'] ?? 3600);

            $values = array();
            foreach ($rrset['records'] as $record) {
                $values[] = array(
                    'value'   => $record['value'],
                    'comment' => $record['comment'] ?? null,
                );
            }

            try {
                // RRSetAddRecords creates the RRSet if it does not exist and works well for cloning.
                $this->RRSetAddRecords($toDomain, $name, $type, $values, $ttlForClone, null);
            } catch (\RuntimeException $e) {
                // Surface the first error to the caller instead of silently skipping.
                throw $e;
            }
        }

        return true;
    }

    /**
     * Convenience: clone by source zone ID (compat).
     *
     * @param string|int $fromZoneId Source zone ID.
     * @param string     $toDomain   Target zone name.
     *
     * @return bool
     */
    public function ZoneCloneID($fromZoneId, $toDomain) : bool
    {
        return $this->ZoneClone($fromZoneId, $toDomain);
    }

    /**
     * Low-level HTTP request helper.
     *
     * @param string     $method HTTP method: GET, POST, PUT, DELETE, ...
     * @param string     $path   API path starting with '/', e.g. '/zones'.
     * @param array|null $query  Optional query parameters.
     * @param array|null $body   Optional JSON body for non-GET.
     *
     * @return array Decoded JSON response.
     *
     * @throws RuntimeException on transport or API errors.
     */
    protected function request($method, $path, array $query = null, $body = null)
    {
        $url = $this->apiBase . $path;

        if (!empty($query)) {
            // Let http_build_query encode arrays; Hetzner accepts repeated parameters.
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init();
        $headers = array(
            'Authorization: Bearer ' . $this->apiToken,
            'Accept: application/json',
        );

        $opts = array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => 30,
        );

        if ($body !== null && strtoupper($method) !== 'GET') {
            try {
                $jsonBody = json_encode(
                    $body,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
                );
            } catch (\JsonException $e) {
                throw new \RuntimeException('Failed to encode JSON body: ' . $e->getMessage());
            }

            $headers[]  = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = $jsonBody;
        }

        // Merge user-specified curl options (override defaults if needed).
        foreach ($this->curlOptions as $opt => $value) {
            $opts[$opt] = $value;
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($ch, $opts);

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($responseBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP request failed: ' . $err);
        }

        curl_close($ch);

        $this->lastStatusCode = $httpCode;

        $decoded = json_decode($responseBody, true);
        $this->lastResponse = $decoded;

        if ($httpCode >= 400) {
            $msg = 'Hetzner Cloud API error, HTTP ' . $httpCode;

            if (is_array($decoded) && isset($decoded['error']['message'])) {
                $msg .= ': ' . $decoded['error']['message'];
            }

            throw new \RuntimeException($msg);
        }

        if ($decoded === null && $responseBody !== '' && $responseBody !== 'null') {
            // Unexpected non-JSON payload.
            throw new \RuntimeException('Hetzner Cloud API returned non-JSON response: ' . $responseBody);
        }

        return $decoded ?: array();
    }

    /**
     * Normalize RR name: keep "@" for apex, otherwise lower-case and strip trailing dot.
     *
     * @param string $name
     * @return string
     */
    protected function normalizeRecordName($name)
    {
        $name = trim($name);

        if ($name === '' || $name === '@') {
            return '@';
        }

        $name = rtrim($name, '.');

        return strtolower($name);
    }

    /**
     * Build an array of record objects for the RRSet actions.
     *
     * @param string|array $values   String, array of strings, or array of ['value'=>..,'comment'=>..].
     * @param string|null  $comment  Default comment if values are plain strings.
     * @param string|null  $rrType   RR type (needed to quote TXT values correctly).
     *
     * @return array
     */

    protected function buildRecordsArray($values, $comment = null, $rrType = null)
    {
        $records = array();
    
        if (is_string($values)) {
            $values = array($values);
        }
    
        foreach ($values as $v) {
            if (is_array($v)) {
                if (isset($v['value']) && $rrType === 'TXT') {
                    $v['value'] = $this->normalizeTxtValue($v['value']);
                }
                $records[] = $v;
            } else {
                if ($rrType === 'TXT') {
                    $v = $this->normalizeTxtValue($v);
                }
    
                $records[] = array(
                    'value'   => $v,
                    'comment' => $comment,
                );
            }
        }
    
        return $records;
    }

    /**
     * Normalize TXT record value for Hetzner:
     * - If already wrapped in double quotes, return as-is.
     * - Otherwise:
     *   - Escape any unescaped inner double quotes with backslashes.
     *   - Wrap the whole value in double quotes.
     *
     * @param string $value
     * @return string
     */
    protected function normalizeTxtValue($value)
    {
        $value = trim((string)$value);
    
        // Already quoted: assume caller did correct escaping, don't touch it.
        if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            return $value;
        }
    
        // Not quoted: escape unescaped double quotes...
        $value = preg_replace('/(?<!\\\\)"/', '\\"', $value);
    
        // ...and wrap with double quotes.
        return '"' . $value . '"';
    }

}
