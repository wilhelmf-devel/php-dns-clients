<?php

// https://docs.nameshield.net/docs/getting-started
// https://docs.nameshield.net/docs/dns-api/

/**
 * myNameshieldApiClient
 *
 * Minimal PHP client for the Nameshield DNS API (v3). Provides basic zone and
 * record operations plus compatibility helpers to mirror the convenience
 * methods used in the other clients in this repository. Zone creation and
 * deletion are not supported by the Nameshield DNS API.
 *
 * Built with support from OpenAI Codex.
 */
/**
 * Note on test coverage:
 *
 * All functions, except DomainsList, are currently untested.
 * This is a limitation of the Nameshield API: user and domain name management
 * are available free of charge, while DNS zone configuration is a paid/premium
 * feature. As I did not have access to the premium DNS features, the
 * corresponding methods could not be tested.
 */

class myNameshieldApiClient
{
    private $token;
    private $baseUrl;
    private $registrarBaseUrl;
    private $apiHost;

    public function __construct($apiToken, $useSandbox = false)
    {
        $this->token = $apiToken;
        $this->apiHost = $useSandbox
            ? 'https://ote-api.nameshield.net'
            : 'https://api.nameshield.net';
        $this->baseUrl = $this->apiHost . '/dns/v3';
        $this->registrarBaseUrl = $this->apiHost . '/registrar/v1';
    }

    private function request($method, $endpoint, $body = null, $baseUrl = null)
    {
        $url = ($baseUrl ?? $this->baseUrl) . $endpoint;
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ];

        if ($body !== null) {
            $opts['http']['content'] = json_encode($body);
        }

        $context = stream_context_create($opts);
	$response = file_get_contents($url, false, $context);
	print_r($url); print_r($opts);

        if ($response === false) {
            throw new Exception("HTTP request failed: $method $url");
        }

        $statusLine = $http_response_header[0] ?? '';
        if (!preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $statusLine, $matches)) {
            throw new Exception("Unexpected HTTP response: $statusLine");
        }

        $statusCode = (int)$matches[1];
        $json = json_decode($response, true);

        if ($statusCode >= 400) {
            $errorMsg = 'Unknown error';
            if (is_array($json)) {
                if (isset($json['errors'][0]['message'])) {
                    $errorMsg = $json['errors'][0]['message'];
                } elseif (isset($json['message'])) {
                    $errorMsg = $json['message'];
                } elseif (isset($json['error'])) {
                    $errorMsg = $json['error'];
                } else {
                    $errorMsg = json_encode($json);
                }
            } else {
                $errorMsg = $response;
            }
            throw new Exception("API error $statusCode: $errorMsg");
        }

        return is_array($json) ? ($json['data'] ?? $json) : $response;
    }

    public function DomainsList()
    {
        // Registrar API endpoint for registered domains
        return $this->request('GET', '/domains', null, $this->registrarBaseUrl);
    }

    public function ZonesList()
    {
        return $this->request('GET', '/zones');
    }

    public function ZoneListRecords($zone)
    {
        return $this->request('GET', "/zones/{$zone}/records");
    }

    public function ZoneAddRecord($zone, $name, $type, $data, $ttl = 3600, $comment = '')
    {
        $record = [
            'name' => $name,
            'type' => $type,
            'data' => $data,
            'ttl' => $ttl,
        ];

        if ($comment !== '') {
            $record['comment'] = $comment;
        }

        return $this->request('POST', "/zones/{$zone}/records", $record);
    }

    /**
     * Delete a record by ID or by name/type/data (compatibility wrapper).
     *
     * @param string     $zone
     * @param int|string $nameOrId Record ID, or record name for compat usage.
     * @param string|null $type    Required if deleting by name.
     * @param string|null $data    Optional data filter when deleting by name.
     */
    public function ZoneDeleteRecord($zone, $nameOrId, $type = null, $data = null)
    {
        // If only ID is passed, delete directly.
        if ($type === null && is_numeric($nameOrId)) {
            return $this->request('DELETE', "/zones/{$zone}/records/{$nameOrId}");
        }

        // Delete by matching name/type (and optionally data).
        $records = $this->ZoneListRecords($zone);
        $type = strtoupper((string)$type);
        foreach ($records as $rec) {
            if (strtoupper($rec['type']) !== $type) continue;
            if ($rec['name'] !== $nameOrId) continue;
            if ($data !== null && $rec['data'] !== $data) continue;
            $this->request('DELETE', "/zones/{$zone}/records/{$rec['id']}");
        }

        return true;
    }

    public function ZoneAddRecords($zone, array $entries)
    {
        $responses = [];
        foreach ($entries as $idx => $entry) {
            if (!is_array($entry) || empty($entry['type']) || !isset($entry['name']) || !isset($entry['data'])) {
                $responses[$idx] = null;
                continue;
            }
            $ttl = $entry['ttl'] ?? 3600;
            $comment = $entry['comment'] ?? '';
            $responses[$idx] = $this->ZoneAddRecord($zone, $entry['name'], $entry['type'], $entry['data'], $ttl, $comment);
        }
        return $responses;
    }

    public function ZoneDeleteRecords($zone, array $entries)
    {
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['type']) || !isset($entry['name'])) continue;
            $data = $entry['data'] ?? null;
            $this->ZoneDeleteRecord($zone, $entry['name'], $entry['type'], $data);
        }
        return true;
    }

    public function ZoneClone($fromZone, $toZone)
    {
        $records = $this->ZoneListRecords($fromZone);
        if (!is_array($records)) return false;

        $entries = [];
        foreach ($records as $rec) {
            // Skip SOA; Nameshield manages it.
            if (strtoupper($rec['type']) === 'SOA') continue;
            $entries[] = [
                'name'    => $rec['name'],
                'type'    => $rec['type'],
                'data'    => $rec['data'],
                'ttl'     => $rec['ttl'] ?? 3600,
                'comment' => $rec['comment'] ?? '',
            ];
        }

        if (empty($entries)) return true;

        $this->ZoneAddRecords($toZone, $entries);
        return true;
    }

    public function ZoneValidate($zone)
    {
        return $this->request('POST', "/zones/{$zone}/validate");
    }

    /**
     * Cancel pending changes for a zone.
     * If $includeOthers is true, also cancels changes made by other users.
     */
    public function ZoneCancel($zone, $includeOthers = false)
    {
        $endpoint = "/zones/{$zone}/cancel";
        if ($includeOthers) {
            $endpoint .= '?include_other_users_changes=true';
        }
        return $this->request('POST', $endpoint);
    }

    // Not available in Nameshield DNS API v3.
    // public function ZoneCreate($domain)
    // {
    //    throw new Exception('ZoneCreate is not supported by the Nameshield DNS API.');
    // }

    // public function ZoneDelete($domain)
    // {
    //    throw new Exception('ZoneDelete is not supported by the Nameshield DNS API.');
    // }
}
