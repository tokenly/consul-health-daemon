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

    ////////////////////////////////////////////////////////////////////////
    // Checks
    
    public function checkMySQLConnection() {
        try {
            $result = DB::selectOne('SELECT 1 AS n');
            if ($result->n != 1) { throw new Exception("Unexpected Database Connection Result", 1); }
        } catch (Exception $e) {
            throw new Exception("Database Connection Failed: ".$e->getMessage(), 1);
        }

    }
    
    public function checkQueueConnection($connection=null) {
        try {
            $pheanstalk = Queue::connection($connection)->getPheanstalk();
            $stats = $pheanstalk->stats();
            if ($stats['uptime'] < 1) { throw new Exception("Unexpected Queue Connection", 1); }
        } catch (Exception $e) {
            throw new Exception("Queue Connection Failed: ".$e->getMessage(), 1);
        }
    }

    public function checkPusherConnection() {
        try {
            $pusher_client = app('Tokenly\PusherClient\Client');
            $pusher_client->send('/check', time());
        } catch (Exception $e) {
            throw new Exception("Pusher Connection Failed: ".$e->getMessage(), 1);
        }
    }

    public function checkXCPDConnection() {
        try {
            $xcpd_client = app('Tokenly\XCPDClient\Client');
            $info = $xcpd_client->get_running_info();
            if (!$info) { throw new Exception("Unexpected response from Counterparty Server", 1); }
            if (!$info['db_caught_up']) { throw new Exception("Counterparty Server is not caught up.  On block ".$info['last_block']['block_index'], 1); }
        } catch (Exception $e) {
            throw new Exception("Counterparty Server Connection Failed: ".$e->getMessage(), 1);
        }
    }

    public function checkBitcoindConnection() {
        try {
            $bitcoind_client = app('Nbobtc\Bitcoind\Bitcoind');
            $info = $bitcoind_client->getinfo();
            $info = (array)$info;
            if (!$info) { throw new Exception("Unexpected response from Bitcoind Server", 1); }
            if (!isset($info['blocks']) OR !$info['blocks']) { throw new Exception("Unexpected response from Bitcoind Server (No Blocks)", 1); }
            if (!isset($info['errors']) AND strlen($info['errors'])) { throw new Exception($info['errors'], 1); }
            
        } catch (Exception $e) {
            throw new Exception("Bitcoind Server Connection Failed: ".$e->getMessage(), 1);
        }
    }


    public function pushQueueSizeChecks($service_prefix, $queue_params, $connection=null) {
        foreach($queue_params as $queue_name => $max_size) {
            print "$queue_name\n";
            try {
                $queue_size = $this->getQueueSize($queue_name, $connection);

                $service_id = $service_prefix."_queue_".$queue_name;
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
