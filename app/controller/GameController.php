<?php

namespace app\controller;

use app\service\IdGen;
use support\Request;

class GameController
{
    public function lobby(Request $request)
    {
        return view('lobby/index', []);
    }

    public function create(Request $request)
    {
        $id = IdGen::gameId();
        return json([
            'code' => 0,
            'id' => $id,
            'url' => '/game/' . $id,
        ]);
    }

    public function play(Request $request, string $id)
    {
        if (!preg_match('/^[A-Za-z0-9]{19}$/', $id)) {
            return response('Invalid game id', 400);
        }
        $host = $request->host(true);
        // Strip any :port suffix; WS port is fixed.
        $hostNoPort = preg_replace('/:\d+$/', '', $host);
        $isHttps = strtolower($request->header('x-forwarded-proto', '')) === 'https' || $request->header('x-forwarded-ssl') === 'on';
        $wsScheme = $isHttps ? 'wss' : 'ws';
        $wsUrl = sprintf('%s://%s:8788', $wsScheme, $hostNoPort);
        return view('game/play', [
            'gameId' => $id,
            'wsUrl' => $wsUrl,
        ]);
    }
}
