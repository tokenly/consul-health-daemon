<?php

namespace Tokenly\ConsulHealthDaemon;


use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

/**
* ConsulClient
*/
class ConsulClient
{

    public function __construct($consul_url=null) {
        if ($consul_url === null) { $consul_url = \Illuminate\Support\Facades\Config::get('consul-health.consul_url'); }
        $this->guzzle_client = new Client(['base_uri' => $consul_url]);
    }

    public function healthUp($container_name) {
        $this->setKeyValue("health/$container_name", 1);
    }
    public function healthDown($container_name) {
        $this->deleteKey("health/$container_name");
    }

    public function checkPass($check_id) {
        try {
            $this->guzzle_client->get('/v1/agent/check/pass/'.urlencode($check_id));
            return true;
        } catch (Exception $e) {
            Log::warning("failed to update check pass: ".$check_id);
            return false;
        }
    }
    public function checkWarn($check_id, $note=null) {
        try {
            $this->guzzle_client->get('/v1/agent/check/warn/'.urlencode($check_id), ['query' => ['note' => $note]]);
            return true;
        } catch (Exception $e) {
            Log::warning("failed to update check warn: ".$check_id);
            return false;
        }
    }
    public function checkFail($check_id, $note=null) {
        try {
            $this->guzzle_client->get('/v1/agent/check/fail/'.urlencode($check_id), ['query' => ['note' => $note]]);
            return true;
        } catch (Exception $e) {
            Log::warning("failed to update check failure: ".$check_id);
            return false;
        }
    }

    public function setKeyValue($key, $value) {
        $this->guzzle_client->put('/v1/kv/'.urlencode($key), ['body' => (string)$value]);
    }

    public function deleteKey($key) {
        $this->guzzle_client->delete('/v1/kv/'.urlencode($key));
    }

    public function getKeyValue($key) {
        try {
            $response = $this->guzzle_client->get('/v1/kv/'.urlencode($key));
        } catch (ClientException $e) {
            $e_respone = $e->getResponse();
            if ($e_respone->getStatusCode() == 404) {
                return null;
            }
            throw $e;
        }

        // decode this...
        $response_data = json_decode($response->getBody(), true);
        return base64_decode($response_data[0]['Value']);
    }

}

