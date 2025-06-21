<?php

/**
 * Simple Class for InternetX JSON API.
 *
 * This class is intentionally done very simple.
 * It provides a simple client for the InternetX (AutoDNS) JSON API,
 * with a focus on DNS zone management. Only a subset of the API is implemented,
 * specifically the endpoints and actions relevant for DNS operations.
 *
 * NOTES:
 * - Different suppliers under InternetX may require different "context IDs".
 *   Known context IDs:
 *     - SchlundTech: 10
 *     - Lenoex: 2242
 * - Use the `hello()` method to verify authentication and connectivity.
 * - This implementation is intentionally minimal and may require extension
 *   for use cases beyond DNS zone and record management.
 *
 * API Documentation:
 *   EN: https://help.internetx.com/display/APIXMLEN/JSON+API+basics
 *   DE: https://help.internetx.com/display/APIXMLDE/Technische+Dokumentation+JSON
 *
 */


class myInternetXApiClient
{
	private $baseUrl = 'https://api.autodns.com/v1';
	private $username;
	private $password;
	private $robotcontext;
	private $apiKey=NULL;
	private $token=NULL;

	public function __construct($username = NULL, $password = NULL, $robotcontext=NULL, $apiKey = NULL)
	{
		if (empty($username) && empty($apiKey)) throw new InvalidArgumentException('Either Username/Password or API key needs to be provided');
		if (!empty($username) && empty($password)) throw new InvalidArgumentException('No password provided');
		if (empty($robotcontext)) throw new InvalidArgumentException('RobotContext not provided');

		$this->username = $username;
		$this->password = $password;
		if (ctype_digit($apiKey) && strlen($apiKey) == 6) 
			$this->token = $apiKey;
		else 
			$this->apiKey = $apiKey;
		$this->robotcontext = $robotcontext;
	}

	private function callApi($method, $endpoint, $data = [])
	{
		$url = $this->baseUrl . $endpoint;

		$headers = ['Content-Type: application/json'];
		if (!empty($this->apiKey)) 
			$headers[] = 'Authorization: Apikey ' . $this->apiKey;
		else 
			$headers[] = 'Authorization: Basic ' . base64_encode("$this->username:$this->password");

		$headers[] = "X-Domainrobot-Context: ". $this->robotcontext;

		if (!empty($this->token) && empty($this->apiKey))
			$headers[] = 'X-Domainrobot-2FA-Token: '.$this->token;

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);

		if ($method !== 'GET' && !empty($data)) 
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));

		$response = curl_exec($curl);
		curl_close($curl);

		return json_decode($response, true);
	}

	public function hello()
	{
		return $this->callApi("GET","/hello");
	}

	public function ZonesList(): array
	{
		// Returns an array with all zones in the account
		$return=array();
		$result=$this->callApi('POST', "/zone/_search");
		if ($result["status"]["type"]=="SUCCESS" && $result["object"]["type"]=="Zone" && is_array($result["data"])) 
			foreach ($result["data"] as $curdomain)
				array_push($return,$curdomain["origin"]);
		return $return;
	}

	public function ZoneCreate(string $domain, int $ttl = 86400, array $nameservers = []): bool
	{
		// Creates a new DNS zone, optionally with nameservers and default TTL.
		$data = [
			"origin" => $domain,
			"defaultTTL" => $ttl
		];

		if (empty($nameservers)) $nameservers = $this -> _ZoneGetDefaultNS();

		if (!empty($nameservers)) {
			// Flexible Übergabe: Entweder String-Array oder Array von Arrays
			if (is_array($nameservers[0]) && isset($nameservers[0]['name']))
				$data["nameServers"] = $nameservers;
			else {
				$data["nameServers"] = array_map(
					fn($ns) => ["name" => $ns],
					$nameservers
				);
			}
		} 

		$soa = [
			"primary" => $data["nameServers"][0]["name"],
			"mail"    => "hostmaster." . $domain . ".",
			"refresh" => 43200,
			"retry"   => 7200,
			"expire"  => 1209600,
			"ttl"     => $ttl ?? 86400
		];
		$data["soa"] = $soa;

		$result = $this->callApi("POST", "/zone", $data);
		return ($result["status"]["type"] == "SUCCESS");
	}

	public function ZoneDelete(string $domain) : bool
	{
		// Deletes a DNS zone
		$result = $this->callApi("DELETE", "/zone/$domain");
		return ($result["status"]["type"] == "SUCCESS");
	}

	public function ZoneListRecords(string $domain)
	{
		// Returns all records of a zone as an array (non-AXFR, structured JSON)
		$result = $this->callApi("GET", "/zone/$domain");
		if ($result["status"]["type"] == "SUCCESS" && isset($result["data"][0]["resourceRecords"]))
			return $result["data"][0]["resourceRecords"];
		else
			return false;
	}

	public function _ZoneGetDefaultNS(): array
	{
		// Use dry-run to simulate domain creation and extract default nameservers
		$data = [ "domain" => [ "name" => "dryrun-example-test-zone.com", "ownerContact" => [ "handle" => "AUTO" ] ] ];
		$result = $this->callApi("POST", "/domain", ["dryRun" => true, "data" => $data]);
		if (
			isset($result["status"]["type"]) && $result["status"]["type"] === "SUCCESS" &&
			isset($result["object"]["type"]) && $result["object"]["type"] === "Domain" &&
			isset($result["data"][0]["nameServers"])
		) return $result["data"][0]["nameServers"];
		// Special Section for Schlundtech
		if ($this->robotcontext=="10" && preg_match('/^\d{7,}$/',$this->username))
		{
			// With Schlundtech the default nameservers use the last digit in the username/customer id within the nameserver name
			$x=substr($this->username, -1);
			return ["nsa".$x.".schlundtech.de","nsb".$x.".schlundtech.de","nsc".$x.".schlundtech.de","nsd".$x.".schlundtech.de"];
		}
		// Last Resort - let's try to get the nameservers of the first zone we can find
		$allzones = $this->ZonesList();
		if (is_array($allzones) && count($allzones) > 0) return $this->_ZoneGetNS($allzones[0]);

		// If no nameservers are found, return an empty array
		return [];
	}

	public function _ZoneGetNS($domain): array
	{
		// Returns an array of nameserver hostnames for the given zone, using AXFR parsing.
		$zone = $this->Zone_axfr($domain);
		if (!is_array($zone)) return [];
		$return = [];
		foreach ($zone as $record)
			if ($record["type"] === "NS" && ($record["FQDN"] === $domain || $record["FQDN"] === $domain . "."))
				$return[] = $record["content"];
		return $return;
	}

	public function _ZonesGetVNS($domain)
	{
		// Returns the virtual nameserver of a zone
		$search=json_decode('{ "filters": [ { "key": "name", "value": "'.$domain.'", "operator": "LIKE" } ] }');
		$result=$this->callApi('POST', "/zone/_search",$search);
		if ($result["status"]["type"]=="SUCCESS" && $result["object"]["type"]=="Zone" && is_array($result["data"]) && count($result["data"])==1)
			return $result["data"][0]["virtualNameServer"];
		else
			return false;
	}

	public function ZoneAddRecord(string $domain, string $name, string $type, string $content, int $ttl = NULL, int $prio = NULL)
	{
		// Adds one entry to the zone of $domain
		$data = [ "adds" => [] ];
		$type=strtoupper($type);
		if (!in_array($type,array("A","MX","CNAME","TXT","SRV","PTR","AAAA","NS","CAA","PROCEED_MUC","TLSA","NAPTR","SSHFP","LOC","RP","HINFO","PROCEED","ALIAS","DNSKEY","NSEC","DS"))) return false;
		$entry = [
			"name" => $name,
			"type" => $type,
			"value" => $content
		];
		if ($ttl !== NULL) $entry["ttl"] = $ttl;
		// Add prio for MX, SRV, NAPTR
		if (in_array($type, ["MX", "SRV", "NAPTR"]) && $prio !== NULL) $entry["prio"] = $prio;

		$data['adds'][] = $entry;

		$result=$this->callApi("POST","/zone/".$domain."/_stream",$data);
		if ( is_array($result) && is_array($result["status"]) && is_array($result["object"]) && isset($result["status"]["type"]) && isset($result["object"]["summary"]) && $result["status"]["type"]=="SUCCESS" )
			return $result["object"]["summary"];
		else
			return false;
	}

	public function ZoneDeleteRecord(string $domain, string $name, string $type, string $content, int $ttl = NULL)
	{
		// Deletes one entry to the zone of $domain
		$data = [ "rems" => [] ];
		$type=strtoupper($type);
		if (!in_array($type,array("A","MX","CNAME","TXT","SRV","PTR","AAAA","NS","CAA","PROCEED_MUC","TLSA","NAPTR","SSHFP","LOC","RP","HINFO","PROCEED","ALIAS","DNSKEY","NSEC","DS"))) return false;
		$entry = [
			"name" => $name,
			"type" => $type,
			"value" => $content
		];
		if ($ttl !== NULL) $entry["ttl"] = $ttl;
		$data['rems'][] = $entry;

		$result=$this->callApi("POST","/zone/".$domain."/_stream",$data);
		if ( is_array($result) && is_array($result["status"]) && is_array($result["object"]) && isset($result["status"]["type"]) && isset($result["object"]["summary"]) && $result["status"]["type"]=="SUCCESS" )
			return $result["object"]["summary"];
		else
			return false;

	}

	public function ZoneAddRecords(string $domain,array $entries) 
	{
		// Adds multiple records to a zone
		$data = [ "adds" => [] ];
		foreach ($entries as $entry) {
			if (isset($entry["name"])) $name=$entry["name"]; else continue;
			if (isset($entry["value"])) $content=$entry["value"]; else continue;
			if (isset($entry["ttl"])) $ttl=$entry["ttl"]; 
			if (isset($entry["type"])) $type=$entry["type"]; else continue;
			$type = strtoupper($type);
			if (!in_array($type,array("A","MX","CNAME","TXT","SRV","PTR","AAAA","NS","CAA","PROCEED_MUC","TLSA","NAPTR","SSHFP","LOC","RP","HINFO","PROCEED","ALIAS","DNSKEY","NSEC","DS"))) continue;
			$record = [
				"name" => $name,
				"type" => $type,
				"value" => $content
			];
			if (isset($ttl)) $record["ttl"] = $ttl;
			// Add prio for MX, SRV, NAPTR
			if (in_array($type, ["MX", "SRV", "NAPTR"]) && isset($entry["prio"])) $record["prio"] = $entry["prio"];
			$data['adds'][] = $record;
		}
		$result=$this->callApi("POST","/zone/".$domain."/_stream",$data);
		if (is_array($result) && isset($result["status"]["type"]) && $result["status"]["type"]=="SUCCESS" && isset($result["object"]["summary"]))
			return $result["object"]["summary"];
		else
			return false;
	}

	public function ZoneDeleteRecords(string $domain,array $entries) 
	{
		// Deletes multiple entries from the zone of $domain
		$data = [ "rems" => [] ];
		foreach ($entries as $entry) {
			if (isset($entry["name"])) $name=$entry["name"]; else continue;
			if (isset($entry["value"])) $content=$entry["value"]; else continue;
			if (isset($entry["ttl"])) $ttl=$entry["ttl"];
			if (isset($entry["type"])) $type=$entry["type"]; else continue;
			$type=strtoupper($type);
			if (!in_array($type,array("A","MX","CNAME","TXT","SRV","PTR","AAAA","NS","CAA","PROCEED_MUC","TLSA","NAPTR","SSHFP","LOC","RP","HINFO","PROCEED","ALIAS","DNSKEY","NSEC","DS"))) continue;
			$record = [
				"name" => $name,
				"type" => $type,
				"value" => $content
			];
			if (isset($ttl)) $record["ttl"] = $ttl;
			// Add prio for MX, SRV, NAPTR
			if (in_array($type, ["MX", "SRV", "NAPTR"]) && isset($entry["prio"])) $record["prio"] = $entry["prio"];
			$data['rems'][] = $record;
		}
		$result=$this->callApi("POST","/zone/".$domain."/_stream",$data);
		if (is_array($result) && isset($result["status"]["type"]) && $result["status"]["type"]=="SUCCESS" && isset($result["object"]["summary"]))
			return $result["object"]["summary"];
		else
			return false;
	}

	public function ZoneExists(string $domain): bool
	{
		$existingZones = $this->ZonesList();
		return in_array($domain, $existingZones);
	}

	public function ZoneClone(string $fromDomain, string $toDomain): bool
	{
		// Check if destination zone already exists
		if ($this->ZoneExists($toDomain)) return false;

		// Get all records from source zone
		$sourceZone = $this->ZoneListRecords($fromDomain);
		if (empty($sourceZone)) return false;

		$sourceZoneNS = $this->_ZoneGetNS($fromDomain);
		if (empty($sourceZoneNS)) return false;

		if ($this->ZoneCreate($toDomain, 86400, $sourceZoneNS)) 
		{
			if ($this->ZoneAddRecords($toDomain, $sourceZone))
				return true;
			else
				$this->ZoneDelete($toDomain);
		}

		return false;
	}

	public function Zone_axfr(string $domain) 
	{
		// Attempts an AXFR zone transfer for $domain using its virtual nameserver.
		// Returns an array of records or false on failure.

		// First we need to get the primary nameserver to get the full URL of the API request
		if ($ns=$this->_ZonesGetVNS($domain))
  			$result=$this->callApi("GET","/zone/".$domain."/".$ns."/_axfr");
		else 
			return false;
		if ($result["status"]["type"]=="SUCCESS" && $result["object"]["type"]=="Zone" && is_array($result["data"]) && count($result["data"])==1 && isset($result["object"]) && is_array($result["object"]) && isset($result["object"]["value"]) && $result["object"]["value"]==$domain)  
		 {
			$lines=explode("\n",$result["data"][0]);
			if (empty($lines[count($lines)])) unset($lines[count($lines)-1]);
			$return=array();

			// Parses AXFR response into an array of records.
			// Each record contains: FQDN, name, ttl, type, content.
			// Assumes tab-separated fields as returned by the API.
			foreach ($lines as $line) 
			{
				$tmp1=explode("\t",$line);
				$tmp2["FQDN"]=$tmp1[0];
				$tmp2["name"]=substr($tmp1[0],0,-(strlen($domain)+2));
				if (empty($tmp2["name"])) $tmp2["name"]="@";
				if (empty($tmp1[1]) && $tmp1[3]=="IN") array_splice($tmp1, 1, 1); // If there is one tab more then expected remove it. 
				$tmp2["ttl"]=$tmp1[1];
				$tmp2["type"]=$tmp1[3];
				$tmp2["content"]=$tmp1[4];
				$return[]=$tmp2;
				unset($tmp1);
				unset($tmp2);
			}
			return $return;
		 }
		else
			return false;

	}

}

?>
