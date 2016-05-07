<?php

namespace Tokenly\ConsulHealthDaemon;


use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
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

    public function checkTotalQueueJobsVelocity($queue_velocity_params, $connection=null) {
        foreach($queue_velocity_params as $queue_name => $velocity_params) {
            // echo "checking queue $queue_name.  Now is ".Carbon::now()."\n";
            $service_id = $this->service_prefix."queue_velocity_".$queue_name;

            try {
                $minumum_velocity = $velocity_params[0];
                $time_description = $velocity_params[1];

                $now = Carbon::now();
                $old_time = Carbon::parse('-'.$time_description);
                $seconds_to_check = $old_time->diffInSeconds($now);
                $total_size_now = $this->getTotalQueueJobs($queue_name, $connection);
                $total_size_past = $this->getTotalQueueJobsInThePast($queue_name, $old_time);
                // echo "$queue_name \$now={$now} \$old_time={$old_time} \$total_size_now=".json_encode($total_size_now, 192)." \$total_size_past=".json_encode($total_size_past, 192)."\n";

                // cache $total_size_now
                $expires_at_time = $now->copy()->addSeconds($seconds_to_check)->addMinutes(10);
                $key = 'qTotalJobs_'.$queue_name.'_'.$now->format('Ymd_Hi');
                // echo "PUT key={$key} value=".json_encode($total_size_now, 192)." Expires at ".$expires_at_time."\n";
                Cache::add($key, $total_size_now, $expires_at_time);


                if ($total_size_past === null) {
                    // not enough information - pass for now
                    $this->consul_client->checkPass($service_id);
                    return;
                }

                try {
                    $actual_velocity = $total_size_now - $total_size_past;
                    // echo "$queue_name \$actual_velocity=$actual_velocity\n";
                    if ($actual_velocity >= $minumum_velocity) {
                        $this->consul_client->checkPass($service_id);
                    } else {
                        $this->consul_client->checkFail($service_id, "Queue $queue_name velocity was $actual_velocity in $time_description");
                    }
                } catch (Exception $e) {
                    Log::error($e->getMessage());
                }

            } catch (Exception $e) {
                try {
                    Log::error($e->getMessage());
                    $this->consul_client->checkFail($service_id, $e->getMessage());
                } catch (Exception $e) {
                    Log::error($e->getMessage());
                }
            }

        }

        return;
    }

    protected function getTotalQueueJobsInThePast($queue_name, $old_time) {
        $now = Carbon::now()->second(0);
        $working_time = $old_time->copy()->second(0);

        $max_minutes_to_check = 10;
        while($working_time->lte($now)) {
            $key = 'qTotalJobs_'.$queue_name.'_'.$working_time->format('Ymd_Hi');
            $value = Cache::get($key);
            // echo "getTotalQueueJobsInThePast key={$key} value=".json_encode($value, 192)."\n";
            if ($value !== null) { return $value; }

            $working_time->addMinutes(1);
        }
        return null;
    }

    public function getTotalQueueJobs($queue_name, $connection=null) {
        $pheanstalk = Queue::connection($connection)->getPheanstalk();
        $stats = $pheanstalk->statsTube($queue_name);
        return $stats['total-jobs'];
    }


    public function getQueueSize($queue_name, $connection=null) {
        $pheanstalk = Queue::connection($connection)->getPheanstalk();
        $stats = $pheanstalk->statsTube($queue_name);
        return $stats['current-jobs-urgent'];
    }

}
