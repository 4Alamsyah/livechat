<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WidgetSite;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WidgetSiteController extends Controller
{
    /**
     * Register that a widget is live on the caller's site, so agents can
     * target announcements at sites that have never opened a chat.
     */
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => ['required', 'string', 'max:120'],
        ]);

        WidgetSite::heartbeat($validated['property_id'], $this->resolveOrigin($request));

        return response()->noContent();
    }

    /**
     * The browser sets Origin/Referer itself, so it is a far better source for
     * the embedding site than anything the page's own JavaScript could send.
     */
    private function resolveOrigin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');

        if (! $origin && $referer = $request->headers->get('Referer')) {
            $parts = parse_url($referer);
            $origin = isset($parts['scheme'], $parts['host'])
                ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
                : null;
        }

        return $origin ? mb_substr($origin, 0, 255) : null;
    }
}
