<?php

namespace Tokenly\ConsulHealthDaemon\HealthController;

use Exception;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
* HealthController
*/
class HealthController extends Controller
{
    
    public function __construct() {
    
    }

    public function healthcheck($checkType) {
        try {
            // fire a check event
            //   for other handlers
            Event::fire('consul-health.http.check', $checkType);

        } catch (Exception $e) {
            Log::error('Health check failed: '.$e->getMessage());
            return new Response('failed', 500);
        }

        return new Response('ok', 200);
    }

}
