<?php

namespace Tokenly\ConsulHealthDaemon\ServiceProvider;


use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Tokenly\ConsulHealthDaemon\Client;

/*
* ConsulHealthDaemonServiceProvider
*/
class ConsulHealthDaemonServiceProvider extends ServiceProvider
{

    public function boot()
    {
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->bindConfig();

        $this->app->bind('Tokenly\ConsulHealthDaemon\Client', function($app) {
            $client = new Client(Config::get('consul-health.connection_string'), Config::get('consul-health.rpc_user'), Config::get('consul-health.rpc_password'));
            return $client;
        });
    }

    protected function bindConfig()
    {
        // simple config
        $config = [
            'consul-health.consul_url'        => env('CONSUL_URL', 'http://consul.service.consul:8500'),
            'consul-health.health_service_id' => env('CONSUL_HEALTH_SERVICE_ID', 'monitor_health'),
            'consul-health.loop_delay'        => env('CONSUL_LOOP_DELAY', 15),
        ];

        // set the laravel config
        Config::set($config);
    }

}

