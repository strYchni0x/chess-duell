<?php
/**
 * Chess Duell – serverseitige Schach-Regel-Engine.
 *
 * 1:1-Portierung von assets/js/engine.js nach PHP. Der Server nutzt diese
 * Engine, um jeden Zug selbst zu validieren und FEN/SAN/Spielende selbst zu
 * berechnen. Dadurch kann ein manipulierter Client keinen illegalen Zug
 * durchsetzen (kein Cheaten möglich).
 *
 * Brettdarstellung: board[r][c]
 *   r = 0 entspricht Reihe 8 (oben), r = 7 entspricht Reihe 1 (unten)
 *   c = 0 entspricht Linie a, c = 7 entspricht Linie h
 *   Felder als String "wp" (weisser Bauer), "bk" (schwarzer König) ... oder null.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chess_Duell_Engine {

    const START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    public $board;
    public $turn;
    public $castling;
    public $ep;
    public $halfmove;
    public $fullmove;

    private static $KNIGHT = array(array(-2, -1), array(-2, 1), array(-1, -2), array(-1, 2), array(1, -2), array(1, 2), array(2, -1), array(2, 1));
    private static $DIAG   = array(array(-1, -1), array(-1, 1), array(1, -1), array(1, 1));
    private static $ORTH   = array(array(-1, 0), array(1, 0), array(0, -1), array(0, 1));

    public function __construct($fen = null) {
        $this->load($fen ? $fen : self::START_FEN);
    }

    private static function sq($r, $c) { return chr(97 + $c) . (8 - $r); }
    private static function rc($square) {
        return array('r' => 8 - intval($square[1]), 'c' => ord($square[0]) - 97);
    }
    private static function inb($r, $c) { return $r >= 0 && $r < 8 && $c >= 0 && $c < 8; }

    public function load($fen) {
        $parts = preg_split('/\s+/', trim($fen));
        $rows  = explode('/', $parts[0]);
        $this->board = array();
        for ($r = 0; $r < 8; $r++) {
            $row = array_fill(0, 8, null);
            $c = 0;
            $rowStr = isset($rows[$r]) ? $rows[$r] : '8';
            $len = strlen($rowStr);
            for ($i = 0; $i < $len; $i++) {
                $ch = $rowStr[$i];
                if (ctype_digit($ch)) {
                    $c += intval($ch);
                } else {
                    $color = ($ch === strtoupper($ch)) ? 'w' : 'b';
                    $row[$c] = $color . strtolower($ch);
                    $c++;
                }
            }
            $this->board[] = $row;
        }
        $this->turn = isset($parts[1]) ? $parts[1] : 'w';
        $cr = isset($parts[2]) ? $parts[2] : '-';
        $this->castling = array(
            'wK' => strpos($cr, 'K') !== false, 'wQ' => strpos($cr, 'Q') !== false,
            'bK' => strpos($cr, 'k') !== false, 'bQ' => strpos($cr, 'q') !== false,
        );
        $this->ep       = (isset($parts[3]) && $parts[3] !== '-') ? $parts[3] : null;
        $this->halfmove = isset($parts[4]) ? intval($parts[4]) : 0;
        $this->fullmove = isset($parts[5]) ? intval($parts[5]) : 1;
    }

    public function fen() {
        $rows = array();
        for ($r = 0; $r < 8; $r++) {
            $s = ''; $empty = 0;
            for ($c = 0; $c < 8; $c++) {
                $p = $this->board[$r][$c];
                if (!$p) {
                    $empty++;
                } else {
                    if ($empty) { $s .= $empty; $empty = 0; }
                    $ch = $p[1];
                    $s .= ($p[0] === 'w') ? strtoupper($ch) : $ch;
                }
            }
            if ($empty) { $s .= $empty; }
            $rows[] = $s;
        }
        $cr = ($this->castling['wK'] ? 'K' : '') . ($this->castling['wQ'] ? 'Q' : '') .
              ($this->castling['bK'] ? 'k' : '') . ($this->castling['bQ'] ? 'q' : '');
        if ($cr === '') { $cr = '-'; }
        return implode('/', $rows) . ' ' . $this->turn . ' ' . $cr . ' ' .
               ($this->ep ? $this->ep : '-') . ' ' . $this->halfmove . ' ' . $this->fullmove;
    }

    public function get($square) {
        $p = self::rc($square);
        return $this->board[$p['r']][$p['c']];
    }

    public function copy() { return new self($this->fen()); }

    /** Wird das Feld (r,c) von Farbe $by angegriffen? */
    public function isAttacked($r, $c, $by) {
        $b = $this->board;
        // Bauern
        if ($by === 'w') {
            foreach (array(-1, 1) as $dc) { $pr = $r + 1; $pc = $c + $dc;
                if (self::inb($pr, $pc) && $b[$pr][$pc] === 'wp') { return true; } }
        } else {
            foreach (array(-1, 1) as $dc) { $pr = $r - 1; $pc = $c + $dc;
                if (self::inb($pr, $pc) && $b[$pr][$pc] === 'bp') { return true; } }
        }
        // Springer
        foreach (self::$KNIGHT as $o) {
            $nr = $r + $o[0]; $nc = $c + $o[1];
            if (self::inb($nr, $nc) && $b[$nr][$nc] === $by . 'n') { return true; }
        }
        // König benachbart
        for ($dr = -1; $dr <= 1; $dr++) {
            for ($dc = -1; $dc <= 1; $dc++) {
                if ($dr === 0 && $dc === 0) { continue; }
                $nr = $r + $dr; $nc = $c + $dc;
                if (self::inb($nr, $nc) && $b[$nr][$nc] === $by . 'k') { return true; }
            }
        }
        // Läufer / Dame (diagonal)
        foreach (self::$DIAG as $dir) {
            for ($step = 1; ; $step++) {
                $nr = $r + $dir[0] * $step; $nc = $c + $dir[1] * $step;
                if (!self::inb($nr, $nc)) { break; }
                $t = $b[$nr][$nc];
                if ($t) { if ($t === $by . 'b' || $t === $by . 'q') { return true; } break; }
            }
        }
        // Turm / Dame (gerade)
        foreach (self::$ORTH as $dir) {
            for ($step = 1; ; $step++) {
                $nr = $r + $dir[0] * $step; $nc = $c + $dir[1] * $step;
                if (!self::inb($nr, $nc)) { break; }
                $t = $b[$nr][$nc];
                if ($t) { if ($t === $by . 'r' || $t === $by . 'q') { return true; } break; }
            }
        }
        return false;
    }

    public function kingSquare($color) {
        for ($r = 0; $r < 8; $r++) {
            for ($c = 0; $c < 8; $c++) {
                if ($this->board[$r][$c] === $color . 'k') { return array('r' => $r, 'c' => $c); }
            }
        }
        return null;
    }

    public function inCheck($color = null) {
        if ($color === null) { $color = $this->turn; }
        $k = $this->kingSquare($color);
        if (!$k) { return false; }
        return $this->isAttacked($k['r'], $k['c'], $color === 'w' ? 'b' : 'w');
    }

    private function mk($fr, $fc, $tr, $tc, $piece, $captured, $me) {
        return array('from' => self::sq($fr, $fc), 'to' => self::sq($tr, $tc), 'piece' => $piece,
            'color' => $me, 'captured' => $captured, 'promotion' => null, 'flags' => $captured ? 'c' : 'n');
    }

    /** Pseudo-legale Züge (ohne Schach-Prüfung) für die Seite am Zug. */
    private function pseudoMoves($onlySquare = null) {
        $me  = $this->turn; $opp = $me === 'w' ? 'b' : 'w'; $b = $this->board;
        $moves = array();
        $only = $onlySquare ? self::rc($onlySquare) : null;

        $pushPawn = function ($fr, $fc, $tr, $tc, $flags, $captured, $promoRow) use (&$moves, $me) {
            if ($tr === $promoRow) {
                foreach (array('q', 'r', 'b', 'n') as $pr) {
                    $moves[] = array('from' => self::sq($fr, $fc), 'to' => self::sq($tr, $tc), 'piece' => 'p',
                        'color' => $me, 'captured' => $captured, 'promotion' => $pr, 'flags' => $flags . 'p');
                }
            } else {
                $moves[] = array('from' => self::sq($fr, $fc), 'to' => self::sq($tr, $tc), 'piece' => 'p',
                    'color' => $me, 'captured' => $captured, 'promotion' => null, 'flags' => $flags);
            }
        };

        for ($r = 0; $r < 8; $r++) {
            for ($c = 0; $c < 8; $c++) {
                if ($only && ($only['r'] !== $r || $only['c'] !== $c)) { continue; }
                $p = $b[$r][$c];
                if (!$p || $p[0] !== $me) { continue; }
                $type = $p[1];

                if ($type === 'p') {
                    $dir = $me === 'w' ? -1 : 1;
                    $startRow = $me === 'w' ? 6 : 1;
                    $promoRow = $me === 'w' ? 0 : 7;
                    $nr = $r + $dir;
                    if (self::inb($nr, $c) && !$b[$nr][$c]) {
                        $pushPawn($r, $c, $nr, $c, 'n', null, $promoRow);
                        if ($r === $startRow && !$b[$r + 2 * $dir][$c]) {
                            $moves[] = array('from' => self::sq($r, $c), 'to' => self::sq($r + 2 * $dir, $c),
                                'piece' => 'p', 'color' => $me, 'captured' => null, 'promotion' => null, 'flags' => 'b');
                        }
                    }
                    foreach (array(-1, 1) as $dcp) {
                        $cc = $c + $dcp;
                        if (!self::inb($nr, $cc)) { continue; }
                        $t = $b[$nr][$cc];
                        if ($t && $t[0] === $opp) {
                            $pushPawn($r, $c, $nr, $cc, 'c', $t, $promoRow);
                        } elseif (!$t && $this->ep && self::sq($nr, $cc) === $this->ep) {
                            $moves[] = array('from' => self::sq($r, $c), 'to' => self::sq($nr, $cc), 'piece' => 'p',
                                'color' => $me, 'captured' => $opp . 'p', 'promotion' => null, 'flags' => 'e');
                        }
                    }
                } elseif ($type === 'n') {
                    foreach (self::$KNIGHT as $o) {
                        $kr = $r + $o[0]; $kc = $c + $o[1];
                        if (!self::inb($kr, $kc)) { continue; }
                        $kt = $b[$kr][$kc];
                        if (!$kt) { $moves[] = $this->mk($r, $c, $kr, $kc, 'n', null, $me); }
                        elseif ($kt[0] === $opp) { $moves[] = $this->mk($r, $c, $kr, $kc, 'n', $kt, $me); }
                    }
                } elseif ($type === 'k') {
                    for ($dr = -1; $dr <= 1; $dr++) {
                        for ($dc = -1; $dc <= 1; $dc++) {
                            if ($dr === 0 && $dc === 0) { continue; }
                            $xr = $r + $dr; $xc = $c + $dc;
                            if (!self::inb($xr, $xc)) { continue; }
                            $xt = $b[$xr][$xc];
                            if (!$xt) { $moves[] = $this->mk($r, $c, $xr, $xc, 'k', null, $me); }
                            elseif ($xt[0] === $opp) { $moves[] = $this->mk($r, $c, $xr, $xc, 'k', $xt, $me); }
                        }
                    }
                    $this->castlingMoves($me, $r, $c, $moves);
                } else {
                    $dirs = $type === 'b' ? self::$DIAG : ($type === 'r' ? self::$ORTH : array_merge(self::$DIAG, self::$ORTH));
                    foreach ($dirs as $d) {
                        for ($st = 1; ; $st++) {
                            $sr = $r + $d[0] * $st; $scc = $c + $d[1] * $st;
                            if (!self::inb($sr, $scc)) { break; }
                            $stt = $b[$sr][$scc];
                            if (!$stt) { $moves[] = $this->mk($r, $c, $sr, $scc, $type, null, $me); }
                            else { if ($stt[0] === $opp) { $moves[] = $this->mk($r, $c, $sr, $scc, $type, $stt, $me); } break; }
                        }
                    }
                }
            }
        }
        return $moves;
    }

    private function castlingMoves($me, $r, $c, &$moves) {
        $opp = $me === 'w' ? 'b' : 'w'; $b = $this->board;
        $homeRow = $me === 'w' ? 7 : 0;
        if ($r !== $homeRow || $c !== 4) { return; }
        if ($this->isAttacked($homeRow, 4, $opp)) { return; }
        $kSide = $me === 'w' ? $this->castling['wK'] : $this->castling['bK'];
        $qSide = $me === 'w' ? $this->castling['wQ'] : $this->castling['bQ'];
        if ($kSide && !$b[$homeRow][5] && !$b[$homeRow][6] && $b[$homeRow][7] === $me . 'r' &&
            !$this->isAttacked($homeRow, 5, $opp) && !$this->isAttacked($homeRow, 6, $opp)) {
            $moves[] = array('from' => self::sq($homeRow, 4), 'to' => self::sq($homeRow, 6), 'piece' => 'k',
                'color' => $me, 'captured' => null, 'promotion' => null, 'flags' => 'k');
        }
        if ($qSide && !$b[$homeRow][3] && !$b[$homeRow][2] && !$b[$homeRow][1] && $b[$homeRow][0] === $me . 'r' &&
            !$this->isAttacked($homeRow, 3, $opp) && !$this->isAttacked($homeRow, 2, $opp)) {
            $moves[] = array('from' => self::sq($homeRow, 4), 'to' => self::sq($homeRow, 2), 'piece' => 'k',
                'color' => $me, 'captured' => null, 'promotion' => null, 'flags' => 'q');
        }
    }

    /** Wendet einen vollständigen Zug an (mutiert den Zustand). */
    private function applyMove($m) {
        $me = $this->turn;
        $f = self::rc($m['from']); $t = self::rc($m['to']);
        $piece = $this->board[$f['r']][$f['c']];
        $this->ep = null;

        $this->board[$t['r']][$t['c']] = $piece;
        $this->board[$f['r']][$f['c']] = null;

        if (strpos($m['flags'], 'e') !== false) { $this->board[$f['r']][$t['c']] = null; }
        if ($m['promotion']) { $this->board[$t['r']][$t['c']] = $me . $m['promotion']; }
        if (strpos($m['flags'], 'k') !== false) { $this->board[$t['r']][5] = $this->board[$t['r']][7]; $this->board[$t['r']][7] = null; }
        if (strpos($m['flags'], 'q') !== false) { $this->board[$t['r']][3] = $this->board[$t['r']][0]; $this->board[$t['r']][0] = null; }
        if (strpos($m['flags'], 'b') !== false) { $this->ep = self::sq(intval(($f['r'] + $t['r']) / 2), $f['c']); }

        if ($piece === $me . 'k') {
            if ($me === 'w') { $this->castling['wK'] = false; $this->castling['wQ'] = false; }
            else { $this->castling['bK'] = false; $this->castling['bQ'] = false; }
        }
        if ($m['from'] === 'a1' || $m['to'] === 'a1') { $this->castling['wQ'] = false; }
        if ($m['from'] === 'h1' || $m['to'] === 'h1') { $this->castling['wK'] = false; }
        if ($m['from'] === 'a8' || $m['to'] === 'a8') { $this->castling['bQ'] = false; }
        if ($m['from'] === 'h8' || $m['to'] === 'h8') { $this->castling['bK'] = false; }

        if ($piece[1] === 'p' || $m['captured']) { $this->halfmove = 0; } else { $this->halfmove++; }
        if ($me === 'b') { $this->fullmove++; }
        $this->turn = $me === 'w' ? 'b' : 'w';
    }

    /** Legale Züge (mit Schach-Prüfung). $onlySquare optional. */
    public function moves($onlySquare = null) {
        $pseudo = $this->pseudoMoves($onlySquare);
        $me = $this->turn; $legal = array();
        foreach ($pseudo as $m) {
            $c = $this->copy();
            $c->applyMove($m);
            if (!$c->inCheck($me)) { $legal[] = $m; }
        }
        return $legal;
    }

    private function buildSan($move, $legalMoves) {
        if (strpos($move['flags'], 'k') !== false) { return 'O-O'; }
        if (strpos($move['flags'], 'q') !== false) { return 'O-O-O'; }
        $letter = $move['piece'] === 'p' ? '' : strtoupper($move['piece']);
        $san = $letter;
        $isCapture = !empty($move['captured']);

        if ($move['piece'] === 'p') {
            if ($isCapture) { $san .= $move['from'][0] . 'x'; }
        } else {
            $same = array();
            foreach ($legalMoves as $m) {
                if ($m['piece'] === $move['piece'] && $m['to'] === $move['to'] && $m['from'] !== $move['from']) {
                    $same[] = $m;
                }
            }
            if (count($same)) {
                $sameFile = false; $sameRank = false;
                foreach ($same as $m) {
                    if ($m['from'][0] === $move['from'][0]) { $sameFile = true; }
                    if ($m['from'][1] === $move['from'][1]) { $sameRank = true; }
                }
                if (!$sameFile) { $san .= $move['from'][0]; }
                elseif (!$sameRank) { $san .= $move['from'][1]; }
                else { $san .= $move['from']; }
            }
            if ($isCapture) { $san .= 'x'; }
        }
        $san .= $move['to'];
        if ($move['promotion']) { $san .= '=' . strtoupper($move['promotion']); }
        return $san;
    }

    /**
     * Führt einen Zug aus. Gibt das Zug-Array (inkl. 'san') zurück oder null,
     * wenn der Zug illegal ist.
     */
    public function move($from, $to, $promotion = null) {
        $legal = $this->moves();
        $chosen = null;
        foreach ($legal as $m) {
            if ($m['from'] === $from && $m['to'] === $to) {
                if ($m['promotion']) {
                    if ($m['promotion'] === ($promotion ? $promotion : 'q')) { $chosen = $m; break; }
                } else { $chosen = $m; break; }
            }
        }
        if (!$chosen) { return null; }
        $san = $this->buildSan($chosen, $legal);
        $this->applyMove($chosen);
        if ($this->inCheck($this->turn)) {
            $san .= count($this->moves()) === 0 ? '#' : '+';
        }
        $chosen['san'] = $san;
        return $chosen;
    }

    public function isCheckmate() { return $this->inCheck($this->turn) && count($this->moves()) === 0; }
    public function isStalemate() { return !$this->inCheck($this->turn) && count($this->moves()) === 0; }

    public function insufficientMaterial() {
        $pieces = array();
        for ($r = 0; $r < 8; $r++) {
            for ($c = 0; $c < 8; $c++) {
                $p = $this->board[$r][$c];
                if ($p && $p[1] !== 'k') { $pieces[] = array('p' => $p, 'r' => $r, 'c' => $c); }
            }
        }
        $n = count($pieces);
        if ($n === 0) { return true; }
        if ($n === 1 && ($pieces[0]['p'][1] === 'b' || $pieces[0]['p'][1] === 'n')) { return true; }
        if ($n === 2 && $pieces[0]['p'][1] === 'b' && $pieces[1]['p'][1] === 'b') {
            $col0 = ($pieces[0]['r'] + $pieces[0]['c']) % 2;
            $col1 = ($pieces[1]['r'] + $pieces[1]['c']) % 2;
            if ($col0 === $col1 && $pieces[0]['p'][0] !== $pieces[1]['p'][0]) { return true; }
        }
        return false;
    }

    /** Spielstatus zur Auswertung des Spielendes. */
    public function gameStatus() {
        if ($this->isCheckmate()) {
            return array('over' => true, 'type' => 'checkmate', 'winner' => $this->turn === 'w' ? 'b' : 'w');
        }
        if ($this->isStalemate()) { return array('over' => true, 'type' => 'stalemate', 'winner' => null); }
        if ($this->halfmove >= 100) { return array('over' => true, 'type' => 'fiftymove', 'winner' => null); }
        if ($this->insufficientMaterial()) { return array('over' => true, 'type' => 'material', 'winner' => null); }
        return array('over' => false, 'check' => $this->inCheck($this->turn), 'turn' => $this->turn);
    }
}
