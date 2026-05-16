<?php

namespace app\service;

/**
 * Chinese chess engine.
 * Board: 9 cols (x=0..8) x 10 rows (y=0..9). y=0 top (Black), y=9 bottom (Red).
 * Pieces: 2-char strings. side: r|b. type: K(将/帅) A(士) E(相/象) H(马) R(车) C(炮) S(卒/兵).
 * Empty squares: null.
 * Move: [fx, fy, tx, ty].
 */
class Xiangqi
{
    public static function initial(): array
    {
        $row = fn(string $s) => array_map(fn($p) => $p === '..' ? null : $p, [
            substr($s, 0, 2), substr($s, 3, 2), substr($s, 6, 2),
            substr($s, 9, 2), substr($s, 12, 2), substr($s, 15, 2),
            substr($s, 18, 2), substr($s, 21, 2), substr($s, 24, 2),
        ]);
        // Each row is 9 cells, each cell 2 chars separated by spaces.
        $rows = [
            'bR bH bE bA bK bA bE bH bR', // y=0
            '.. .. .. .. .. .. .. .. ..', // y=1
            '.. bC .. .. .. .. .. bC ..', // y=2
            'bS .. bS .. bS .. bS .. bS', // y=3
            '.. .. .. .. .. .. .. .. ..', // y=4
            '.. .. .. .. .. .. .. .. ..', // y=5
            'rS .. rS .. rS .. rS .. rS', // y=6
            '.. rC .. .. .. .. .. rC ..', // y=7
            '.. .. .. .. .. .. .. .. ..', // y=8
            'rR rH rE rA rK rA rE rH rR', // y=9
        ];
        // Transform: board[y][x]
        $board = [];
        foreach ($rows as $y => $r) {
            $board[$y] = $row($r);
        }
        return $board;
    }

    public static function pieceAt(array $board, int $x, int $y): ?string
    {
        if ($x < 0 || $x > 8 || $y < 0 || $y > 9) return null;
        return $board[$y][$x] ?? null;
    }

    public static function sideOf(?string $p): ?string
    {
        return $p ? $p[0] : null;
    }

    public static function typeOf(?string $p): ?string
    {
        return $p ? $p[1] : null;
    }

    public static function opponent(string $side): string
    {
        return $side === 'r' ? 'b' : 'r';
    }

    /** Apply a move and return the new board (does not validate). */
    public static function applyMove(array $board, array $move): array
    {
        [$fx, $fy, $tx, $ty] = $move;
        $piece = $board[$fy][$fx];
        $board[$fy][$fx] = null;
        $board[$ty][$tx] = $piece;
        return $board;
    }

    /** Pseudo-legal moves for a single piece, ignoring self-check. */
    public static function pieceMoves(array $board, int $x, int $y): array
    {
        $p = $board[$y][$x] ?? null;
        if (!$p) return [];
        $side = $p[0];
        $type = $p[1];
        $moves = [];
        $add = function(int $tx, int $ty) use (&$moves, $board, $side, $x, $y) {
            if ($tx < 0 || $tx > 8 || $ty < 0 || $ty > 9) return;
            $dst = $board[$ty][$tx];
            if ($dst === null || $dst[0] !== $side) {
                $moves[] = [$x, $y, $tx, $ty];
            }
        };
        $inPalace = function(int $cx, int $cy) use ($side) {
            if ($cx < 3 || $cx > 5) return false;
            return $side === 'r' ? ($cy >= 7 && $cy <= 9) : ($cy >= 0 && $cy <= 2);
        };
        $ownSideOfRiver = function(int $cy) use ($side) {
            return $side === 'r' ? $cy >= 5 : $cy <= 4;
        };

        switch ($type) {
            case 'K': // King: 1 step ortho, must stay in palace
                foreach ([[1,0],[-1,0],[0,1],[0,-1]] as [$dx,$dy]) {
                    $tx = $x + $dx; $ty = $y + $dy;
                    if ($inPalace($tx, $ty)) $add($tx, $ty);
                }
                break;
            case 'A': // Advisor: 1 step diag, in palace
                foreach ([[1,1],[1,-1],[-1,1],[-1,-1]] as [$dx,$dy]) {
                    $tx = $x + $dx; $ty = $y + $dy;
                    if ($inPalace($tx, $ty)) $add($tx, $ty);
                }
                break;
            case 'E': // Elephant: 2 diag, no blocking eye, own side of river
                foreach ([[2,2],[2,-2],[-2,2],[-2,-2]] as [$dx,$dy]) {
                    $tx = $x + $dx; $ty = $y + $dy;
                    $mx = $x + intdiv($dx, 2); $my = $y + intdiv($dy, 2);
                    if (!$ownSideOfRiver($ty)) continue;
                    if (self::pieceAt($board, $mx, $my) !== null) continue;
                    $add($tx, $ty);
                }
                break;
            case 'H': // Horse: L-shape, blocked by hobbling foot
                $jumps = [
                    [1, 2, 0, 1], [-1, 2, 0, 1],
                    [1, -2, 0, -1], [-1, -2, 0, -1],
                    [2, 1, 1, 0], [2, -1, 1, 0],
                    [-2, 1, -1, 0], [-2, -1, -1, 0],
                ];
                foreach ($jumps as [$dx, $dy, $bx, $by]) {
                    $tx = $x + $dx; $ty = $y + $dy;
                    $blockX = $x + $bx; $blockY = $y + $by;
                    if (self::pieceAt($board, $blockX, $blockY) !== null) continue;
                    $add($tx, $ty);
                }
                break;
            case 'R': // Chariot: slide ortho
                foreach ([[1,0],[-1,0],[0,1],[0,-1]] as [$dx,$dy]) {
                    $tx = $x + $dx; $ty = $y + $dy;
                    while ($tx >= 0 && $tx <= 8 && $ty >= 0 && $ty <= 9) {
                        $dst = $board[$ty][$tx];
                        if ($dst === null) {
                            $moves[] = [$x, $y, $tx, $ty];
                        } else {
                            if ($dst[0] !== $side) $moves[] = [$x, $y, $tx, $ty];
                            break;
                        }
                        $tx += $dx; $ty += $dy;
                    }
                }
                break;
            case 'C': // Cannon: slide ortho for non-capture; capture by jumping exactly one piece
                foreach ([[1,0],[-1,0],[0,1],[0,-1]] as [$dx,$dy]) {
                    $tx = $x + $dx; $ty = $y + $dy;
                    $jumped = false;
                    while ($tx >= 0 && $tx <= 8 && $ty >= 0 && $ty <= 9) {
                        $dst = $board[$ty][$tx];
                        if (!$jumped) {
                            if ($dst === null) {
                                $moves[] = [$x, $y, $tx, $ty];
                            } else {
                                $jumped = true;
                            }
                        } else {
                            if ($dst !== null) {
                                if ($dst[0] !== $side) $moves[] = [$x, $y, $tx, $ty];
                                break;
                            }
                        }
                        $tx += $dx; $ty += $dy;
                    }
                }
                break;
            case 'S': // Soldier: forward only before river, +sideways after crossing
                $forward = $side === 'r' ? -1 : 1;
                $add($x, $y + $forward);
                $crossed = $side === 'r' ? $y <= 4 : $y >= 5;
                if ($crossed) {
                    $add($x + 1, $y);
                    $add($x - 1, $y);
                }
                break;
        }
        return $moves;
    }

    public static function findKing(array $board, string $side): ?array
    {
        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 9; $x++) {
                $p = $board[$y][$x];
                if ($p && $p[0] === $side && $p[1] === 'K') return [$x, $y];
            }
        }
        return null;
    }

    /** Check whether `$side` is currently in check (including kings-facing rule). */
    public static function isInCheck(array $board, string $side): bool
    {
        $king = self::findKing($board, $side);
        if (!$king) return true; // king captured = max-in-check
        [$kx, $ky] = $king;
        $opp = self::opponent($side);

        // Kings facing each other on same file with no piece between
        $oppKing = self::findKing($board, $opp);
        if ($oppKing && $oppKing[0] === $kx) {
            $clear = true;
            $y1 = min($ky, $oppKing[1]); $y2 = max($ky, $oppKing[1]);
            for ($y = $y1 + 1; $y < $y2; $y++) {
                if ($board[$y][$kx] !== null) { $clear = false; break; }
            }
            if ($clear) return true;
        }

        // Any opponent piece can move to king's square?
        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 9; $x++) {
                $p = $board[$y][$x];
                if (!$p || $p[0] !== $opp) continue;
                foreach (self::pieceMoves($board, $x, $y) as $m) {
                    if ($m[2] === $kx && $m[3] === $ky) return true;
                }
            }
        }
        return false;
    }

    /** All fully-legal moves for `$side` (those not leaving own king in check). */
    public static function legalMoves(array $board, string $side): array
    {
        $legal = [];
        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 9; $x++) {
                $p = $board[$y][$x];
                if (!$p || $p[0] !== $side) continue;
                foreach (self::pieceMoves($board, $x, $y) as $m) {
                    $after = self::applyMove($board, $m);
                    if (!self::isInCheck($after, $side)) $legal[] = $m;
                }
            }
        }
        return $legal;
    }

    public static function isLegalMove(array $board, string $side, array $move): bool
    {
        [$fx, $fy] = [$move[0], $move[1]];
        $p = $board[$fy][$fx] ?? null;
        if (!$p || $p[0] !== $side) return false;
        foreach (self::legalMoves($board, $side) as $m) {
            if ($m === $move) return true;
        }
        return false;
    }

    /**
     * Returns ['status' => 'ongoing'|'checkmate'|'stalemate', 'winner' => 'r'|'b'|null].
     * In Chinese chess stalemate (困毙) is also a loss for the side to move.
     */
    public static function status(array $board, string $sideToMove): array
    {
        if (self::findKing($board, 'r') === null) return ['status' => 'king_captured', 'winner' => 'b'];
        if (self::findKing($board, 'b') === null) return ['status' => 'king_captured', 'winner' => 'r'];
        if (self::legalMoves($board, $sideToMove)) return ['status' => 'ongoing', 'winner' => null];
        $winner = self::opponent($sideToMove);
        $checked = self::isInCheck($board, $sideToMove);
        return ['status' => $checked ? 'checkmate' : 'stalemate', 'winner' => $winner];
    }

    /** Pick a uniform random legal move. Returns null if no legal move. */
    public static function randomMove(array $board, string $side): ?array
    {
        $moves = self::legalMoves($board, $side);
        if (!$moves) return null;
        return $moves[array_rand($moves)];
    }
}
