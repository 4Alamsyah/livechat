<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ConfigController extends Controller
{
    /**
     * Expose the public (non-secret) connection details the widget needs
     * to reach Reverb and PeerJS's STUN server.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'reverb' => [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme'),
            ],
            'ice_servers' => [
                ['urls' => 'stun:stun.l.google.com:19302'],
            ],
        ]);
    }
}
