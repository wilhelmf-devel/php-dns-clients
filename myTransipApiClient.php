<?php

// https://api.transip.nl/rest/docs.html
// NOTE: There’s no single “add” or “delete” endpoint for one record; you must always replace the full set of DNS records for a domain.

/**
 * myTransipApiClient
 *
 * Minimal PHP client for TransIP DNS (REST v6). TransIP’s DNS API only allows
 * replacing the full record set, so add/delete helpers rewrite the entire zone
 * to mimic the convenience methods used in other clients in this repo. Zone
 * creation and deletion are not available in TransIP’s DNS API; domains must
 * already exist in your TransIP account.
 *
 * Built with support from OpenAI Codex.
 */

class myTransipApiClient
{
    private $token;
    private $baseUrl = 'https://api.transip.nl/v6';

    public function __construct($bearerToken)
    {
        $this->token = $bearerToken;
    }

    private function request($method, $endpoint, $data = null)
    {
        $url = $this->baseUrl . $endpoint;
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json'
        ];
        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ];
        if ($data !== null) {
            $opts['http']['content'] = json_encode($data);
        }
        $context = stream_context_create($opts);
        $response = file_get_contents($url, false, $context);
        if ($response === false) throw new Exception("HTTP request failed: $method $url");

        $statusLine = $http_response_header[0] ?? '';
        if (!preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $statusLine, $matches)) throw new Exception("Unexpected HTTP response: $statusLine");
        $statusCode = (int)$matches[1];
        $json = json_decode($response, true);

        if ($statusCode >= 400) {
            $errorMsg = $json['error'] ?? ($json['message'] ?? 'Unknown error');
            throw new Exception("API error $statusCode: $errorMsg");
        }
        return $json;
    }

    // List all domains
    public function DomainsList($detailed=false): array
    {
	    $return=array();
	    if($detailed)
		    $result = $this->request('GET', '/domains?include=nameservers,contacts');
	    else
		    $result = $this->request('GET', '/domains');
	
	if ($detailed) return $result['domains'] ?? [];
	foreach($result['domains'] as $domain) $return[]=$domain['name'];
	return $return;
    }

    public function ZonesList(): array
    {
	    $return=array();
	    $domains = $this->DomainsList(true);
	    foreach ($domains as $domain) 
		    if (strtolower($domain['nameservers'][0]['hostname'])=="ns0.transip.net" ) 
			    $return[]=$domain['name'];
	    return $return;
    }

    // List DNS records for a domain
    public function ZoneListRecords($domain): array
    {
        $result = $this->request('GET', "/domains/{$domain}/dns");
        return $result['dnsEntries'] ?? [];
    }

    // Replace ALL records for a domain (required by API)
    public function DomainSetRecords($domain, $records)
    {
        $data = ['dnsEntries' => $records];
        return $this->request('PUT', "/domains/{$domain}/dns", $data);
    }

    // Add a single record (compat: use ZoneAddRecord)
    public function DomainAddRecord($domain, $name, $type, $content, $expire = 3600)
    {
        return $this->ZoneAddRecord($domain, $name, $type, $content, $expire);
    }

    // Delete a single record (by exact match)
    public function DomainDeleteRecord($domain, $type, $name, $content)
    {
        return $this->ZoneDeleteRecord($domain, $name, $type, $content);
    }

    // Replace one record by ID (exact match on all fields)
    public function DomainUpdateRecord($domain, $oldType, $oldName, $oldContent, $newType, $newName, $newContent, $expire = 3600)
    {
        $records = $this->ZoneListRecords($domain);
        $updated = false;
        foreach ($records as &$rec) {
            if (
                strtolower($rec['type']) == strtolower($oldType) &&
                $rec['name'] == $oldName &&
                $rec['content'] == $oldContent
            ) {
                $rec['type'] = strtoupper($newType);
                $rec['name'] = $newName;
                $rec['content'] = $newContent;
                $rec['expire'] = (int)$expire;
                $updated = true;
            }
        }
        if ($updated) return $this->DomainSetRecords($domain, $records);
        else throw new Exception("Record not found");
    }

    // Compat: create a zone (not supported via DNS endpoint; caller must register domain separately).
    public function ZoneCreate($domain)
    {
        throw new Exception('ZoneCreate not supported in this client; register the domain first in TransIP.');
    }

    // Compat: delete a zone (not supported via DNS endpoint).
    public function ZoneDelete($domain)
    {
        throw new Exception('ZoneDelete not supported in this client; remove the domain from TransIP manually.');
    }

    // Compat: add a single record.
    public function ZoneAddRecord($domain, $name, $type, $content, $expire = 3600)
    {
        $records = $this->ZoneListRecords($domain);
        $records[] = [
            'name' => $name,
            'expire' => (int)$expire,
            'type' => strtoupper($type),
            'content' => $content
        ];
        return $this->DomainSetRecords($domain, $records);
    }

    // Compat: add multiple records.
    public function ZoneAddRecords($domain, array $entries)
    {
        $records = $this->ZoneListRecords($domain);
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['type']) || !isset($entry['name']) || !isset($entry['content'])) {
                continue;
            }
            $records[] = [
                'name'    => $entry['name'],
                'expire'  => isset($entry['expire']) ? (int)$entry['expire'] : 3600,
                'type'    => strtoupper($entry['type']),
                'content' => $entry['content'],
            ];
        }
        return $this->DomainSetRecords($domain, $records);
    }

    // Compat: delete records by name/type/content (content optional => remove all matching name/type).
    public function ZoneDeleteRecord($domain, $name, $type, $content = null)
    {
        $records = $this->ZoneListRecords($domain);
        $filtered = [];
        foreach ($records as $rec) {
            $matchesType = strtolower($rec['type']) == strtolower($type);
            $matchesName = $rec['name'] == $name;
            $matchesContent = $content === null || $rec['content'] == $content;

            if ($matchesType && $matchesName && $matchesContent) {
                // skip -> delete
                continue;
            }
            $filtered[] = $rec;
        }
        return $this->DomainSetRecords($domain, $filtered);
    }

    // Compat: delete multiple records.
    public function ZoneDeleteRecords($domain, array $entries)
    {
        $records = $this->ZoneListRecords($domain);
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['type']) || !isset($entry['name'])) {
                continue;
            }
            $content = $entry['content'] ?? null;
            $records = array_values(array_filter($records, function ($rec) use ($entry, $content) {
                $matchesType = strtolower($rec['type']) == strtolower($entry['type']);
                $matchesName = $rec['name'] == $entry['name'];
                $matchesContent = $content === null || $rec['content'] == $content;
                return !($matchesType && $matchesName && $matchesContent);
            }));
        }
        return $this->DomainSetRecords($domain, $records);
    }

    // Compat: clone records from one domain to another (replaces all records on target).
    public function ZoneClone($fromDomain, $toDomain)
    {
        $records = $this->ZoneListRecords($fromDomain);
        return $this->DomainSetRecords($toDomain, $records);
    }

}
