/**
 * Chess Duell – Front-End: Lobby, Brett-Rendering, Synchronisation per Polling.
 */
(function () {
  'use strict';

  var Engine = window.ChessDuellEngine;
  var CFG = window.ChessDuellConfig || {};
  var POLL_MS = 2000;

  var GLYPH = { k: '♚', q: '♛', r: '♜', b: '♝', n: '♞', p: '♟' };
  var PROMO_LABEL = { q: 'Dame', r: 'Turm', b: 'Läufer', n: 'Springer' };

  function el(tag, cls, txt) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (txt != null) { e.textContent = txt; }
    return e;
  }

  function api(path, method, body) {
    var opts = { method: method || 'GET', headers: { 'Content-Type': 'application/json' } };
    if (CFG.nonce) { opts.headers['X-WP-Nonce'] = CFG.nonce; }
    if (body) { opts.body = JSON.stringify(body); }
    return fetch(CFG.restUrl + path, opts).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok) { throw new Error((data && data.message) || ('HTTP ' + res.status)); }
        return data;
      });
    });
  }

  function storeKey(id) { return 'chessduell_' + id; }
  function loadIdentity(id) {
    try { return JSON.parse(localStorage.getItem(storeKey(id)) || 'null'); } catch (e) { return null; }
  }
  function saveIdentity(id, data) {
    try { localStorage.setItem(storeKey(id), JSON.stringify(data)); } catch (e) {}
  }
  function loadName() {
    try { return localStorage.getItem('chessduell_name') || ''; } catch (e) { return ''; }
  }
  function saveName(n) {
    try { localStorage.setItem('chessduell_name', n); } catch (e) {}
  }
  // Vorbelegung: selbst gewählter Name (Override) hat Vorrang, sonst der
  // WordPress-Anzeigename angemeldeter Nutzer.
  function defaultName() {
    return loadName() || (CFG.userName || '');
  }

  function ChessDuell(root) {
    this.root = root;
    this.engine = new Engine();
    this.gameId = null;
    this.token = null;
    this.color = null;        // 'white' | 'black' | 'spectator'
    this.appliedMoves = 0;
    this.selected = null;
    this.legalCache = [];
    this.pendingPromo = null; // {from,to}
    this.pollTimer = null;
    this.state = null;
    this.init();
  }

  ChessDuell.prototype.init = function () {
    var params = new URLSearchParams(window.location.search);
    var id = params.get('chess_game') || CFG.gameId || null;
    if (id) { this.enterGame(id); }
    else { this.renderLobby(); }
  };

  // ---------- Lobby ----------
  ChessDuell.prototype.renderLobby = function () {
    var r = this.root;
    r.innerHTML = '';
    var box = el('div', 'cd-lobby');
    box.appendChild(el('h2', null, 'Schach – Online gegeneinander spielen'));
    box.appendChild(el('p', 'cd-muted',
      'Starte eine neue Partie und teile den Link mit deinem Gegner. Wer den Link erstellt, spielt Weiß.'));

    var nameInput = el('input', 'cd-name-input');
    nameInput.type = 'text';
    nameInput.maxLength = 24;
    nameInput.placeholder = 'Dein Name (optional)';
    nameInput.value = defaultName();
    box.appendChild(nameInput);

    var btn = el('button', 'cd-btn cd-btn-primary', 'Neue Partie starten');
    var msg = el('div', 'cd-msg');
    var self = this;
    btn.addEventListener('click', function () {
      btn.disabled = true;
      msg.textContent = 'Erstelle Partie ...';
      var nm = nameInput.value.trim();
      saveName(nm);
      api('game', 'POST', { name: nm }).then(function (data) {
        saveIdentity(data.id, { token: data.token, color: data.color });
        var url = self.gameUrl(data.id);
        history.replaceState(null, '', url);
        self.enterGame(data.id);
      }).catch(function (e) {
        btn.disabled = false;
        msg.textContent = 'Fehler: ' + e.message;
      });
    });
    box.appendChild(btn);
    box.appendChild(msg);
    r.appendChild(box);
  };

  ChessDuell.prototype.gameUrl = function (id) {
    var u = new URL(window.location.href);
    u.searchParams.set('chess_game', id);
    return u.toString();
  };

  // ---------- Partie betreten ----------
  ChessDuell.prototype.enterGame = function (id) {
    var self = this;
    this.gameId = id;
    var ident = loadIdentity(id);
    var body = {};
    if (ident && ident.token) { body.token = ident.token; }
    var myName = defaultName();
    if (myName) { body.name = myName; }
    this.root.innerHTML = '<div class="cd-msg">Verbinde mit Partie ...</div>';
    api('game/' + id + '/join', 'POST', body).then(function (data) {
      self.color = data.color;
      self.token = data.token || (ident && ident.token) || null;
      if (self.token) { saveIdentity(id, { token: self.token, color: self.color }); }
      self.applyState(data.state);
      self.renderGame();
      self.startPolling();
    }).catch(function (e) {
      self.root.innerHTML = '';
      var m = el('div', 'cd-msg', 'Partie konnte nicht geladen werden: ' + e.message);
      self.root.appendChild(m);
      var back = el('button', 'cd-btn', 'Zurück zur Lobby');
      back.addEventListener('click', function () {
        history.replaceState(null, '', window.location.pathname);
        self.renderLobby();
      });
      self.root.appendChild(back);
    });
  };

  // ---------- Zustand vom Server uebernehmen ----------
  ChessDuell.prototype.applyState = function (state) {
    if (!state) { return; }
    this.state = state;
    var moves = state.moves || [];
    if (moves.length < this.appliedMoves) {
      // Sollte nicht vorkommen – Sicherheitsnetz: komplett neu aufbauen.
      this.engine = new Engine();
      this.appliedMoves = 0;
    }
    for (var i = this.appliedMoves; i < moves.length; i++) {
      var m = moves[i];
      this.engine.move({ from: m.from, to: m.to, promotion: m.promotion });
    }
    this.appliedMoves = moves.length;
  };

  // ---------- Spielansicht ----------
  ChessDuell.prototype.renderGame = function () {
    var r = this.root;
    r.innerHTML = '';
    var wrap = el('div', 'cd-game');

    this.statusBar = el('div', 'cd-status');
    wrap.appendChild(this.statusBar);

    this.boardEl = el('div', 'cd-board');
    if (this.color === 'black') { this.boardEl.classList.add('cd-flip'); }
    wrap.appendChild(this.boardEl);

    var side = el('div', 'cd-side');
    this.moveListEl = el('div', 'cd-moves');
    side.appendChild(el('h3', null, 'Zugliste'));
    side.appendChild(this.moveListEl);

    var controls = el('div', 'cd-controls');
    var self = this;

    this.shareBox = el('div', 'cd-share');
    controls.appendChild(this.shareBox);

    if (this.color === 'white' || this.color === 'black') {
      var nameWrap = el('div', 'cd-name-edit');
      nameWrap.appendChild(el('label', 'cd-name-label', 'Dein Name'));
      var nameField = el('input', 'cd-name-input');
      nameField.type = 'text';
      nameField.maxLength = 24;
      nameField.placeholder = this.color === 'white' ? 'Weiß' : 'Schwarz';
      var ownName = this.color === 'white'
        ? (this.state && this.state.white_name)
        : (this.state && this.state.black_name);
      nameField.value = ownName || defaultName();
      nameField.addEventListener('change', function () { self.saveOwnName(this.value); });
      nameWrap.appendChild(nameField);
      controls.appendChild(nameWrap);

      this.resignBtn = el('button', 'cd-btn cd-btn-danger', 'Aufgeben');
      this.resignBtn.addEventListener('click', function () { self.resign(); });
      controls.appendChild(this.resignBtn);
    }
    var newBtn = el('button', 'cd-btn', 'Neue Partie');
    newBtn.addEventListener('click', function () {
      history.replaceState(null, '', window.location.pathname);
      self.stopPolling();
      self.reset();
      self.renderLobby();
    });
    controls.appendChild(newBtn);
    side.appendChild(controls);
    wrap.appendChild(side);

    this.promoEl = el('div', 'cd-promo cd-hidden');
    wrap.appendChild(this.promoEl);

    r.appendChild(wrap);
    this.drawBoard();
    this.updateStatus();
    this.updateMoveList();
    this.updateShare();
  };

  ChessDuell.prototype.reset = function () {
    this.engine = new Engine();
    this.gameId = this.token = this.color = null;
    this.appliedMoves = 0; this.selected = null; this.state = null;
  };

  ChessDuell.prototype.updateShare = function () {
    var box = this.shareBox; box.innerHTML = '';
    if (this.state && this.state.has_black) {
      box.appendChild(el('div', 'cd-muted', 'Beide Spieler sind verbunden.'));
      return;
    }
    if (this.color === 'white') {
      box.appendChild(el('div', 'cd-muted', 'Teile diesen Link mit deinem Gegner:'));
      var url = this.gameUrl(this.gameId);
      var input = el('input', 'cd-link');
      input.type = 'text'; input.readOnly = true; input.value = url;
      input.addEventListener('focus', function () { this.select(); });
      box.appendChild(input);
      var copy = el('button', 'cd-btn', 'Link kopieren');
      copy.addEventListener('click', function () {
        input.select();
        if (navigator.clipboard) { navigator.clipboard.writeText(url); }
        else { document.execCommand('copy'); }
        copy.textContent = 'Kopiert!';
        setTimeout(function () { copy.textContent = 'Link kopieren'; }, 1500);
      });
      box.appendChild(copy);
    } else if (this.color === 'spectator') {
      box.appendChild(el('div', 'cd-muted', 'Du schaust als Zuschauer zu.'));
    }
  };

  // ---------- Brett zeichnen ----------
  ChessDuell.prototype.drawBoard = function () {
    var b = this.boardEl;
    b.innerHTML = '';
    var order = [];
    for (var i = 0; i < 8; i++) { order.push(i); }
    var rows = this.color === 'black' ? order.slice().reverse() : order;
    var cols = this.color === 'black' ? order.slice().reverse() : order;
    var self = this;
    var legalTargets = {};
    if (this.selected) {
      this.legalCache.forEach(function (m) { legalTargets[m.to] = true; });
    }
    var kingInCheck = null;
    var st = this.engine.gameStatus();
    if (st.check) { kingInCheck = this.engine.kingSquare(this.engine.turn); }

    for (var ri = 0; ri < rows.length; ri++) {
      var r = rows[ri];
      for (var ci = 0; ci < cols.length; ci++) {
        var c = cols[ci];
        var dark = (r + c) % 2 === 1;
        var cell = el('div', 'cd-cell ' + (dark ? 'cd-dark' : 'cd-light'));
        var square = String.fromCharCode(97 + c) + (8 - r);
        cell.dataset.square = square;
        var piece = this.engine.board[r][c];
        if (piece) {
          var pe = el('span', 'cd-piece cd-' + (piece[0] === 'w' ? 'white' : 'black'), GLYPH[piece[1]]);
          cell.appendChild(pe);
        }
        if (this.selected === square) { cell.classList.add('cd-selected'); }
        if (legalTargets[square]) { cell.classList.add(piece ? 'cd-capture' : 'cd-target'); }
        if (kingInCheck && kingInCheck.r === r && kingInCheck.c === c) { cell.classList.add('cd-check'); }
        // Koordinaten
        if (ci === 0) { cell.appendChild(el('span', 'cd-coord cd-rank', String(8 - r))); }
        if (ri === rows.length - 1) { cell.appendChild(el('span', 'cd-coord cd-file', String.fromCharCode(97 + c))); }
        cell.addEventListener('click', function () { self.onCellClick(this.dataset.square); });
        b.appendChild(cell);
      }
    }
  };

  ChessDuell.prototype.myTurn = function () {
    if (this.color !== 'white' && this.color !== 'black') { return false; }
    if (this.state && this.state.status === 'finished') { return false; }
    var myColorCode = this.color === 'white' ? 'w' : 'b';
    return this.engine.turn === myColorCode && this.state && this.state.has_black;
  };

  ChessDuell.prototype.onCellClick = function (square) {
    if (this.pendingPromo) { return; }
    if (!this.myTurn()) { return; }
    var piece = this.engine.get(square);
    var myColorCode = this.color === 'white' ? 'w' : 'b';

    if (this.selected) {
      // Zielfeld?
      var target = this.legalCache.filter(function (m) { return m.to === square; });
      if (target.length) {
        var needsPromo = target.some(function (m) { return m.promotion; });
        if (needsPromo) { this.askPromotion(this.selected, square); }
        else { this.doMove(this.selected, square, null); }
        return;
      }
    }
    // Neue Auswahl
    if (piece && piece[0] === myColorCode) {
      this.selected = square;
      this.legalCache = this.engine.moves({ square: square });
    } else {
      this.selected = null;
      this.legalCache = [];
    }
    this.drawBoard();
  };

  ChessDuell.prototype.askPromotion = function (from, to) {
    this.pendingPromo = { from: from, to: to };
    var p = this.promoEl;
    p.innerHTML = '';
    p.classList.remove('cd-hidden');
    p.appendChild(el('div', 'cd-promo-title', 'Umwandeln in:'));
    var self = this;
    ['q', 'r', 'b', 'n'].forEach(function (t) {
      var btn = el('button', 'cd-promo-btn cd-' + (self.color === 'white' ? 'white' : 'black'),
        GLYPH[t]);
      btn.title = PROMO_LABEL[t];
      btn.addEventListener('click', function () {
        p.classList.add('cd-hidden');
        var fp = self.pendingPromo; self.pendingPromo = null;
        self.doMove(fp.from, fp.to, t);
      });
      p.appendChild(btn);
    });
  };

  // ---------- Zug ausfuehren & senden ----------
  ChessDuell.prototype.doMove = function (from, to, promotion) {
    var self = this;
    var move = this.engine.move({ from: from, to: to, promotion: promotion });
    if (!move) { this.selected = null; this.legalCache = []; this.drawBoard(); return; }
    this.appliedMoves++;
    this.selected = null;
    this.legalCache = [];

    var status = this.engine.gameStatus();
    var finished = status.over;
    var result = null;
    if (status.over) {
      if (status.type === 'checkmate') { result = status.winner === 'w' ? '1-0' : '0-1'; }
      else { result = '1/2-1/2'; }
    }
    // Optimistisch anzeigen
    if (this.state) {
      this.state.moves = (this.state.moves || []).concat([{ from: from, to: to, promotion: promotion, san: move.san }]);
      this.state.turn = this.engine.turn;
      if (finished) { this.state.status = 'finished'; this.state.result = result; }
    }
    this.drawBoard();
    this.updateStatus();
    this.updateMoveList();

    // Der Server validiert den Zug selbst und liefert den maßgeblichen Zustand.
    api('game/' + this.gameId + '/move', 'POST', {
      token: this.token, from: from, to: to, promotion: promotion
    }).then(function (data) {
      self.applyState(data);
      self.refreshUI();
    }).catch(function (e) {
      // Konflikt / serverseitig abgelehnt: maßgeblichen Zustand neu laden
      self.fetchState();
      console.warn('Zug abgelehnt:', e.message);
    });
  };

  ChessDuell.prototype.saveOwnName = function (value) {
    var self = this;
    var nm = (value || '').trim();
    saveName(nm);
    if (!this.token) { return; }
    api('game/' + this.gameId + '/join', 'POST', { token: this.token, name: nm }).then(function (data) {
      if (data && data.state) { self.state = data.state; }
      self.updateStatus();
    }).catch(function (e) { console.warn(e.message); });
  };

  ChessDuell.prototype.resign = function () {
    if (!confirm('Partie wirklich aufgeben?')) { return; }
    var self = this;
    var result = this.color === 'white' ? '0-1' : '1-0';
    api('game/' + this.gameId + '/resign', 'POST', { token: this.token }).then(function (data) {
      self.applyState(data);
      self.refreshUI();
    }).catch(function (e) { console.warn(e.message); });
  };

  // ---------- Statusleiste & Zugliste ----------
  ChessDuell.prototype.updateStatus = function () {
    var s = this.statusBar; if (!s) { return; }
    var st = this.engine.gameStatus();
    var wName = (this.state && this.state.white_name) ? this.state.white_name : 'Weiß';
    var bName = (this.state && this.state.black_name) ? this.state.black_name
              : ((this.state && this.state.has_black) ? 'Schwarz' : '—');

    var youAre = this.color === 'white' ? 'Du spielst Weiß' :
                 this.color === 'black' ? 'Du spielst Schwarz' : 'Zuschauer';
    var line2 = '';
    var cls = 'cd-status';

    if (this.state && this.state.status === 'finished') {
      var res = this.state.result;
      if (res === '1-0') { line2 = 'Sieg – ' + wName + ' gewinnt'; }
      else if (res === '0-1') { line2 = 'Sieg – ' + bName + ' gewinnt'; }
      else { line2 = 'Remis'; }
      cls += ' cd-status-over';
    } else if (st.over) {
      if (st.type === 'checkmate') { line2 = 'Schachmatt – ' + (st.winner === 'w' ? wName : bName) + ' gewinnt'; }
      else if (st.type === 'stalemate') { line2 = 'Patt – Remis'; }
      else if (st.type === 'fiftymove') { line2 = '50-Züge-Regel – Remis'; }
      else { line2 = 'Remis (unzureichendes Material)'; }
      cls += ' cd-status-over';
    } else if (!this.state || !this.state.has_black) {
      line2 = 'Warte auf den Gegner ...';
    } else {
      var turnName = this.engine.turn === 'w' ? wName : bName;
      line2 = 'Am Zug: ' + turnName + (this.myTurn() ? ' (du)' : '');
      if (st.check) { line2 += ' – Schach!'; }
    }
    s.className = cls;
    s.innerHTML = '';
    s.appendChild(el('span', 'cd-you', youAre));
    s.appendChild(el('span', 'cd-players', '♔ ' + wName + '  vs  ♚ ' + bName));
    s.appendChild(el('span', 'cd-turn', line2));
  };

  ChessDuell.prototype.updateMoveList = function () {
    var ml = this.moveListEl; if (!ml) { return; }
    ml.innerHTML = '';
    var moves = (this.state && this.state.moves) || [];
    for (var i = 0; i < moves.length; i += 2) {
      var row = el('div', 'cd-move-row');
      row.appendChild(el('span', 'cd-move-no', (i / 2 + 1) + '.'));
      row.appendChild(el('span', 'cd-move-san', moves[i].san || (moves[i].from + moves[i].to)));
      if (moves[i + 1]) {
        row.appendChild(el('span', 'cd-move-san', moves[i + 1].san || (moves[i + 1].from + moves[i + 1].to)));
      }
      ml.appendChild(row);
    }
    ml.scrollTop = ml.scrollHeight;
  };

  ChessDuell.prototype.refreshUI = function () {
    this.drawBoard();
    this.updateStatus();
    this.updateMoveList();
    this.updateShare();
  };

  // ---------- Polling ----------
  ChessDuell.prototype.startPolling = function () {
    var self = this;
    this.stopPolling();
    this.pollTimer = setInterval(function () { self.fetchState(); }, POLL_MS);
  };
  ChessDuell.prototype.stopPolling = function () {
    if (this.pollTimer) { clearInterval(this.pollTimer); this.pollTimer = null; }
  };
  ChessDuell.prototype.fetchState = function () {
    var self = this;
    if (document.hidden) { return; }
    api('game/' + this.gameId).then(function (data) {
      var before = self.appliedMoves;
      var beforeBlack = self.state && self.state.has_black;
      self.applyState(data);
      if (self.appliedMoves !== before || (data.has_black && !beforeBlack) ||
          (data.status === 'finished')) {
        self.refreshUI();
      } else {
        // nur Verbindungsstatus evtl. geaendert
        self.updateShare();
        self.updateStatus();
      }
    }).catch(function () { /* still */ });
  };

  // ---------- Init ----------
  document.addEventListener('DOMContentLoaded', function () {
    var roots = document.querySelectorAll('.chess-duell-root');
    for (var i = 0; i < roots.length; i++) { new ChessDuell(roots[i]); }
  });
})();
