<?php

namespace app\process;

use app\service\IdGen;
use app\service\Xiangqi;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

class WsServer
{
    private const TURN_SECONDS = 20;
    private const CUSTODY_MOVE_DELAY = 0.8;

    /** @var array<string, array> gameId => game state */
    private array $games = [];

    /** @var array<int, array{userId:string,gameId:string}> connectionId => binding */
    private array $bindings = [];

    /** @var array<string, array<string, TcpConnection>> gameId => userId => connection */
    private array $connections = [];

    public function onWorkerStart(Worker $worker): void
    {
    }

    public function onConnect(TcpConnection $connection): void
    {
    }

    public function onMessage(TcpConnection $connection, $data): void
    {
        $msg = json_decode($data, true);
        if (!is_array($msg) || !isset($msg['type'])) {
            $this->error($connection, 'bad_message');
            return;
        }
        switch ($msg['type']) {
            case 'join':
                $this->onJoin($connection, $msg);
                break;
            case 'move':
                $this->onMove($connection, $msg);
                break;
            case 'resign':
                $this->onResign($connection);
                break;
            case 'cancel_custody':
                $this->onCancelCustody($connection);
                break;
            default:
                $this->error($connection, 'unknown_type');
        }
    }

    public function onClose(TcpConnection $connection): void
    {
        $bind = $this->bindings[$connection->id] ?? null;
        if (!$bind) return;
        unset($this->bindings[$connection->id]);
        $gameId = $bind['gameId'];
        $userId = $bind['userId'];
        // Only drop the slot if this connection still owns it (client may have already replaced it)
        if (isset($this->connections[$gameId][$userId])
            && $this->connections[$gameId][$userId] === $connection) {
            unset($this->connections[$gameId][$userId]);
            $this->broadcastPresence($gameId);
        }
    }

    private function onJoin(TcpConnection $connection, array $msg): void
    {
        $gameId = (string)($msg['gameId'] ?? '');
        if ($gameId === '') {
            $this->error($connection, 'missing_gameId');
            return;
        }
        $userId = (string)($msg['userId'] ?? '');
        if ($userId === '') {
            $userId = IdGen::userId();
        }
        $nickname = trim((string)($msg['nickname'] ?? '')) ?: ('玩家' . substr(preg_replace('/[^A-Za-z0-9]/', '', $userId), 0, 4));

        $game = $this->games[$gameId] ?? null;
        if (!$game) {
            $game = $this->newGame($gameId);
        }

        // Reattach if userId already a player
        $side = null;
        if ($game['red'] === $userId) $side = 'r';
        elseif ($game['black'] === $userId) $side = 'b';

        if ($side === null) {
            // Assign next free slot
            if ($game['red'] === null) {
                $game['red'] = $userId;
                $side = 'r';
            } elseif ($game['black'] === null) {
                $game['black'] = $userId;
                $side = 'b';
            } else {
                $this->error($connection, 'game_full');
                return;
            }
        }
        $game['nicknames'][$userId] = $nickname;

        // Start play when both slots filled and not already running
        if ($game['status'] === 'waiting' && $game['red'] && $game['black']) {
            $game['status'] = 'playing';
            $game['turn'] = 'r';
            $game['deadlineTs'] = microtime(true) + self::TURN_SECONDS;
            $this->games[$gameId] = $game;
            $this->scheduleTimer($gameId);
        } else {
            $this->games[$gameId] = $game;
        }

        // Bind connection
        // If there's a previous connection for this user, close it gracefully
        if (isset($this->connections[$gameId][$userId])
            && $this->connections[$gameId][$userId] !== $connection) {
            $old = $this->connections[$gameId][$userId];
            unset($this->bindings[$old->id]);
            try { $old->close(); } catch (\Throwable $e) {}
        }
        $this->connections[$gameId][$userId] = $connection;
        $this->bindings[$connection->id] = ['userId' => $userId, 'gameId' => $gameId];

        $this->send($connection, [
            'type' => 'joined',
            'userId' => $userId,
            'gameId' => $gameId,
            'side' => $side,
        ]);
        $this->broadcastState($gameId);
    }

    private function onMove(TcpConnection $connection, array $msg): void
    {
        $bind = $this->bindings[$connection->id] ?? null;
        if (!$bind) { $this->error($connection, 'not_in_game'); return; }
        $gameId = $bind['gameId'];
        $userId = $bind['userId'];
        $game = $this->games[$gameId] ?? null;
        if (!$game) { $this->error($connection, 'no_game'); return; }
        if ($game['status'] !== 'playing') { $this->error($connection, 'not_playing'); return; }

        $mySide = $game['red'] === $userId ? 'r' : ($game['black'] === $userId ? 'b' : null);
        if ($mySide === null) { $this->error($connection, 'spectator'); return; }
        if ($mySide !== $game['turn']) { $this->error($connection, 'not_your_turn'); return; }

        $from = $msg['from'] ?? null;
        $to = $msg['to'] ?? null;
        if (!is_array($from) || !is_array($to) || count($from) !== 2 || count($to) !== 2) {
            $this->error($connection, 'bad_move');
            return;
        }
        $move = [(int)$from[0], (int)$from[1], (int)$to[0], (int)$to[1]];
        if (!Xiangqi::isLegalMove($game['board'], $mySide, $move)) {
            $this->error($connection, 'illegal_move');
            return;
        }
        $this->applyMoveAndAdvance($gameId, $move, false);
    }

    private function onResign(TcpConnection $connection): void
    {
        $bind = $this->bindings[$connection->id] ?? null;
        if (!$bind) return;
        $gameId = $bind['gameId'];
        $userId = $bind['userId'];
        $game = $this->games[$gameId] ?? null;
        if (!$game || $game['status'] !== 'playing') return;
        $mySide = $game['red'] === $userId ? 'r' : ($game['black'] === $userId ? 'b' : null);
        if ($mySide === null) return;
        $this->endGame($gameId, Xiangqi::opponent($mySide), 'resign');
    }

    private function newGame(string $gameId): array
    {
        $game = [
            'red' => null,
            'black' => null,
            'nicknames' => [],
            'board' => Xiangqi::initial(),
            'turn' => 'r',
            'history' => [],
            'status' => 'waiting', // waiting | playing | over
            'winner' => null,
            'endReason' => null,
            'lastMove' => null,
            'deadlineTs' => null,
            'timerId' => null,
            'custody' => ['r' => false, 'b' => false],
        ];
        $this->games[$gameId] = $game;
        $this->connections[$gameId] = [];
        return $game;
    }

    private function scheduleTimer(string $gameId): void
    {
        $game = $this->games[$gameId];
        if ($game['timerId']) {
            Timer::del($game['timerId']);
            $this->games[$gameId]['timerId'] = null;
        }

        $side = $game['turn'];
        $inCustody = $game['custody'][$side] ?? false;

        if ($inCustody) {
            $tid = Timer::add(self::CUSTODY_MOVE_DELAY, function () use ($gameId) {
                $game = $this->games[$gameId] ?? null;
                if (!$game || $game['status'] !== 'playing') return;
                if (!($game['custody'][$game['turn']] ?? false)) return;
                $this->executeAutoMove($gameId);
            }, [], false);
        } else {
            $tid = Timer::add(self::TURN_SECONDS, function () use ($gameId) {
                $game = $this->games[$gameId] ?? null;
                if (!$game || $game['status'] !== 'playing') return;
                $this->games[$gameId]['custody'][$game['turn']] = true;
                $this->broadcastState($gameId);
                $this->executeAutoMove($gameId);
            }, [], false);
        }
        $this->games[$gameId]['timerId'] = $tid;
    }

    private function executeAutoMove(string $gameId): void
    {
        $game = $this->games[$gameId] ?? null;
        if (!$game || $game['status'] !== 'playing') return;
        $side = $game['turn'];
        $move = Xiangqi::randomMove($game['board'], $side);
        if ($move === null) {
            $this->endGame($gameId, Xiangqi::opponent($side), 'stalemate');
            return;
        }
        $this->applyMoveAndAdvance($gameId, $move, true);
    }

    private function onCancelCustody(TcpConnection $connection): void
    {
        $bind = $this->bindings[$connection->id] ?? null;
        if (!$bind) return;
        $gameId = $bind['gameId'];
        $userId = $bind['userId'];
        $game = $this->games[$gameId] ?? null;
        if (!$game || $game['status'] !== 'playing') return;
        $mySide = $game['red'] === $userId ? 'r' : ($game['black'] === $userId ? 'b' : null);
        if ($mySide === null) return;
        $this->games[$gameId]['custody'][$mySide] = false;
        // If it's currently this player's turn, reschedule with normal countdown
        if ($game['turn'] === $mySide) {
            $this->games[$gameId]['deadlineTs'] = microtime(true) + self::TURN_SECONDS;
            $this->scheduleTimer($gameId);
        }
        $this->broadcastState($gameId);
    }

    private function applyMoveAndAdvance(string $gameId, array $move, bool $auto): void
    {
        $game = $this->games[$gameId];
        $side = $game['turn'];
        $newBoard = Xiangqi::applyMove($game['board'], $move);
        $game['board'] = $newBoard;
        $game['history'][] = [
            'side' => $side,
            'move' => $move,
            'auto' => $auto,
            'at' => microtime(true),
        ];
        $game['lastMove'] = $move;
        $next = Xiangqi::opponent($side);
        $game['turn'] = $next;
        $this->games[$gameId] = $game;

        $status = Xiangqi::status($newBoard, $next);
        if ($status['status'] !== 'ongoing') {
            $this->endGame($gameId, $status['winner'], $status['status']);
            return;
        }

        $nextInCustody = $this->games[$gameId]['custody'][$next] ?? false;
        $this->games[$gameId]['deadlineTs'] = $nextInCustody ? null : (microtime(true) + self::TURN_SECONDS);
        $this->scheduleTimer($gameId);
        $this->broadcastState($gameId);
    }

    private function endGame(string $gameId, ?string $winner, string $reason): void
    {
        $game = $this->games[$gameId] ?? null;
        if (!$game) return;
        if ($game['timerId']) {
            Timer::del($game['timerId']);
            $game['timerId'] = null;
        }
        $game['status'] = 'over';
        $game['winner'] = $winner;
        $game['endReason'] = $reason;
        $game['deadlineTs'] = null;
        $this->games[$gameId] = $game;
        $this->broadcastState($gameId);
    }

    private function broadcastState(string $gameId): void
    {
        $game = $this->games[$gameId] ?? null;
        if (!$game) return;
        $payload = [
            'type' => 'state',
            'gameId' => $gameId,
            'board' => $game['board'],
            'turn' => $game['turn'],
            'status' => $game['status'],
            'winner' => $game['winner'],
            'endReason' => $game['endReason'],
            'lastMove' => $game['lastMove'],
            'deadlineTs' => $game['deadlineTs'],
            'turnSeconds' => self::TURN_SECONDS,
            'players' => [
                'r' => $game['red'] ? ['userId' => $game['red'], 'nickname' => $game['nicknames'][$game['red']] ?? '', 'online' => isset($this->connections[$gameId][$game['red']])] : null,
                'b' => $game['black'] ? ['userId' => $game['black'], 'nickname' => $game['nicknames'][$game['black']] ?? '', 'online' => isset($this->connections[$gameId][$game['black']])] : null,
            ],
            'historyLen' => count($game['history']),
            'custody' => $game['custody'],
        ];
        foreach (($this->connections[$gameId] ?? []) as $conn) {
            $this->send($conn, $payload);
        }
    }

    private function broadcastPresence(string $gameId): void
    {
        // Re-send full state to refresh online flags
        $this->broadcastState($gameId);
    }

    private function send(TcpConnection $conn, array $payload): void
    {
        try {
            $conn->send(json_encode($payload, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // connection probably gone; will be cleaned up by onClose
        }
    }

    private function error(TcpConnection $conn, string $code): void
    {
        $this->send($conn, ['type' => 'error', 'code' => $code]);
    }
}
