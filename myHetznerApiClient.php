<?php

/**
 * Simple Class for Hetzner DNS JSON API.
 *
 * This class is intentionally done very simple.
 * It provides a minimal client for the Hetzner DNS API,
 * focusing on DNS zone and record management. Only a subset of the API is implemented,
 * specifically the endpoints and actions relevant for DNS operations.
 *
 * API Documentation:
 *   https://dns.hetzner.com/api-docs/
 *
 */


class myHetznerDnsApiClient
{
	private $baseUrl = 'https://dns.hetzner.com/api/v1';
	private $token;

	public function __construct(string $token)
	{
		if (empty($token)) {
			throw new InvalidArgumentException('API token must be provided');
		}
		$this->token = $token;
	}

	private function callApi($method, $endpoint, $data = null)
	{
		$url = $this->baseUrl . $endpoint;

		$headers = [
			'Content-Type: application/json',
			'Auth-API-Token: ' . $this->token
		];

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);

		if ($data !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
		}

		$response = curl_exec($curl);
		if ($response === false) {
			throw new Exception('cURL Error: ' . curl_error($curl));
		}
		curl_close($curl);

		return json_decode($response, true);
	}

	public function ZoneGetId(string $domain): ?string
	{
		$result = $this->ZonesList();
		if (!empty($result)) 
			foreach ($result as $id => $name)
				if (strtolower($name) == strtolower($domain)) 
					return $id;
		return null;
	}

	public function ZoneExists(string $domain): bool
	{
		return $this->ZoneGetId($domain) !== null;
	}

	public function ZonesList(): array
	{
		$result = $this->callApi('GET', '/zones');
		if (empty($result['zones'])) return [];
		$return=array();
		foreach ($result['zones'] as $zone) $return[$zone['id']]=$zone['name'];
		return $return;
	}

	public function ZoneCreate(string $name, string $ttl = '86400'): ?string
	{
		$data = [
			'name' => $name,
			'ttl' => (int)$ttl
		];
		$result = $this->callApi('POST', '/zones', $data);
		if (!empty($result['zone']['id']) && $result['zone']['name']==$name)
		{
			// Once the zone is created we still need to add the NS records and the SOA
			// The NS records which need to be configured are already given in the result
			$id=$result['zone']['id'];
			$ns=$result['zone']['ns'];
			foreach ($ns as $curns) $this->ZoneAddRecordID($id,"@","NS",$curns);
			return $id;
		}
		else return null;
	}

	public function ZoneDeleteID(string $zone_id): bool
	{
		$result=$this->callApi('DELETE', '/zones/' . $zone_id);
		if (isset($result["error"]) && is_array($result["error"]) && empty($result["error"]))
			return true;
		return false;
	}

	public function ZoneDelete(string $domain): bool
	{
		$zone_id = $this->ZoneGetId($domain);
		if (!$zone_id) return false;
		return $this->ZoneDeleteID($zone_id);
	}

	public function ZoneUpdateTTLID(string $zone_id, $ttl = null): bool
	{
		$data = [];
		if ($ttl !== null) $data['ttl'] = (int)$ttl;
		$result = $this->callApi('PUT', '/zones/' . $zone_id, $data);
		// If updated, it should return the zone array with the new ttl set
		if (isset($result['zone']) && $result['zone']['id'] == $zone_id) 
			if ($ttl === null || $result['zone']['ttl'] == (int)$ttl) return true;
		return false;
	}

	public function ZoneUpdateTTL(string $domain, $ttl = null): bool
	{
		$zone_id = $this->ZoneGetId($domain);
		if (!$zone_id) return false;
		return $this->ZoneUpdateTTLID($zone_id, $ttl);
	}

	public function ZoneListRecordsID(string $zone_id): array
	{
		$result = $this->callApi('GET', '/records?zone_id=' . urlencode($zone_id));
		return $result['records'] ?? [];
	}

	public function ZoneListRecords(string $domain): array
	{
		$zone_id = $this->ZoneGetId($domain);
		if (!$zone_id) return [];
		return $this->ZoneListRecordsID($zone_id);
	}

	public function ZoneAddRecordID(string $zone_id, string $name, string $type, string $value, int $ttl = null): ?string
	{
		$data = [
			'zone_id' => $zone_id,
			'type' => strtoupper($type),
			'name' => $name,
			'value' => $value
		];
		if ($ttl !== null) $data['ttl'] = (int)$ttl;
		$result = $this->callApi('POST', '/records', $data);
		// In case of any error error is set. 
		if (isset($result['error'])) return null;
		// Let's check if the returned data is what is expected. We are not checking vlaue since especally for TXT records this needs slighly more logic than a simple comparison.
		if (isset($result['record']) && $result['record']['zone_id'] == $zone_id && strtoupper($result['record']['type']) == strtoupper($type) && strtolower($result['record']['name']) == strtolower($name))
			return 	$result['record']['id'];
		else
			return null;
	}

	public function ZoneAddRecord(string $domain, string $name, string $type, string $value, int $ttl = null): ?string
	{
		$zone_id = $this->ZoneGetId($domain);
		if (!$zone_id) return null;
		return $this->ZoneAddRecordID($zone_id, $name, $type, $value, $ttl);
	}

	public function ZoneInfoID(string $zone_id): ?array
	{
		$result = $this->callApi('GET', '/zones/' . $zone_id);
		return $result['zone'] ?? null;
	}

	public function ZoneInfo(string $domain): ?array
	{
		$zone_id = $this->ZoneGetId($domain);
		if (!$zone_id) return null;
		return $this->ZoneInfoID($zone_id);
	}

	public function _ZoneGetNSID (string $zone_id): array
	{
		$result = $this->ZoneInfoID($zone_id);

		if (!is_array($result["ns"]))
			return []; 
		else
			return $result["ns"];
	}

	public function _ZoneGetNS(string $domain): ?array
	{
		$zone_id = $this->ZoneGetId($domain);
		if (!$zone_id) return null;
		return $this->_ZoneGetNSID($zone_id);
	}

	public function ZoneUpdateRecordIDID(string $zone_id, string $record_id, string $name, string $type, string $value, int $ttl = null): ?array
	{
		$data = [
			'zone_id' => $zone_id,
			'type' => strtoupper($type),
			'name' => $name,
			'value' => $value
		];
		if ($ttl !== null) $data['ttl'] = (int)$ttl;
		$result = $this->callApi('PUT', '/records/' . $record_id, $data);
		if (isset($result['error'])) return null;
		return $result['record'] ?? null;
	}

	public function ZoneUpdateRecordID(string $domain, string $record_id, string $name, string $type, string $value, int $ttl = null): ?array
	{
		$zone_id = $this->ZoneGetId($domain);
		if (!$zone_id) return null;
		return $this->ZoneUpdateRecordIDID($zone_id, $record_id, $name, $type, $value, $ttl);
	}

	public function ZoneDeleteRecordID(string $record_id): bool
	{
		$result=$this->callApi('DELETE', '/records/' . $record_id);
		if (isset($result["error"]) && is_array($result["error"]) && empty($result["error"]))
			return true;
		return false;
	}

	public function ZoneAddRecordsID(string $zone_id, array $entries): array
	{
		foreach ($entries as &$entry) if (!isset($entry["zone_id"])) $entry["zone_id"] = $zone_id;
		$data = ['zone_id' => $zone_id, 'records' => $entries];
		$result = $this->callApi('POST', '/records/bulk', $data);
		$return = array();
		if (isset($result['records'])) foreach ($result['records'] as $re) $return[$re['id']] = $re;
		return $return;
	}

	public function ZoneAddRecords(string $domain, array $entries): ?array
	{
		$zone_id = $this->ZoneGetId($domain);
		if (!$zone_id) return null;
		return $this->ZoneAddRecordsID($zone_id, $entries);
	}

	public function ZoneUpdateRecordsID(string $zone_id, array $entries): array
	{
		foreach ($entries as &$entry) if (!isset($entry["zone_id"])) $entry["zone_id"] = $zone_id;
		$data = ['zone_id' => $zone_id, 'records' => $entries];
		$result = $this->callApi('PUT', '/records/bulk', $data);
		$return = array();
		if (isset($result['records'])) foreach ($result['records'] as $re) $return[$re['id']] = $re;
		return $return;
	}

	public function ZoneUpdateRecords(string $domain, array $entries): ?array
	{
		$zone_id = $this->ZoneGetId($domain);
		if (!$zone_id) return null;
		return $this->ZoneUpdateRecordsID($zone_id, $entries);
	}

	public function ZoneCloneID(string $fromZoneID, string $toDomain): bool
	{
		if ($this->ZoneExists($toDomain)) return false;

		$keysToRemove = ['id','zone_id','created','modified'];
		$oldrecords=$this->ZoneListRecordsID($fromZoneID);
		$newrecords=array();
		foreach ($oldrecords as $currec) 
		{
			// Remove unwanted records
			if (strtoupper($currec["type"])=="SOA" || ($currec["name"]=="@" && strtoupper($currec["type"])=="NS")) continue;
			// Remove unwanted keys
			$cleanrec = array_diff_key($currec, array_flip($keysToRemove));

			$newrecords[]=$cleanrec;
		}

		$toZoneID=$this->ZoneCreate($toDomain);
		if ($toZoneID == null || strlen($toZoneID)<5) return false;
		if (empty($newrecords)) return true;
		$result=$this->ZoneAddRecordsID($toZoneID,$newrecords);
		return !empty($result);
	}

	public function ZoneClone(string $fromDomain, string $toDomain): bool
	{
		$zone_id = $this->ZoneGetId($fromDomain);
		if (!$zone_id) return false;
		return $this->ZoneCloneID($zone_id, $toDomain);
	}

}

?>
