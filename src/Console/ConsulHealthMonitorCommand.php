<?php

namespace Tokenly\ConsulHealthDaemon\Console;


use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\ConsulHealthDaemon\ConsulClient;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ConsulHealthMonitorCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'consul:monitor';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitors Health';


    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['test', null, InputOption::VALUE_NONE, 'Test Mode'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('begin');

        $consul = app(ConsulClient::class);
        $my_service_id = Config::get('consul-health.health_service_id');
        $sleep_delay = Config::get('consul-health.loop_delay');
        while(true) {
            try {
                $consul->checkPass($my_service_id);

            } catch (Exception $e) {
                EventLog::logError('healthcheck.failed', $e);
            }
            try {
                // fire a check event
                //   for other handlers
                Event::fire('consul-health.console.check');

            } catch (Exception $e) {
                EventLog::logError('healthcheck.failed', $e);

                $consul->checkFail($my_service_id, $e->getMessage());
            }

            sleep($sleep_delay);
        }

        $this->info('done');
    }



}
