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
        $host = config('broadcasting.connections.reverb.options.host', 'localhost');

        // In production with a reverse proxy, use the request's host (e.g., livechat.bmt-system2.com)
        // In local development, keep using localhost
        if (config('app.env') === 'production') {
            $host = request()->getHost();
        }

        $iceServers = [
            ['urls' => 'stun:stun.l.google.com:19302'],
            ['urls' => 'stun:stun1.l.google.com:19302'],
        ];

        $turnServers = env('TURN_SERVERS');
        if ($turnServers) {
            $urls = array_map('trim', explode(',', $turnServers));
            $iceServers[] = [
                'urls' => $urls,
            ];
        }

        return response()->json([
            'reverb' => [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => $host,
                'port' => config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme'),
            ],
            'ice_servers' => $iceServers,
        ]);
    }
}
