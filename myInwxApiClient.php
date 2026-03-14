<?php

/**
 * Simple Class for XML-RPC client for INWX API.
 *
 * This class is intentionally done very simple
 * This is quite incomplete and mainly focuses on working with the DNS zones
 *
 * API Documentation: https://www.inwx.de/en/help/apidoc
 *
 */


class myInwxApiClient
{
    private $endpoint;
    private $client;
    private $cookieFile;

    public function __construct(string $username, string $password, bool $sandbox = false)
    {
        $this->endpoint = $sandbox
            ? 'https://api.ote.domrobot.com/xmlrpc/'
            : 'https://api.domrobot.com/xmlrpc/';
        $this->client = curl_init();
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'inwx_cookie');
        curl_setopt($this->client, CURLOPT_COOKIEJAR, $this->cookieFile);
	curl_setopt($this->client, CURLOPT_COOKIEFILE, $this->cookieFile);
	curl_setopt($this->client, CURLOPT_TIMEOUT, 20);

	// Login
        $this->call('account.login', [ 'user' => $username, 'pass' => $password ]);
    }


    public function __destruct()
    {
        curl_close($this->client);
        if (!empty($this->cookieFile) && file_exists($this->cookieFile)) unlink($this->cookieFile);
    }

    private function call($method, $params = [])
    {
        $request = xmlrpc_encode_request($method, $params, ["encoding" => "utf-8"]);

        curl_setopt($this->client, CURLOPT_URL, $this->endpoint);
        curl_setopt($this->client, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->client, CURLOPT_POST, true);
        curl_setopt($this->client, CURLOPT_POSTFIELDS, $request);
        curl_setopt($this->client, CURLOPT_HTTPHEADER, [
            "Content-Type: text/xml"
        ]);

        $response = curl_exec($this->client);
        if ($response === false) {
            throw new Exception('cURL Error: ' . curl_error($this->client));
        }
        $result = xmlrpc_decode($response, "utf-8");

        if (is_array($result) && isset($result['code']) && $result['code'] != 1000) {
            throw new Exception('API Error: ' . $result['msg']);
        }
        return $result;
    }

    private function callListAll(string $method, string $resultKey, array $params = [], int $pageLimit = 100): array
    {
        $page = 1;
        $items = [];

        do {
            $requestParams = $params;
            if (!isset($requestParams['page'])) $requestParams['page'] = $page;
            if (!isset($requestParams['pagelimit'])) $requestParams['pagelimit'] = $pageLimit;

            $result = $this->call($method, $requestParams);
            $pageItems = $result['resData'][$resultKey] ?? [];
            if (!is_array($pageItems) || empty($pageItems)) break;

            foreach ($pageItems as $item) $items[] = $item;

            $total = isset($result['resData']['count']) ? (int)$result['resData']['count'] : null;
            if ($total !== null && count($items) >= $total) break;
            if (count($pageItems) < (int)$requestParams['pagelimit']) break;

            $page++;
        } while (true);

        return $items;
    }

    public function AccountInfo(): array
    {
	    $return=$this->call('account.info');
	    return $return["resData"];
    }

    // BEGIN Domain Section 
    // 

    // List all domains
    public function DomainsList(bool $full=true, array $filters = []): array
    {
	    $return=array();
	    $return_simple=array();
	    $domains=$this->callListAll('domain.list', 'domain', $filters);
	    foreach ($domains as $domain)
	    {
		    $return[$domain["domain"]]=$domain;
		    array_push($return_simple,$domain["domain"]);
	    }

	    if ($full) return $return; else return $return_simple;
    }

    // Get domain info
    public function DomainInfo(string $domain): array
    {
	    $return = $this->call('domain.info', ['domain' => $domain]);
	    return $return["resData"];
    }

    // Register a domain (very simple form)
    public function DomainRegister($domain, $period = 1, $registrant = null, $admin = null, $tech = null, $billing = null, array $nameservers = [])
    {
        $params = ['domain' => $domain, 'period' => $period];
        if ($registrant !== null) $params['registrant'] = $registrant;
        if ($admin !== null) $params['admin'] = $admin;
        if ($tech !== null) $params['tech'] = $tech;
        if ($billing !== null) $params['billing'] = $billing;
        if (!empty($nameservers)) $params['ns'] = $nameservers;
        return $this->call('domain.create', $params);
    }

    public function DomainUpdate(string $domain, array $params = [])
    {
        $params['domain'] = $domain;
        return $this->call('domain.update', $params);
    }

    public function DomainUpdateContacts(string $domain, $registrant = null, $admin = null, $tech = null, $billing = null)
    {
        $params = [];
        if ($registrant !== null) $params['registrant'] = $registrant;
        if ($admin !== null) $params['admin'] = $admin;
        if ($tech !== null) $params['tech'] = $tech;
        if ($billing !== null) $params['billing'] = $billing;
        return $this->DomainUpdate($domain, $params);
    }

    public function DomainContactRoles(): array
    {
        // According to INWX domain.create/domain.update the supported roles are:
        // registrant, admin, tech and billing.
        return ['registrant', 'admin', 'tech', 'billing'];
    }

    // BEGIN Contact Section
    //

    public function ContactsList(bool $full = true, array $filters = []): array
    {
	    $return=array();
	    $return_simple=array();
	    $contacts=$this->callListAll('contact.list', 'contact', $filters);
	    foreach ($contacts as $contact) {
		    $return[$contact["id"]]=$contact;
		    $return_simple[]=$contact["id"];
	    }
	    if ($full) return $return;
	    return $return_simple;
    }

    public function ContactInfo(int $id): array
    {
	    $return = $this->call('contact.info', ['id' => $id]);
	    return $return["resData"];
    }

    public function ContactUpdate(int $id, array $params = [])
    {
	    $params['id'] = $id;
	    return $this->call('contact.update', $params);
    }

    public function ContactUsageTypes(): array
    {
	    // INWX domain operations use contacts for these roles.
	    return $this->DomainContactRoles();
    }

    public function ContactsWithUsage(): array
    {
	    $contacts = $this->ContactsList(true);
	    $roles = $this->ContactUsageTypes();
	    foreach ($contacts as &$contact) $contact['usableFor'] = $roles;
	    return $contacts;
    }

    public function ContactsLog(): array
    {
	    $return = $this->call('contact.log');
	    return $return["resData"];
    }



    // BEGIN Zone Section
    //

    // List all zones
    public function ZonesList(bool $full = false, array $filters = []): array
    {
        $domains = [];
        $fullDomains = [];
        $result = $this->callListAll('nameserver.list', 'domains', $filters);
        foreach ($result as $domain) {
            if ($domain["type"] == "MASTER") {
                $domains[] = $domain["domain"];
                $fullDomains[$domain["domain"]] = $domain;
            }
        }
        if ($full) return $fullDomains;
        return $domains;
    }

    // List records in a zone
    public function ZoneListRecords(string $domain): array
    {
	    $return = [];
	    $result = $this->call('nameserver.info', ['domain' => $domain]);
	    if (!isset($result["resData"]["domain"]) || $result["resData"]["domain"] != $domain) return [];
	    foreach ($result["resData"]["record"] as $record)
		    $return[$record["id"]] = $record;
	    return $return;
    }

    public function ZoneInfo(string $domain): array
    {
	    $result = $this->call('nameserver.info', ['domain' => $domain]);
	    return $result["resData"] ?? [];
    }

    // General addRecord supporting all record types
    public function ZoneAddRecord(string $domain, string $name, string $type, string $content, int $ttl = 3600, int $prio = null) : int
    {
        $params = [
            'domain'  => $domain,
            'type'    => $type,
            'name'    => $name,
            'content' => $content,
            'ttl'     => $ttl,
        ];
	if ($prio !== null) $params['prio'] = $prio;

	$return = $this->call('nameserver.createRecord', $params);
	return $return["resData"]["id"];

	// Examples:
	// $inwx->ZoneAddRecord($domain, 'test', 'A',    '192.0.2.10');
	// $inwx->ZoneAddRecord($domain, 'test', 'AAAA', '2001:db8::10');
	// $inwx->ZoneAddRecord($domain, 'test', 'MX',   'mail1.myexample.com.', 3600, 10); // prio 10
	// $inwx->ZoneAddRecord($domain, 'test', 'TXT',  'Hello world 2');
	// $inwx->ZoneAddRecord($domain, 'cname', 'CNAME', 'test.myexample.com.');
	// $inwx->ZoneAddRecord($domain, 'i_sip._tcp', 'SRV', '33 554 sipserver.myexample.com.', 3600, 10);
	// $inwx->ZoneAddRecord($domain, 'www', 'HTTPS', '5 backup.myexample.com. alpn=h2,h3 ipv4hint=192.0.2.2 port=8443 .', 3600);
    }

    // Delete a record by record id
    public function ZoneDeleteRecordId(int $id)
    {
	    return $this->call('nameserver.deleteRecord', ['id' => $id]);
    }

    // Delete a record by name/type/content (compat wrapper).
    public function ZoneDeleteRecord(string $domain, string $name, string $type, string $content = null)
    {
        // INWX deletes by record id, so we look up matching records first.
        $records = $this->ZoneListRecords($domain);
        $type = strtoupper($type);

        foreach ($records as $rec) {
            if (strtoupper($rec['type']) !== $type) continue;
            if ($rec['name'] !== $name) continue;
            if ($content !== null && $rec['content'] !== $content) continue;

            $this->ZoneDeleteRecordId((int)$rec['id']);
        }

        return true;
    }

    // Delete multiple records (compat wrapper).
    // Each entry: ['name' => ..., 'type' => ..., 'content' => optional]
    public function ZoneDeleteRecords(string $domain, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['type']) || !isset($entry['name'])) {
                continue;
            }
            $content = $entry['content'] ?? null;
            $this->ZoneDeleteRecord($domain, $entry['name'], $entry['type'], $content);
        }
        return true;
    }

   // Returns Nameserver Sets
    public function NameserverSetsList(bool $full = true, array $filters = []): array
    {
	    $list=$this->callListAll('nameserverset.list', 'nsset', $filters);
	    $return = array();
	    foreach ($list as $curset) {
		    if ($full && isset($curset['id'])) $return[$curset['id']] = $curset;
		    else $return[] = $curset;
	    }
	    return $return;
    }

    public function ZonesListNameservers(): array
    {
	    $sets = $this->NameserverSetsList(true);
	    $return = array();
	    foreach ($sets as $curset)
		    if (isset($curset['ns'])) array_push($return,$curset['ns']);
	    return $return;
    }

    // Create a new empty zone
    public function ZoneCreate(string $domain, array $nameservers = [])
    {
	    if (empty($nameservers)) {
		    $accountInfo = $this->AccountInfo();
		    $nssets = $this->NameserverSetsList(true);
		    if (isset($accountInfo['defaultNsset']) && isset($nssets[$accountInfo['defaultNsset']]['ns']))
			    $nameservers = $nssets[$accountInfo['defaultNsset']]['ns'];
		    elseif (!empty($nssets)) {
			    $firstSet = reset($nssets);
			    if (isset($firstSet['ns'])) $nameservers = $firstSet['ns'];
		    }
	    }
	    if (empty($nameservers)) throw new Exception('Could not determine the INWX default nameserver set');
	
	    return $this->call('nameserver.create', [
		    'domain' => $domain,
		    'type' => 'MASTER',
		    'ns' => $nameservers
	]);
    }

    // Add multiple records (compat wrapper).
    // Each entry: ['type' => ..., 'name' => ..., 'content' => ..., 'ttl' => 3600, 'prio' => null]
    public function ZoneAddRecords(string $domain, array $entries): array
    {
        $created = [];
        foreach ($entries as $idx => $entry) {
            if (!is_array($entry) || empty($entry['type']) || !isset($entry['name']) || !isset($entry['content'])) {
                $created[$idx] = null;
                continue;
            }

            $ttl  = $entry['ttl']  ?? 3600;
            $prio = $entry['prio'] ?? null;

            $id = $this->ZoneAddRecord($domain, $entry['name'], $entry['type'], $entry['content'], $ttl, $prio);
            $created[$idx] = $id;
        }

        return $created;
    }

    // Delete a zone
    public function ZoneDelete(string $domain)
    {
        return $this->call('nameserver.delete', [
            'domain' => $domain
        ]);
    }

    // Copy all records from $fromDomain to $toDomain
    public function ZoneClone(string $fromDomain, string $toDomain): int
    {
        $return = $this->call('nameserver.clone', [
            'sourceDomain' => $fromDomain,
            'targetDomain' => $toDomain
	]);
	return $return["resData"]["roId"];
    }
}
