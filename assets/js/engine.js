/**
 * Chess Duell – Schach-Regel-Engine (eigenständig, keine Abhängigkeiten).
 *
 * Implementiert die kompletten Schachregeln:
 *  - alle Figurenzüge, Schlagen
 *  - Rochade (kurz/lang) inkl. Felder-unter-Beschuss-Prüfung
 *  - En passant
 *  - Bauernumwandlung
 *  - Schach, Schachmatt, Patt
 *  - 50-Züge-Regel und unzureichendes Material (Remis)
 *
 * Brettdarstellung: board[r][c]
 *   r = 0 entspricht Reihe 8 (oben), r = 7 entspricht Reihe 1 (unten)
 *   c = 0 entspricht Linie a, c = 7 entspricht Linie h
 *   Felder als String "wp" (weisser Bauer), "bk" (schwarzer Koenig) ... oder null.
 */
(function (global) {
  'use strict';

  var START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

  function sq(r, c) { return String.fromCharCode(97 + c) + (8 - r); }
  function rc(square) {
    return { r: 8 - parseInt(square[1], 10), c: square.charCodeAt(0) - 97 };
  }
  function inBounds(r, c) { return r >= 0 && r < 8 && c >= 0 && c < 8; }

  var KNIGHT_OFFS = [[-2, -1], [-2, 1], [-1, -2], [-1, 2], [1, -2], [1, 2], [2, -1], [2, 1]];
  var DIAG = [[-1, -1], [-1, 1], [1, -1], [1, 1]];
  var ORTH = [[-1, 0], [1, 0], [0, -1], [0, 1]];

  function Chess(fen) { this.load(fen || START_FEN); }

  Chess.prototype.load = function (fen) {
    var parts = fen.trim().split(/\s+/);
    var rows = parts[0].split('/');
    this.board = [];
    for (var r = 0; r < 8; r++) {
      var row = [null, null, null, null, null, null, null, null];
      var c = 0;
      var rowStr = rows[r] || '8';
      for (var i = 0; i < rowStr.length; i++) {
        var ch = rowStr[i];
        if (/\d/.test(ch)) { c += parseInt(ch, 10); }
        else {
          var color = ch === ch.toUpperCase() ? 'w' : 'b';
          row[c] = color + ch.toLowerCase();
          c++;
        }
      }
      this.board.push(row);
    }
    this.turn = parts[1] || 'w';
    var cr = parts[2] || '-';
    this.castling = {
      wK: cr.indexOf('K') !== -1, wQ: cr.indexOf('Q') !== -1,
      bK: cr.indexOf('k') !== -1, bQ: cr.indexOf('q') !== -1
    };
    this.ep = (parts[3] && parts[3] !== '-') ? parts[3] : null;
    this.halfmove = parts[4] ? parseInt(parts[4], 10) : 0;
    this.fullmove = parts[5] ? parseInt(parts[5], 10) : 1;
  };

  Chess.prototype.fen = function () {
    var rows = [];
    for (var r = 0; r < 8; r++) {
      var s = ''; var empty = 0;
      for (var c = 0; c < 8; c++) {
        var p = this.board[r][c];
        if (!p) { empty++; }
        else {
          if (empty) { s += empty; empty = 0; }
          var ch = p[1];
          s += p[0] === 'w' ? ch.toUpperCase() : ch;
        }
      }
      if (empty) { s += empty; }
      rows.push(s);
    }
    var cr = (this.castling.wK ? 'K' : '') + (this.castling.wQ ? 'Q' : '') +
             (this.castling.bK ? 'k' : '') + (this.castling.bQ ? 'q' : '');
    if (!cr) { cr = '-'; }
    return rows.join('/') + ' ' + this.turn + ' ' + cr + ' ' +
           (this.ep || '-') + ' ' + this.halfmove + ' ' + this.fullmove;
  };

  Chess.prototype.get = function (square) { var p = rc(square); return this.board[p.r][p.c]; };
  Chess.prototype.clone = function () { return new Chess(this.fen()); };

  // Wird das Feld (r,c) von Farbe `by` angegriffen?
  Chess.prototype.isAttacked = function (r, c, by) {
    var b = this.board, dc, pr, pc, i, dr, nr, nc, dir, step, t;
    // Bauern
    if (by === 'w') {
      for (i = 0; i < 2; i++) { dc = i === 0 ? -1 : 1; pr = r + 1; pc = c + dc;
        if (inBounds(pr, pc) && b[pr][pc] === 'wp') { return true; } }
    } else {
      for (i = 0; i < 2; i++) { dc = i === 0 ? -1 : 1; pr = r - 1; pc = c + dc;
        if (inBounds(pr, pc) && b[pr][pc] === 'bp') { return true; } }
    }
    // Springer
    for (i = 0; i < KNIGHT_OFFS.length; i++) {
      nr = r + KNIGHT_OFFS[i][0]; nc = c + KNIGHT_OFFS[i][1];
      if (inBounds(nr, nc) && b[nr][nc] === by + 'n') { return true; }
    }
    // Koenig benachbart
    for (dr = -1; dr <= 1; dr++) {
      for (dc = -1; dc <= 1; dc++) {
        if (dr === 0 && dc === 0) { continue; }
        nr = r + dr; nc = c + dc;
        if (inBounds(nr, nc) && b[nr][nc] === by + 'k') { return true; }
      }
    }
    // Laeufer / Dame (diagonal)
    for (i = 0; i < DIAG.length; i++) {
      dir = DIAG[i];
      for (step = 1; ; step++) {
        nr = r + dir[0] * step; nc = c + dir[1] * step;
        if (!inBounds(nr, nc)) { break; }
        t = b[nr][nc];
        if (t) { if (t === by + 'b' || t === by + 'q') { return true; } break; }
      }
    }
    // Turm / Dame (gerade)
    for (i = 0; i < ORTH.length; i++) {
      dir = ORTH[i];
      for (step = 1; ; step++) {
        nr = r + dir[0] * step; nc = c + dir[1] * step;
        if (!inBounds(nr, nc)) { break; }
        t = b[nr][nc];
        if (t) { if (t === by + 'r' || t === by + 'q') { return true; } break; }
      }
    }
    return false;
  };

  Chess.prototype.kingSquare = function (color) {
    for (var r = 0; r < 8; r++) {
      for (var c = 0; c < 8; c++) {
        if (this.board[r][c] === color + 'k') { return { r: r, c: c }; }
      }
    }
    return null;
  };

  Chess.prototype.inCheck = function (color) {
    color = color || this.turn;
    var k = this.kingSquare(color);
    if (!k) { return false; }
    return this.isAttacked(k.r, k.c, color === 'w' ? 'b' : 'w');
  };

  // Pseudo-legale Zuege (ohne Schach-Pruefung) fuer die Seite am Zug.
  Chess.prototype._pseudoMoves = function (onlySquare) {
    var me = this.turn, opp = me === 'w' ? 'b' : 'w', b = this.board;
    var moves = [];
    var only = onlySquare ? rc(onlySquare) : null;

    function pushPawnMoves(fr, fc, tr, tc, flags, captured, promoRow) {
      if (tr === promoRow) {
        ['q', 'r', 'b', 'n'].forEach(function (pr) {
          moves.push({ from: sq(fr, fc), to: sq(tr, tc), piece: 'p', color: me,
            captured: captured, promotion: pr, flags: flags + 'p' });
        });
      } else {
        moves.push({ from: sq(fr, fc), to: sq(tr, tc), piece: 'p', color: me,
          captured: captured, promotion: null, flags: flags });
      }
    }

    for (var r = 0; r < 8; r++) {
      for (var c = 0; c < 8; c++) {
        if (only && (only.r !== r || only.c !== c)) { continue; }
        var p = b[r][c];
        if (!p || p[0] !== me) { continue; }
        var type = p[1];

        if (type === 'p') {
          var dir = me === 'w' ? -1 : 1;
          var startRow = me === 'w' ? 6 : 1;
          var promoRow = me === 'w' ? 0 : 7;
          var nr = r + dir;
          if (inBounds(nr, c) && !b[nr][c]) {
            pushPawnMoves(r, c, nr, c, 'n', null, promoRow);
            if (r === startRow && !b[r + 2 * dir][c]) {
              moves.push({ from: sq(r, c), to: sq(r + 2 * dir, c), piece: 'p',
                color: me, captured: null, promotion: null, flags: 'b' });
            }
          }
          for (var di = 0; di < 2; di++) {
            var cc = c + (di === 0 ? -1 : 1);
            if (!inBounds(nr, cc)) { continue; }
            var t = b[nr][cc];
            if (t && t[0] === opp) {
              pushPawnMoves(r, c, nr, cc, 'c', t, promoRow);
            } else if (!t && this.ep && sq(nr, cc) === this.ep) {
              moves.push({ from: sq(r, c), to: sq(nr, cc), piece: 'p', color: me,
                captured: opp + 'p', promotion: null, flags: 'e' });
            }
          }
        } else if (type === 'n') {
          for (var ki = 0; ki < KNIGHT_OFFS.length; ki++) {
            var kr = r + KNIGHT_OFFS[ki][0], kc = c + KNIGHT_OFFS[ki][1];
            if (!inBounds(kr, kc)) { continue; }
            var kt = b[kr][kc];
            if (!kt) { moves.push(mk(r, c, kr, kc, 'n', null)); }
            else if (kt[0] === opp) { moves.push(mk(r, c, kr, kc, 'n', kt)); }
          }
        } else if (type === 'k') {
          for (var dr = -1; dr <= 1; dr++) {
            for (var dc = -1; dc <= 1; dc++) {
              if (dr === 0 && dc === 0) { continue; }
              var xr = r + dr, xc = c + dc;
              if (!inBounds(xr, xc)) { continue; }
              var xt = b[xr][xc];
              if (!xt) { moves.push(mk(r, c, xr, xc, 'k', null)); }
              else if (xt[0] === opp) { moves.push(mk(r, c, xr, xc, 'k', xt)); }
            }
          }
          this._castlingMoves(me, r, c, moves);
        } else {
          var dirs = type === 'b' ? DIAG : type === 'r' ? ORTH : DIAG.concat(ORTH);
          for (var si = 0; si < dirs.length; si++) {
            for (var st = 1; ; st++) {
              var sr = r + dirs[si][0] * st, scc = c + dirs[si][1] * st;
              if (!inBounds(sr, scc)) { break; }
              var stt = b[sr][scc];
              if (!stt) { moves.push(mk(r, c, sr, scc, type, null)); }
              else { if (stt[0] === opp) { moves.push(mk(r, c, sr, scc, type, stt)); } break; }
            }
          }
        }
      }
    }
    function mk(fr, fc, tr, tc, piece, captured) {
      return { from: sq(fr, fc), to: sq(tr, tc), piece: piece, color: me,
        captured: captured, promotion: null, flags: captured ? 'c' : 'n' };
    }
    return moves;
  };

  Chess.prototype._castlingMoves = function (me, r, c, moves) {
    var opp = me === 'w' ? 'b' : 'w', b = this.board;
    var homeRow = me === 'w' ? 7 : 0;
    if (r !== homeRow || c !== 4) { return; }
    if (this.isAttacked(homeRow, 4, opp)) { return; }
    var kSide = me === 'w' ? this.castling.wK : this.castling.bK;
    var qSide = me === 'w' ? this.castling.wQ : this.castling.bQ;
    if (kSide && !b[homeRow][5] && !b[homeRow][6] && b[homeRow][7] === me + 'r' &&
        !this.isAttacked(homeRow, 5, opp) && !this.isAttacked(homeRow, 6, opp)) {
      moves.push({ from: sq(homeRow, 4), to: sq(homeRow, 6), piece: 'k', color: me,
        captured: null, promotion: null, flags: 'k' });
    }
    if (qSide && !b[homeRow][3] && !b[homeRow][2] && !b[homeRow][1] && b[homeRow][0] === me + 'r' &&
        !this.isAttacked(homeRow, 3, opp) && !this.isAttacked(homeRow, 2, opp)) {
      moves.push({ from: sq(homeRow, 4), to: sq(homeRow, 2), piece: 'k', color: me,
        captured: null, promotion: null, flags: 'q' });
    }
  };

  // Wendet einen vollstaendigen Zug an (mutiert den Zustand).
  Chess.prototype._apply = function (m) {
    var me = this.turn;
    var f = rc(m.from), t = rc(m.to);
    var piece = this.board[f.r][f.c];
    this.ep = null;

    this.board[t.r][t.c] = piece;
    this.board[f.r][f.c] = null;

    if (m.flags.indexOf('e') !== -1) { this.board[f.r][t.c] = null; }
    if (m.promotion) { this.board[t.r][t.c] = me + m.promotion; }
    if (m.flags.indexOf('k') !== -1) { this.board[t.r][5] = this.board[t.r][7]; this.board[t.r][7] = null; }
    if (m.flags.indexOf('q') !== -1) { this.board[t.r][3] = this.board[t.r][0]; this.board[t.r][0] = null; }
    if (m.flags.indexOf('b') !== -1) { this.ep = sq((f.r + t.r) / 2, f.c); }

    if (piece === me + 'k') {
      if (me === 'w') { this.castling.wK = false; this.castling.wQ = false; }
      else { this.castling.bK = false; this.castling.bQ = false; }
    }
    if (m.from === 'a1' || m.to === 'a1') { this.castling.wQ = false; }
    if (m.from === 'h1' || m.to === 'h1') { this.castling.wK = false; }
    if (m.from === 'a8' || m.to === 'a8') { this.castling.bQ = false; }
    if (m.from === 'h8' || m.to === 'h8') { this.castling.bK = false; }

    if (piece[1] === 'p' || m.captured) { this.halfmove = 0; } else { this.halfmove++; }
    if (me === 'b') { this.fullmove++; }
    this.turn = me === 'w' ? 'b' : 'w';
  };

  // Legale Zuege (mit Schach-Pruefung). onlySquare optional => nur fuer ein Feld.
  Chess.prototype.moves = function (opts) {
    opts = opts || {};
    var pseudo = this._pseudoMoves(opts.square);
    var me = this.turn, legal = [];
    for (var i = 0; i < pseudo.length; i++) {
      var c = this.clone();
      c._apply(pseudo[i]);
      if (!c.inCheck(me)) { legal.push(pseudo[i]); }
    }
    return legal;
  };

  Chess.prototype._buildSan = function (move, legalMoves) {
    if (move.flags.indexOf('k') !== -1) { return 'O-O'; }
    if (move.flags.indexOf('q') !== -1) { return 'O-O-O'; }
    var letter = move.piece === 'p' ? '' : move.piece.toUpperCase();
    var san = letter;
    var isCapture = !!move.captured;

    if (move.piece === 'p') {
      if (isCapture) { san += move.from[0] + 'x'; }
    } else {
      // Mehrdeutigkeit aufloesen
      var same = legalMoves.filter(function (m) {
        return m.piece === move.piece && m.to === move.to && m.from !== move.from;
      });
      if (same.length) {
        var sameFile = same.some(function (m) { return m.from[0] === move.from[0]; });
        var sameRank = same.some(function (m) { return m.from[1] === move.from[1]; });
        if (!sameFile) { san += move.from[0]; }
        else if (!sameRank) { san += move.from[1]; }
        else { san += move.from; }
      }
      if (isCapture) { san += 'x'; }
    }
    san += move.to;
    if (move.promotion) { san += '=' + move.promotion.toUpperCase(); }
    return san;
  };

  // Fuehrt einen Zug aus. Erwartet {from,to,promotion}. Gibt das Zug-Objekt
  // (inkl. SAN) zurueck oder null, wenn der Zug illegal ist.
  Chess.prototype.move = function (req) {
    var legal = this.moves();
    var chosen = null;
    for (var i = 0; i < legal.length; i++) {
      var m = legal[i];
      if (m.from === req.from && m.to === req.to) {
        if (m.promotion) {
          if (m.promotion === (req.promotion || 'q')) { chosen = m; break; }
        } else { chosen = m; break; }
      }
    }
    if (!chosen) { return null; }
    var san = this._buildSan(chosen, legal);
    this._apply(chosen);
    // Schach / Matt anhaengen
    if (this.inCheck(this.turn)) {
      san += this.moves().length === 0 ? '#' : '+';
    }
    chosen.san = san;
    return chosen;
  };

  Chess.prototype.isCheckmate = function () { return this.inCheck(this.turn) && this.moves().length === 0; };
  Chess.prototype.isStalemate = function () { return !this.inCheck(this.turn) && this.moves().length === 0; };

  Chess.prototype.insufficientMaterial = function () {
    var pieces = [];
    for (var r = 0; r < 8; r++) {
      for (var c = 0; c < 8; c++) {
        var p = this.board[r][c];
        if (p && p[1] !== 'k') { pieces.push({ p: p, r: r, c: c }); }
      }
    }
    if (pieces.length === 0) { return true; } // K vs K
    if (pieces.length === 1 && (pieces[0].p[1] === 'b' || pieces[0].p[1] === 'n')) { return true; } // K+L/S vs K
    if (pieces.length === 2 && pieces[0].p[1] === 'b' && pieces[1].p[1] === 'b') {
      var col0 = (pieces[0].r + pieces[0].c) % 2;
      var col1 = (pieces[1].r + pieces[1].c) % 2;
      if (col0 === col1 && pieces[0].p[0] !== pieces[1].p[0]) { return true; } // gleichfarbige Laeufer
    }
    return false;
  };

  Chess.prototype.isDraw = function () {
    return this.isStalemate() || this.halfmove >= 100 || this.insufficientMaterial();
  };
  Chess.prototype.isGameOver = function () { return this.isCheckmate() || this.isDraw(); };

  // Status fuer die Anzeige.
  Chess.prototype.gameStatus = function () {
    if (this.isCheckmate()) {
      return { over: true, type: 'checkmate', winner: this.turn === 'w' ? 'b' : 'w' };
    }
    if (this.isStalemate()) { return { over: true, type: 'stalemate', winner: null }; }
    if (this.halfmove >= 100) { return { over: true, type: 'fiftymove', winner: null }; }
    if (this.insufficientMaterial()) { return { over: true, type: 'material', winner: null }; }
    return { over: false, check: this.inCheck(this.turn), turn: this.turn };
  };

  Chess.START_FEN = START_FEN;
  global.ChessDuellEngine = Chess;
})(window);
