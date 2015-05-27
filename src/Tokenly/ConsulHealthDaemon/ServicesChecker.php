<?php

namespace Tokenly\ConsulHealthDaemon;


use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tokenly\ConsulHealthDaemon\ConsulClient;

/**
 * This is invoked when a new block is received
 */
class ServicesChecker {

    public function __construct(ConsulClient $consul_client) {
        $this->consul_client = $consul_client;
    }

    public function setServicePrefix($service_prefix) {
        $this->service_prefix = $service_prefix;
    }

    ////////////////////////////////////////////////////////////////////////
    // Checks
    
    public function checkMySQLConnection() {
        $service_id = $this->service_prefix."mysql";
        try {
            $result = DB::selectOne('SELECT 1 AS n');
            if ($result->n != 1) { throw new Exception("Unexpected Database Connection Result", 1); }
            $this->consul_client->checkPass($service_id);
        } catch (Exception $e) {
            $this->consul_client->checkFail($service_id, $e->getMessage());
            Log::warning("Database Connection Failed: ".$e->getMessage());
        }

    }
    
    public function checkQueueConnection($connection=null) {
        $service_id = $this->service_prefix."queue";
        try {
            $pheanstalk = Queue::connection($connection)->getPheanstalk();
            $stats = $pheanstalk->stats();
            if ($stats['uptime'] < 1) { throw new Exception("Unexpected Queue Connection", 1); }
            $this->consul_client->checkPass($service_id);
        } catch (Exception $e) {
            $this->consul_client->checkFail($service_id, $e->getMessage());
            Log::warning("Queue Connection Failed: ".$e->getMessage());
        }
    }

    public function checkPusherConnection() {
        $service_id = $this->service_prefix."pusher";
        try {
            $pusher_client = app('Tokenly\PusherClient\Client');
            $pusher_client->send('/check', time());
            $this->consul_client->checkPass($service_id);
        } catch (Exception $e) {
            $this->consul_client->checkFail($service_id, $e->getMessage());
            Log::warning("Pusher Connection Failed: ".$e->getMessage());
        }
    }

    public function checkXCPDConnection() {
        $service_id = $this->service_prefix."xcpd";
        try {
            $xcpd_client = app('Tokenly\XCPDClient\Client');
            $info = $xcpd_client->get_running_info();
            if (!$info) { throw new Exception("Unexpected response from Counterparty Server", 1); }
            if (!$info['db_caught_up']) { throw new Exception("Counterparty Server is not caught up.  On block ".$info['last_block']['block_index'], 1); }
            $this->consul_client->checkPass($service_id);
        } catch (Exception $e) {
            $this->consul_client->checkFail($service_id, $e->getMessage());
            Log::warning("Counterparty Server Connection Failed: ".$e->getMessage());
        }
    }

    public function checkBitcoindConnection() {
        $service_id = $this->service_prefix."bitcoind";
        try {
            $bitcoind_client = app('Nbobtc\Bitcoind\Bitcoind');
            $info = $bitcoind_client->getinfo();
            $info = (array)$info;
            if (!$info) { throw new Exception("Unexpected response from Bitcoind Server", 1); }
            if (!isset($info['blocks']) OR !$info['blocks']) { throw new Exception("Unexpected response from Bitcoind Server (No Blocks)", 1); }
            if (!isset($info['errors']) AND strlen($info['errors'])) { throw new Exception($info['errors'], 1); }
            
            $this->consul_client->checkPass($service_id);
        } catch (Exception $e) {
            $this->consul_client->checkFail($service_id, $e->getMessage());
            Log::warning("Bitcoind Server Connection Failed: ".$e->getMessage());
        }
    }


    public function checkQueueSizes($queue_params, $connection=null) {
        foreach($queue_params as $queue_name => $max_size) {
            try {
                $queue_size = $this->getQueueSize($queue_name, $connection);

                $service_id = $this->service_prefix."queue_".$queue_name;
                try {
                    if ($queue_size < $max_size) {
                        $this->consul_client->checkPass($service_id);
                    } else {
                        $this->consul_client->checkFail($service_id, "Queue $queue_name was $queue_size");
                    }
                } catch (Exception $e) {
                    Log::error($e->getMessage());
                }

            } catch (Exception $e) {
                try {
                    $this->consul_client->checkFail($service_id, $e->getMessage());
                } catch (Exception $e) {
                    Log::error($e->getMessage());
                }
            }

        }

        return;
    }

    public function getQueueSize($queue_name, $connection=null) {
        $pheanstalk = Queue::connection($connection)->getPheanstalk();
        $stats = $pheanstalk->statsTube($queue_name);
        return $stats['current-jobs-urgent'];
    }

}
