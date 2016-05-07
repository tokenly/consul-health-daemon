<?php

namespace Tokenly\ConsulHealthDaemon\ServiceProvider;


use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

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

        $this->app->bind('Tokenly\ConsulHealthDaemon\ConsulClient', function($app) {
            $client = new \Tokenly\ConsulHealthDaemon\ConsulClient(Config::get('consul-health.consul_url'));
            return $client;
        });

        $this->app->bind('Tokenly\ConsulHealthDaemon\ServicesChecker', function($app) {
            $checker = new \Tokenly\ConsulHealthDaemon\ServicesChecker($app->make('Tokenly\ConsulHealthDaemon\ConsulClient'));
            $checker->setServicePrefix(Config::get('consul-health.service_id_prefix'));
            return $checker;
        });
    }

    protected function bindConfig()
    {

        $prefix = rtrim(env('CONSUL_HEALTH_SERVICE_ID_PREFIX', 'generic'), '_').'_';

        $config = [
            'consul-health.consul_url'        => env('CONSUL_URL', 'http://consul.service.consul:8500'),
            'consul-health.health_service_id' => env('CONSUL_HEALTH_SERVICE_ID', $prefix.'monitor_health'),
            'consul-health.loop_delay'        => env('CONSUL_LOOP_DELAY', 15),
            'consul-health.service_id_prefix' => $prefix,
        ];

        // set the laravel config
        Config::set($config);
    }

}

