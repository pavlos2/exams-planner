/* Exams Planner — σελίδα δηλώσεων */
(function () {
  'use strict';

  var cfg = window.DGN_CONFIG;
  var root = document.getElementById('dgn-app');
  if (!cfg || !root) return;

  /* Υποσέλιδο «Δημιουργία: … · vX»: στην κάτω δεξιά γωνία του <article> της σελίδας, χωρίς να προσθέτει κενό. */
  (function () {
    var credit = root.parentNode && root.parentNode.querySelector('.dgn-credit');
    var host = root.closest ? root.closest('article') : null;
    if (!credit || !host) return;
    host.appendChild(credit);
    if (window.getComputedStyle(host).position === 'static') host.classList.add('dgn-credit-host');
    credit.classList.add('dgn-credit-corner');
  })();

  /* Ανθεκτικότητα σε cache: αν η σελίδα σερβιριστεί από παλιότερη αποθηκευμένη έκδοση,
     κάποιες ρυθμίσεις μπορεί να λείπουν. Τις συμπληρώνουμε εδώ, ώστε η σελίδα να μη σταματά. */
  (function fillDefaults() {
    function p2(n) { return (n < 10 ? '0' : '') + n; }
    if (!cfg.today) { var t = new Date(); cfg.today = t.getFullYear() + '-' + p2(t.getMonth() + 1) + '-' + p2(t.getDate()); }
    if (!cfg.syStart || !cfg.syEnd) {
      var y = +cfg.today.slice(0, 4), m = +cfg.today.slice(5, 7), start = m >= 7 ? y : y - 1;
      cfg.syStart = start + '-09-01'; cfg.syEnd = (start + 1) + '-06-30';
    }
    cfg.classes = cfg.classes || [];
    cfg.subjects = cfg.subjects || {};
    cfg.holidays = cfg.holidays || {};
    cfg.weeklyMax = cfg.weeklyMax || 3;
    cfg.access = cfg.access || 'code';
  })();

  var MONTHS = ['Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος', 'Ιούλιος', 'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος'];
  var DAYS = ['ΔΕΥ', 'ΤΡΙ', 'ΤΕΤ', 'ΠΕΜ', 'ΠΑΡ'];
  var DAYS_LONG = ['Δευτέρα', 'Τρίτη', 'Τετάρτη', 'Πέμπτη', 'Παρασκευή'];

  /* ---------- αποθήκευση στον browser (προαιρετική) ---------- */
  function lsGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { if (v === null) window.localStorage.removeItem(k); else window.localStorage.setItem(k, v); } catch (e) { /* χωρίς αποθήκευση */ } }
  function ssGet(k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } }
  function ssSet(k, v) { try { if (v === null) window.sessionStorage.removeItem(k); else window.sessionStorage.setItem(k, v); } catch (e) { /* χωρίς αποθήκευση */ } }
  function tokenGet() { return lsGet('dgn_token') || ssGet('dgn_token'); }
  function tokenSet(tok, remember) { lsSet('dgn_token', null); ssSet('dgn_token', null); if (tok) { if (remember) lsSet('dgn_token', tok); else ssSet('dgn_token', tok); } }
  function loadKeys() { try { return JSON.parse(lsGet('dgn_keys') || '{}') || {}; } catch (e) { return {}; } }

  /* ---------- ημερομηνίες (τοπικές, χωρίς ζώνες ώρας) ---------- */
  function parse(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function addDays(d, n) { var x = new Date(d.getFullYear(), d.getMonth(), d.getDate()); x.setDate(x.getDate() + n); return x; }
  function monday(d) { return addDays(d, -((d.getDay() + 6) % 7)); }
  function short(s) { var d = parse(s); return d.getDate() + '/' + (d.getMonth() + 1); }
  function longDate(s) { var d = parse(s); return DAYS_LONG[(d.getDay() + 6) % 7] + ' ' + d.getDate() + '/' + (d.getMonth() + 1) + '/' + d.getFullYear(); }

  /* ---------- κατάσταση ---------- */
  var state = {
    level: cfg.level,
    token: tokenGet(),
    keys: loadKeys(),
    exams: {},        // "τμήμα|ημερομηνία" -> δήλωση
    loaded: false,
    loadError: '',
    view: (function () {
      var t = cfg.today < cfg.syStart ? cfg.syStart : (cfg.today > cfg.syEnd ? cfg.syEnd : cfg.today);
      var d = parse(t); return { y: d.getFullYear(), m: d.getMonth() };
    })(),
    selected: null,   // "τμήμα|ημερομηνία"
    confirmDel: false,
    busy: false,
    teachers: [],     // λίστα καθηγητών (μόνο για όσους έχουν μπει)
    me: '',           // επώνυμο από τον λογαριασμό ΠΣΔ
    filter: '',       // επιλεγμένο τμήμα ('' = όλα)
    upcoming: null,   // επόμενα διαγωνίσματα του επιλεγμένου τμήματος
    calOpen: false    // επισκέπτες με επιλεγμένο τμήμα: ανοιχτό το ημερολόγιο;
  };
  // Κάθε επίσκεψη ανοίγει με «Όλα τα τμήματα». Μόνο ένας σύνδεσμος ?tmima=Β2 ανοίγει απευθείας σε τμήμα.
  (function initFilter() {
    var v = new URLSearchParams(window.location.search).get('tmima') || '';
    state.filter = cfg.classes.indexOf(v) !== -1 ? v : '';
    lsSet('dgn_filter', null); // καθαρισμός από παλαιότερες εκδόσεις που θυμούνταν το τμήμα
  })();
  var SSO = cfg.access === 'sso';
  var ssoError = '';
  if (SSO) {
    // Επιστροφή από το ΠΣΔ: το διακριτικό έρχεται στο #fragment (δεν φτάνει ποτέ σε server) και σβήνεται αμέσως.
    var h = window.location.hash || '';
    var mt = h.match(/^#dgn-sso=([A-Za-z0-9._-]+)$/), me = h.match(/^#dgn-sso-error=([a-z]+)$/);
    if (mt) { tokenSet(mt[1], false); state.token = mt[1]; }
    if (me) ssoError = me[1];
    if (mt || me) { try { window.history.replaceState(null, '', window.location.pathname + window.location.search); } catch (e) { window.location.hash = ''; } }
  } else if (cfg.access !== 'code') {
    state.token = null;
  }
  // Αποθηκευμένη είσοδος: δείξε αμέσως τη φόρμα· ο server την επιβεβαιώνει στην πρώτη φόρτωση.
  if (state.token && state.level === 'public') state.level = 'teacher';

  function pageUrl(view) {
    var u = new URL(window.location.href);
    u.searchParams.delete('dgn');
    if (view) u.searchParams.set('dgn', view);
    u.hash = '';
    return u.toString();
  }
  var loginView = new URLSearchParams(window.location.search).get('dgn') === 'login';

  function canWrite() { return state.level === 'teacher' || state.level === 'admin'; }
  function isAdmin() { return state.level === 'admin'; }

  /* ---------- REST ---------- */
  function url(path, params) {
    var u = cfg.rest + path;
    if (params) {
      var q = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
      u += (u.indexOf('?') === -1 ? '?' : '&') + q;
    }
    return u;
  }
  function api(method, path, opts) {
    opts = opts || {};
    var headers = { 'Accept': 'application/json' };
    if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
    if (state.token) headers['X-DGN-Token'] = state.token;
    if (opts.body) headers['Content-Type'] = 'application/json';
    if (opts.headers) Object.keys(opts.headers).forEach(function (k) { headers[k] = opts.headers[k]; });
    return fetch(url(path, opts.params), {
      method: method,
      headers: headers,
      credentials: 'same-origin',
      cache: 'no-store',
      body: opts.body ? JSON.stringify(opts.body) : undefined
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) {
          var err = new Error((data && data.message) || 'Σφάλμα σύνδεσης με την ιστοσελίδα (' + res.status + ').');
          err.code = data && data.code;
          err.status = res.status;
          throw err;
        }
        return data;
      });
    });
  }

  function monthRange() {
    var first = new Date(state.view.y, state.view.m, 1);
    var last = new Date(state.view.y, state.view.m + 1, 0);
    var start = monday(first);
    var end = addDays(monday(last), 4);
    return { first: first, last: last, start: start, end: end };
  }

  function load() {
    var r = monthRange();
    var want = state.view.y + '-' + state.view.m;
    return api('GET', 'exams', { params: { from: ymd(r.start), to: ymd(r.end) } }).then(function (data) {
      if (want !== state.view.y + '-' + state.view.m) return; // άλλαξε μήνας στο μεταξύ
      var prevLevel = state.level;
      state.level = data.level;
      if (cfg.access === 'code' && state.token && data.level === 'public') {
        // ο κωδικός άλλαξε από τη διεύθυνση
        state.token = null; tokenSet(null);
        setMsg('Ο κωδικός καθηγητών έχει αλλάξει. Πληκτρολογήστε τον νέο.', 'err');
      }
      if (SSO && state.token && data.level === 'public') {
        // έληξε η είσοδος (12 ώρες) ή άλλαξαν οι ρυθμίσεις
        state.token = null; tokenSet(null);
        setMsg('Η είσοδός σας έληξε. Πατήστε «Είσοδος καθηγητών» για να συνδεθείτε ξανά.', 'err');
      }
      if (data.me !== undefined && data.me !== state.me) {
        state.me = data.me;
        if (SSO && canWrite()) setTeacherField();
      }
      state.exams = {};
      (data.exams || []).forEach(function (e) { state.exams[e.cls + '|' + e.date] = e; });
      var newTeachers = data.teachers || [];
      if (JSON.stringify(newTeachers) !== JSON.stringify(state.teachers)) {
        state.teachers = newTeachers;
        if (prevLevel === state.level && canWrite()) setTeacherField();
      }
      state.loaded = true; state.loadError = '';
      if (prevLevel !== state.level) renderTop();
      render();
    }).catch(function (err) {
      state.loaded = true;
      if (err.code === 'dgn_code_required' || err.code === 'dgn_login_required' || err.code === 'dgn_sso_required') {
        state.level = 'public';
        state.loadError = 'Το πρόγραμμα διαγωνισμάτων είναι ορατό μόνο στους καθηγητές. Πατήστε «Είσοδος καθηγητών».';
      } else {
        state.loadError = err.message;
      }
      renderTop();
      render();
    });
  }

  /* ---------- βοηθητικά DOM ---------- */
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'class') n.className = attrs[k];
      else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) n.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }

  /* ---------- μέγεθος τίτλου: κινητά (έως 600px) 20px· tablet (έως 782px) το πολύ 28px· υπολογιστής όπως το θέμα ---------- */
  function capTitle(n) {
    if (!window.matchMedia) return;
    var phone = window.matchMedia('(max-width: 600px)');
    var tablet = window.matchMedia('(max-width: 782px)');
    function apply() {
      n.style.removeProperty('font-size');
      if (phone.matches) {
        n.style.setProperty('font-size', '20px', 'important');
      } else if (tablet.matches && parseFloat(window.getComputedStyle(n).fontSize) > 28) {
        n.style.setProperty('font-size', '28px', 'important');
      }
    }
    apply();
    [phone, tablet].forEach(function (mq) {
      if (mq.addEventListener) mq.addEventListener('change', apply); else if (mq.addListener) mq.addListener(apply);
    });
  }

  /* ---------- τίτλος σελίδας στο κέντρο (οποιοδήποτε θέμα) ---------- */
  (function centerTitle() {
    var want = (cfg.pageTitle || '').replace(/\s+/g, ' ').trim();
    if (!want) return;
    var nodes = document.querySelectorAll('h1, .entry-title, .page-title, .wp-block-post-title, .post-title');
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (root.contains(n)) continue;
      if ((n.textContent || '').replace(/\s+/g, ' ').trim() !== want) continue;
      n.style.setProperty('text-align', 'center', 'important');
      n.style.setProperty('margin-left', 'auto', 'important');
      n.style.setProperty('margin-right', 'auto', 'important');
      n.classList.add('dgn-centered-title');
      capTitle(n);
      // Σε θέματα όπου ο τίτλος είναι μέσα σε flex/grid κεφαλίδα, κεντράρουμε και τον γονέα.
      var parent = n.parentElement;
      if (parent && parent !== document.body) {
        var d = window.getComputedStyle(parent).display;
        if (d.indexOf('flex') !== -1) { parent.style.justifyContent = 'center'; n.style.width = '100%'; }
        if (d.indexOf('grid') !== -1) { parent.style.justifyItems = 'center'; }
      }
    }
  })();

  /* ---------- σκελετός ---------- */
  root.textContent = '';
  if (loginView) { loginPage(); return; }
  // Κανόνες: ετικέτα αριστερά και οι κανόνες ο ένας κάτω από τον άλλο, στοιχισμένοι (το σύνολο στο κέντρο).
  var notice = +cfg.minNotice || 0; // ελάχιστη προθεσμία δήλωσης σε εργάσιμες (0 = χωρίς όριο, χωρίς γραμμή)
  var ruleRows = [
    el('span', { class: 'dgn-rule-label', text: notice > 0 ? 'Κανόνες:' : 'Κανόνας:' }),
    el('span', null, [el('strong', { text: '1 διαγώνισμα την ημέρα' }), ' και ',
      el('strong', { text: 'έως ' + cfg.weeklyMax + ' την εβδομάδα' }), ' ανά τμήμα.'])
  ];
  if (notice > 0) {
    ruleRows.push(el('span'));
    ruleRows.push(el('span', null, ['Προθεσμία δήλωσης ',
      el('strong', { text: 'τουλάχιστον ' + notice + ' ' + (notice === 1 ? 'εργάσιμη ημέρα' : 'εργάσιμες ημέρες') }), ' πριν από το διαγώνισμα.']));
  }
  var rule = el('p', { class: 'dgn-rule', hidden: true }, [el('span', { class: 'dgn-rule-grid' }, ruleRows)]);
  var auth = el('div', { class: 'dgn-auth' });
  // Κεφαλίδα: μόνο ο κανόνας, μόνο για καθηγητές. Για επισκέπτες κρύβεται εντελώς (χωρίς κενό).
  var head = el('div', { class: 'dgn-head', hidden: true }, [rule]);
  var top = el('div', { class: 'dgn-top' });
  var msg = el('p', { class: 'dgn-msg', role: 'status', 'aria-live': 'polite' });
  var detail = el('div', { class: 'dgn-detail', hidden: true, role: 'status', 'aria-live': 'polite' });
  var mLabel = el('h3', { class: 'dgn-month' });
  var prevBtn = el('button', { type: 'button', class: 'dgn-nav', 'aria-label': 'Προηγούμενος μήνας', text: '‹', onclick: function () { shiftMonth(-1); } });
  var nextBtn = el('button', { type: 'button', class: 'dgn-nav', 'aria-label': 'Επόμενος μήνας', text: '›', onclick: function () { shiftMonth(1); } });
  // Υπόμνημα μόνο για καθηγητές: εξηγεί τις δύο ενδείξεις που δεν είναι αυτονόητες.
  // (Τα διαγωνίσματα και οι αργίες φαίνονται καθαρά στον πίνακα με το όνομά τους.)
  var legend = el('div', { class: 'dgn-legend', hidden: true }, [
    el('span', null, [el('i', { class: 'dgn-sw dgn-sw-mine' }), 'Δική σας δήλωση']),
    el('span', null, [el('i', { class: 'dgn-sw dgn-sw-full' }), 'Εβδομάδα πλήρης']),
    // Γκρι διαγράμμιση: μέρες που δεν δηλώνονται ακόμα λόγω προθεσμίας (μόνο αν υπάρχει προθεσμία, όχι για τη Διεύθυνση).
    el('span', { class: 'dgn-lg-soon', hidden: true }, [el('i', { class: 'dgn-sw dgn-sw-soon' }), 'Εκτός προθεσμίας'])
  ]);
  var filterSel = el('select', { id: 'dgn-filter', 'aria-label': 'Επιλογή τμήματος' }, [el('option', { value: '', text: 'Όλα τα τμήματα' })]);
  cfg.classes.forEach(function (c) { filterSel.appendChild(el('option', { value: c, text: 'Τμήμα ' + c })); });
  filterSel.value = state.filter;
  filterSel.addEventListener('change', function () { setFilter(filterSel.value); });
  // Επισκέπτες με «Όλα τα τμήματα»: μικρή υπόδειξη κάτω από το μενού (κρύβεται μόλις επιλεγεί τμήμα).
  var filterHint = el('span', { class: 'dgn-filter-hint', hidden: true, text: 'Επιλέξτε το τμήμα σας' }); // το βελάκι μπαίνει από το CSS
  var filterBar = el('div', { class: 'dgn-filter' }, [filterSel, filterHint]);
  // Μία γραμμή «τι βλέπω»: μήνας · τμήμα · υπόμνημα (στον υπολογιστή)
  var monthBar = el('div', { class: 'dgn-monthbar' }, [prevBtn, mLabel, nextBtn]);
  var bar = el('div', { class: 'dgn-bar' }, [monthBar, filterBar, legend]);
  var upcomingBox = el('div', { class: 'dgn-upcoming', hidden: true });
  // Επισκέπτες με επιλεγμένο τμήμα: η λίστα αρκεί· το ημερολόγιο (μήνας + πίνακας) ανοίγει με κουμπί.
  var calBtn = el('button', { type: 'button', class: 'dgn-calbtn', hidden: true, 'aria-expanded': 'false', onclick: function () {
    state.calOpen = !state.calOpen; applyCal();
  } });
  // Στον υπολογιστή: [μήνας · τμήμα · υπόμνημα] και από κάτω τα επόμενα διαγωνίσματα.
  // Στο κινητό (.dgn-stacked): τμήμα → επόμενα διαγωνίσματα → μήνας → υπόμνημα → πίνακας.
  var view = el('div', { class: 'dgn-view' }, [bar, upcomingBox, calBtn]);
  var tableBox = el('div', { class: 'dgn-tablebox' });
  var foot = el('div', { class: 'dgn-foot' });
  [head, top, msg, detail, view, tableBox, foot, auth].forEach(function (n) { root.appendChild(n); });
  // Αν η γραμμή μήνα · τμήματος · υπομνήματος δεν χωράει σε μία σειρά (π.χ. tablet),
  // τα στοιχεία μπαίνουν το ένα κάτω από το άλλο, κεντραρισμένα, αντί να «σπάσουν» άτσαλα.
  function fitBar() {
    view.classList.remove('dgn-stacked');
    var narrow = root.clientWidth <= 782;
    var kids = Array.prototype.filter.call(bar.children, function (k) { return k.offsetParent !== null; });
    var wraps = kids.some(function (k) { return k.offsetTop > kids[0].offsetTop + kids[0].offsetHeight / 2; });
    // Επισκέπτες με επιλεγμένο τμήμα: ίδια σειρά και στον υπολογιστή (λίστα → κουμπί → μήνας → πίνακας).
    var visitorClass = !canWrite() && !!state.filter;
    if (narrow || wraps || visitorClass) { view.classList.add('dgn-stacked'); }
  }
  function applyCal() {
    var folding = !canWrite() && !!state.filter;
    var hide = folding && !state.calOpen;
    calBtn.hidden = !folding;
    calBtn.textContent = '';
    calBtn.appendChild(document.createTextNode(hide ? 'Εμφάνιση ημερολογίου' : 'Απόκρυψη ημερολογίου'));
    calBtn.appendChild(el('span', { class: 'dgn-chev', 'aria-hidden': 'true', text: hide ? '▼' : '▲' }));
    calBtn.setAttribute('aria-expanded', hide ? 'false' : 'true');
    monthBar.hidden = hide;
    tableBox.hidden = hide;
    fitBar();
  }
  var lastW = -1;
  if (window.ResizeObserver) {
    new ResizeObserver(function () {
      var w = root.clientWidth;
      if (w !== lastW) { lastW = w; fitBar(); }
    }).observe(root);
  }

  /* ---------- φίλτρο τμήματος ---------- */
  function setFilter(c) {
    state.filter = cfg.classes.indexOf(c) !== -1 ? c : '';
    state.calOpen = false;
    filterSel.value = state.filter;
    if (f.cls && state.filter) f.cls.value = state.filter;
    state.selected = null; state.flash = null; state.confirmDel = false; state.upcoming = null;
    render(); loadUpcoming();
  }
  function loadUpcoming() {
    if (!state.filter) { renderUpcoming(); return; }
    var want = state.filter;
    var from = cfg.today > cfg.syStart ? cfg.today : cfg.syStart;
    if (from > cfg.syEnd) { state.upcoming = []; renderUpcoming(); return; }
    api('GET', 'exams', { params: { from: from, to: cfg.syEnd, cls: want } }).then(function (data) {
      if (want !== state.filter) return;
      state.upcoming = (data.exams || []).filter(function (e) { return e.cls === want; });
      renderUpcoming();
    }).catch(function () { state.upcoming = null; renderUpcoming(); });
  }
  // Σύντομη ημερομηνία για τη λίστα: «Τρί 13/10» (όπως οι μέρες στον πίνακα).
  var DAYS_SHORT = ['Κυρ', 'Δευ', 'Τρί', 'Τετ', 'Πέμ', 'Παρ', 'Σάβ'];
  function upDate(s) { return DAYS_SHORT[parse(s).getDay()] + ' ' + short(s); }
  function renderUpcoming() {
    upcomingBox.textContent = '';
    if (!state.filter) { upcomingBox.hidden = true; return; }
    upcomingBox.hidden = false;
    upcomingBox.appendChild(el('h4', { text: 'Επόμενα διαγωνίσματα · ' + state.filter }));
    if (state.upcoming === null) { upcomingBox.appendChild(el('p', { class: 'dgn-hint', text: 'Φόρτωση…' })); return; }
    if (!state.upcoming.length) { upcomingBox.appendChild(el('p', { class: 'dgn-hint', text: 'Δεν έχουν δηλωθεί διαγωνίσματα για τις επόμενες ημέρες.' })); return; }
    var ul = el('ul');
    state.upcoming.forEach(function (e) {
      ul.appendChild(el('li', null, [el('span', { class: 'dgn-up-date', title: longDate(e.date), text: upDate(e.date) }), el('span', { class: 'dgn-up-subj', text: e.subject + (e.teacher ? ' · ' + e.teacher : '') })]));
    });
    upcomingBox.appendChild(ul);
  }

  function setMsg(t, kind) { msg.textContent = t || ''; msg.className = 'dgn-msg' + (kind ? ' dgn-' + kind : ''); }

  /* ---------- πάνω μέρος: είσοδος ή φόρμα ---------- */
  var f = {};
  function renderTop() {
    top.textContent = '';
    foot.textContent = '';
    auth.textContent = '';
    rule.hidden = !canWrite();
    head.hidden = !canWrite();
    legend.hidden = !canWrite();
    filterHint.hidden = canWrite() || !!state.filter;
    legend.querySelector('.dgn-lg-soon').hidden = !((+cfg.minNotice || 0) > 0 && !isAdmin());
    applyCal();
    if (!canWrite()) {
      auth.appendChild(el('a', { class: 'dgn-authbtn', href: SSO ? cfg.ssoLogin : (cfg.access === 'code' ? pageUrl('login') : cfg.loginUrl), text: 'Είσοδος καθηγητών' }));
      return;
    }
    if ((cfg.access === 'code' || SSO) && !isAdmin()) {
      auth.appendChild(el('button', { type: 'button', class: 'dgn-authbtn', text: 'Έξοδος', onclick: logout }));
    }

    f.cls = el('select', { id: 'dgn-cls' });
    cfg.classes.forEach(function (c) { f.cls.appendChild(el('option', { value: c, text: c })); });
    if (state.filter) f.cls.value = state.filter;
    f.date = el('input', { id: 'dgn-date', type: 'date', required: true });
    if (!isAdmin()) {
      var minD = !cfg.allowPast && cfg.today > cfg.syStart ? cfg.today : cfg.syStart;
      if ((+cfg.minNotice || 0) > 0 && earliest > minD) minD = earliest;
      f.date.setAttribute('min', minD);
      f.date.setAttribute('max', cfg.syEnd);
    }
    f.subj = el('select', { id: 'dgn-subj', required: true }, [el('option', { value: '', text: '— Επιλέξτε —' })]);
    Object.keys(cfg.subjects).forEach(function (s) { f.subj.appendChild(el('option', { value: s, text: s })); });
    f.teacherLabel = el('label', { for: 'dgn-teacher' }, ['Εκπαιδευτικός']);
    setTeacherField();
    f.btn = el('button', { type: 'submit', class: 'dgn-primary', text: 'Δήλωση' });
    var form2 = el('form', { class: 'dgn-form', autocomplete: 'off', onsubmit: submit }, [
      el('label', { for: 'dgn-cls' }, ['Τμήμα', f.cls]),
      el('label', { for: 'dgn-date' }, ['Ημερομηνία', f.date]),
      el('label', { for: 'dgn-subj' }, ['Μάθημα', f.subj]),
      f.teacherLabel,
      f.btn
    ]);
    top.appendChild(form2);

    var tips = ['Πατήστε ένα κενό κελί για να συμπληρωθούν τμήμα και ημερομηνία.'];
    if (isAdmin()) {
      tips.push('Πατήστε μια δήλωση για λεπτομέρειες ή διαγραφή.');
    } else {
      tips.push('Πατήστε μια δήλωση για λεπτομέρειες. Τις δικές σας μπορείτε να τις διαγράψετε' +
        (cfg.access === 'code' ? ' από την ίδια συσκευή,' : '') + ' μέχρι και την ημέρα του διαγωνίσματος.');
    }
    foot.appendChild(el('ul', { class: 'dgn-tips' }, tips.map(function (t) { return el('li', { text: t }); })));
  }

  /* Πεδίο «Εκπαιδευτικός» (επώνυμο): λίστα επιλογής όταν η Διεύθυνση έχει ορίσει καθηγητές, αλλιώς ελεύθερο κείμενο. */
  function setTeacherField() {
    if (!f.teacherLabel) return;
    var prev = f.teacher ? f.teacher.value : '';
    // Το επώνυμο το θυμόμαστε μόνο σε «δική μου» συσκευή (είσοδος με «Να με θυμάσαι»),
    // ώστε σε κοινόχρηστο υπολογιστή να μη γίνει δήλωση με το όνομα του προηγούμενου.
    var remembered = prev || (rememberedDevice() ? lsGet('dgn_teacher') : '') || cfg.userName || '';
    var field;
    if (SSO && !isAdmin()) {
      // Είσοδος μέσω ΠΣΔ: το επώνυμο έρχεται από τον λογαριασμό και δεν αλλάζει.
      field = el('input', { id: 'dgn-teacher', readonly: true, tabindex: '-1', class: 'dgn-readonly' });
      field.value = state.me || '';
    } else if (state.teachers.length) {
      var names = state.teachers.slice().sort(function (a, b) { return a.localeCompare(b, 'el', { sensitivity: 'base' }); });
      field = el('select', { id: 'dgn-teacher', required: true }, [el('option', { value: '', text: '— Επιλέξτε —' })]);
      names.forEach(function (n) { field.appendChild(el('option', { value: n, text: n })); });
      field.value = names.indexOf(remembered) !== -1 ? remembered : '';
    } else {
      field = el('input', { id: 'dgn-teacher', maxlength: '40', required: true, placeholder: 'π.χ. Παπαδάκη', autocomplete: 'family-name' });
      field.value = remembered;
    }
    if (f.teacher && f.teacher.parentNode === f.teacherLabel) f.teacherLabel.replaceChild(field, f.teacher);
    else f.teacherLabel.appendChild(field);
    f.teacher = field;
  }

  function rememberedDevice() { return !!lsGet('dgn_token'); }
  function logout() {
    state.token = null; tokenSet(null); lsSet('dgn_teacher', null);
    if (SSO) { window.location.href = cfg.ssoLogout; return; } // αποσύνδεση και από το ΠΣΔ
    state.level = 'public'; state.selected = null; state.confirmDel = false;
    setMsg(''); // χωρίς μήνυμα: η αλλαγή της σελίδας (φόρμα → «Είσοδος καθηγητών») αρκεί
    renderTop(); render(); load();
  }

  /* ---------- ξεχωριστή σελίδα εισόδου (?dgn=login) ---------- */
  function loginPage() {
    if (SSO) { window.location.replace(cfg.ssoLogin); return; }
    if (cfg.access !== 'code') { window.location.replace(cfg.loginUrl); return; }
    if (state.token || state.level === 'admin') { window.location.replace(pageUrl()); return; }
    root.classList.add('dgn-loginview');
    var user = el('input', { type: 'text', name: 'username', autocomplete: 'username', value: (cfg.school || 'Σχολείο') + ' – καθηγητές', class: 'dgn-vh', tabindex: '-1', 'aria-hidden': 'true', readonly: true });
    var code = el('input', { id: 'dgn-code', type: 'password', name: 'password', autocomplete: 'current-password', required: true });
    // Μη επιλεγμένο από προεπιλογή: ασφαλέστερο σε κοινόχρηστους υπολογιστές.
    var remember = el('input', { id: 'dgn-remember', type: 'checkbox' });
    var btn = el('button', { type: 'submit', class: 'dgn-primary', text: 'Είσοδος' });
    var m = el('p', { class: 'dgn-msg', role: 'status', 'aria-live': 'polite' });
    var form = el('form', { class: 'dgn-loginpage', method: 'post', action: '#' }, [
      el('h2', { text: 'Είσοδος καθηγητών' }),
      user,
      el('label', { for: 'dgn-code' }, ['Κωδικός', code]),
      el('label', { for: 'dgn-remember', class: 'dgn-check' }, [remember, el('span', null, [
        'Να με θυμάται αυτή η συσκευή',
        el('small', { text: 'Μην το επιλέγετε σε κοινόχρηστο υπολογιστή, π.χ. στο γραφείο καθηγητών.' })
      ])]),
      btn, m,
      el('a', { class: 'dgn-back', href: pageUrl(), text: '← Πίσω στο πρόγραμμα διαγωνισμάτων' })
    ]);
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var v = code.value.trim();
      if (!v) { m.textContent = 'Γράψτε τον κωδικό καθηγητών.'; m.className = 'dgn-msg dgn-err'; code.focus(); return; }
      btn.disabled = true; m.textContent = 'Έλεγχος κωδικού…'; m.className = 'dgn-msg';
      api('POST', 'login', { body: { code: v, remember: remember.checked } }).then(function (data) {
        tokenSet(data.token, remember.checked);
        m.textContent = 'Επιτυχής είσοδος.'; m.className = 'dgn-msg dgn-ok';
        window.location.replace(pageUrl());
      }).catch(function (err) {
        btn.disabled = false; m.textContent = err.message; m.className = 'dgn-msg dgn-err';
        code.select();
      });
    });
    root.textContent = '';
    root.appendChild(form);
    code.focus();
  }

  /* ---------- κανόνες στον browser (ο server ελέγχει ξανά) ---------- */
  function weekCount(cls, wkStart) {
    var n = 0;
    for (var i = 0; i < 5; i++) if (state.exams[cls + '|' + ymd(addDays(wkStart, i))]) n++;
    return n;
  }
  function isPast(ds) { return ds < cfg.today; }
  // Ελάχιστη προειδοποίηση σε εργάσιμες ημέρες (ίδιος υπολογισμός με τον server).
  var earliest = (function () {
    var n = +cfg.minNotice || 0, d = parse(cfg.today);
    while (n > 0) {
      d = addDays(d, 1);
      var wd = d.getDay(), ds = ymd(d);
      if (wd !== 0 && wd !== 6 && !cfg.holidays[ds]) n--;
    }
    return ymd(d);
  })();
  function tooSoon(ds) { return (+cfg.minNotice || 0) > 0 && !isAdmin() && ds >= cfg.today && ds < earliest; }
  function tooSoonMsg() {
    var d = parse(earliest);
    return 'Τα διαγωνίσματα δηλώνονται τουλάχιστον ' + cfg.minNotice + ' εργάσιμες ημέρες πριν. Η νωρίτερη ημερομηνία που μπορείτε να δηλώσετε είναι η ' + DAYS_LONG[(d.getDay() + 6) % 7] + ' ' + d.getDate() + '/' + (d.getMonth() + 1) + '.';
  }
  function canBookPast() { return cfg.allowPast || isAdmin(); }
  function canDelete(e) { return isAdmin() || e.mine || !!state.keys[e.id]; }

  function submit(ev) {
    ev.preventDefault();
    if (state.busy) return;
    var cls = f.cls.value, date = f.date.value, subject = f.subj.value, teacher = f.teacher.value.trim();
    if (state.teachers.length && !teacher) { setMsg('Επιλέξτε το επώνυμό σας από τη λίστα.', 'err'); f.teacher.focus(); return; }
    if (!date) { setMsg('Επιλέξτε ημερομηνία.', 'err'); return; }
    if (!subject) { setMsg('Επιλέξτε μάθημα.', 'err'); f.subj.focus(); return; }
    if (teacher.length < 2) { setMsg('Γράψτε το επώνυμό σας.', 'err'); f.teacher.focus(); return; }
    var d = parse(date), wd = d.getDay();
    if (wd === 0 || wd === 6) { setMsg('Η ημερομηνία πέφτει Σαββατοκύριακο.', 'err'); return; }
    if (cfg.holidays[date]) { setMsg('Η ' + longDate(date) + ' είναι αργία (' + cfg.holidays[date] + ').', 'err'); return; }
    if (isPast(date) && !canBookPast()) { setMsg('Η ημερομηνία έχει περάσει.', 'err'); return; }
    if (tooSoon(date)) { setMsg(tooSoonMsg(), 'err'); return; }
    if (!inSchoolYear(date) && !isAdmin()) { setMsg('Η ημερομηνία είναι εκτός του τρέχοντος σχολικού έτους (' + short(cfg.syStart) + '/' + cfg.syStart.slice(0, 4) + ' – ' + short(cfg.syEnd) + '/' + cfg.syEnd.slice(0, 4) + ').', 'err'); return; }

    state.busy = true; f.btn.disabled = true; setMsg('Αποθήκευση…');
    lsSet('dgn_teacher', rememberedDevice() ? teacher : null);
    api('POST', 'exams', { body: { cls: cls, date: date, subject: subject, teacher: teacher } }).then(function (data) {
      if (data.deleteKey) { state.keys[data.id] = data.deleteKey; lsSet('dgn_keys', JSON.stringify(state.keys)); }
      setMsg('Δηλώθηκε: ' + subject + ', ' + cls + ', ' + longDate(date) + '.', 'ok');
      // Μετά τη δήλωση: μόνο το μήνυμα· χωρίς πλαίσιο λεπτομερειών. Το νέο κελί απλώς τονίζεται.
      state.selected = null; state.flash = cls + '|' + date; state.confirmDel = false;
      if (d.getMonth() !== state.view.m || d.getFullYear() !== state.view.y) state.view = { y: d.getFullYear(), m: d.getMonth() };
      loadUpcoming();
      return load();
    }).catch(function (err) {
      if (err.code === 'dgn_code_required') { state.token = null; tokenSet(null); state.level = 'public'; renderTop(); }
      setMsg(err.message, 'err');
      load();
    }).then(function () { state.busy = false; if (f.btn) f.btn.disabled = false; });
  }

  function remove(e) {
    if (state.busy) return;
    state.busy = true;
    var headers = {};
    if (state.keys[e.id]) headers['X-DGN-Delete-Key'] = state.keys[e.id];
    api('DELETE', 'exams/' + e.id, { headers: headers }).then(function () {
      delete state.keys[e.id]; lsSet('dgn_keys', JSON.stringify(state.keys));
      setMsg('Η δήλωση διαγράφηκε.', 'ok');
    }).catch(function (err) {
      if (err.code === 'dgn_gone') { delete state.keys[e.id]; lsSet('dgn_keys', JSON.stringify(state.keys)); }
      setMsg(err.message, 'err');
    }).then(function () {
      state.busy = false; state.selected = null; state.confirmDel = false;
      loadUpcoming();
      return load();
    });
  }

  /* ---------- όρια σχολικού έτους (ο διαχειριστής δεν έχει όρια) ---------- */
  function inSchoolYear(ds) { return ds >= cfg.syStart && ds <= cfg.syEnd; }
  function monthIdx(y, m) { return y * 12 + m; }
  var syFirst = (function () { var d = parse(cfg.syStart); return monthIdx(d.getFullYear(), d.getMonth()); })();
  var syLast = (function () { var d = parse(cfg.syEnd); return monthIdx(d.getFullYear(), d.getMonth()); })();
  function navLimits() {
    var cur = monthIdx(state.view.y, state.view.m), free = isAdmin();
    prevBtn.disabled = !free && cur <= syFirst;
    nextBtn.disabled = !free && cur >= syLast;
  }

  function shiftMonth(n) {
    var m = state.view.m + n, y = state.view.y;
    if (m < 0) { m = 11; y--; } else if (m > 11) { m = 0; y++; }
    if (!isAdmin() && (monthIdx(y, m) < syFirst || monthIdx(y, m) > syLast)) return;
    state.view = { y: y, m: m }; state.selected = null; state.confirmDel = false;
    state.exams = {}; state.loaded = false;
    render(); load();
  }

  function cellClick(cls, ds) {
    var key = cls + '|' + ds;
    state.selected = key; state.flash = null; state.confirmDel = false;
    var toForm = !state.exams[key] && canWrite() && f.cls;
    if (toForm) {
      f.cls.value = cls; f.date.value = ds; setMsg('');
      try { f.subj.focus({ preventScroll: true }); } catch (err) { f.subj.focus(); }
    }
    render();
    // Στο κινητό (ή όταν έχει κυλήσει η σελίδα) η φόρμα μπορεί να είναι εκτός οθόνης: ανεβαίνουμε ομαλά ως εκεί.
    if (toForm) scrollIntoViewIfNeeded(top);
    // Πλαίσιο λεπτομερειών (κάτω μέρος οθόνης): να μην κρύβει το κελί που πατήθηκε.
    else if (state.exams[key]) keepCellAboveDetail(cls, ds);
  }
  function scrollIntoViewIfNeeded(node) {
    var r = node.getBoundingClientRect();
    if (r.top < 0 || r.bottom > window.innerHeight) {
      window.scrollTo({ top: window.pageYOffset + r.top - 16, behavior: 'smooth' });
    }
  }
  function keepCellAboveDetail(cls, ds) {
    window.requestAnimationFrame(function () {
      if (detail.hidden) return;
      var cell = tableBox.querySelector('button[aria-label^="' + cls + ', ' + longDate(ds) + '"]');
      if (!cell) return;
      var c = cell.getBoundingClientRect(), d = detail.getBoundingClientRect();
      if (c.bottom > d.top - 8) window.scrollBy({ top: c.bottom - d.top + 16, behavior: 'smooth' });
    });
  }
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && state.selected && !detail.hidden) { state.selected = null; state.confirmDel = false; render(); }
  });

  /* ---------- πίνακας: ημέρες κάτω, τμήματα δεξιά (όπως το έντυπο) ---------- */
  function render() {
    applyCal();
    filterHint.hidden = canWrite() || !!state.filter;
    mLabel.textContent = MONTHS[state.view.m] + ' ' + state.view.y;
    navLimits();
    var r = monthRange();
    tableBox.textContent = '';
    if (state.loadError && !Object.keys(state.exams).length) {
      tableBox.appendChild(el('p', { class: 'dgn-empty', text: state.loadError }));
      renderDetail();
      return;
    }
    var shown = state.filter ? [state.filter] : cfg.classes;
    var single = shown.length === 1;
    var table = el('table', { class: 'dgn-grid' + (single ? ' dgn-single' : '') });
    var thead = el('thead');
    var hr = el('tr', null, [el('th', { scope: 'col', text: 'ΕΒΔ' }), el('th', { scope: 'col', class: 'dgn-dayh', text: 'ΗΜΕΡΟΜ.' })]);
    shown.forEach(function (c) { hr.appendChild(el('th', { scope: 'col', class: 'dgn-clsh', text: c })); });
    thead.appendChild(hr); table.appendChild(thead);
    var tbody = el('tbody');
    var wn = 1;
    for (var wk = r.start; wk <= r.last; wk = addDays(wk, 7), wn++) {
      for (var i = 0; i < 5; i++) {
        var d = addDays(wk, i), ds = ymd(d), inMonth = d.getMonth() === state.view.m;
        var tr = el('tr', { class: i === 4 ? 'dgn-weekend' : null });
        if (i === 0) tr.appendChild(el('th', { scope: 'rowgroup', rowspan: '5', class: 'dgn-wk', text: wn + 'η' }));
        var hol = cfg.holidays[ds];
        tr.appendChild(el('th', { scope: 'row', class: 'dgn-day' + (ds === cfg.today ? ' dgn-today' : '') + (!inMonth ? ' dgn-outday' : '') }, [
          el('b', { text: DAYS[i] }), ' ' + short(ds)
        ]));
        if (hol) {
          tr.appendChild(el('td', { class: 'dgn-off', colspan: String(shown.length), text: hol }));
          tbody.appendChild(tr);
          continue;
        }
        shown.forEach(function (cls) {
          var key = cls + '|' + ds, e = state.exams[key];
          var td = el('td', { class: 'dgn-c' });
          // Οι ρίγες «εβδομάδα πλήρης» αφορούν μόνο όσους μπορούν να δηλώσουν.
          var full = !e && canWrite() && weekCount(cls, wk) >= cfg.weeklyMax;
          var past = (isPast(ds) && !canBookPast()) || (!inSchoolYear(ds) && !isAdmin()) || tooSoon(ds);
          // «Κλειστές» μελλοντικές μέρες (προθεσμία δήλωσης ή εκτός σχολικής χρονιάς): διαγράμμιση μόνο για καθηγητές.
          // Οι περασμένες μέρες μένουν λευκές: είναι αυτονόητο ότι δεν δηλώνονται.
          var soon = past && !isPast(ds);
          var label = cls + ', ' + longDate(ds) + ': ';
          if (e) {
            // «Δική σας δήλωση»: μόνο όσες έκανε πραγματικά ο χρήστης (και για τη Διεύθυνση, όχι όλες)·
            // μόνο όταν είναι συνδεδεμένος — οι επισκέπτες (και μετά την έξοδο) δεν βλέπουν κουκίδες.
            // Περασμένα διαγωνίσματα (πριν από σήμερα): πιο αχνά, ώστε να ξεχωρίζουν τα επόμενα.
            td.className += ' dgn-taken' + (canWrite() && (e.mine || state.keys[e.id]) ? ' dgn-mine' : '') + (isPast(ds) ? ' dgn-past' : '');
            // Με ένα τμήμα υπάρχει χώρος για το πλήρες όνομα του μαθήματος.
            var abbr = single ? e.subject : (cfg.subjects[e.subject] || e.subject.slice(0, 3).toUpperCase());
            label += e.subject + (e.teacher ? ', ' + e.teacher : '');
            if (canWrite()) {
              td.appendChild(el('button', { type: 'button', title: e.subject + (e.teacher ? ' · ' + e.teacher : ''), 'aria-label': label, text: abbr, onclick: cellClick.bind(null, cls, ds) }));
            } else {
              // Επισκέπτες: χωρίς πάτημα και χωρίς πλαίσιο λεπτομερειών (όλα φαίνονται στον πίνακα και στη λίστα).
              // Κρατάμε <button> (χωρίς ενέργεια), ώστε το κελί να έχει ακριβώς την ίδια εμφάνιση σε κάθε θέμα.
              td.appendChild(el('button', { type: 'button', class: 'dgn-ro', tabindex: '-1', title: e.subject, 'aria-label': label, text: abbr }));
            }
          } else if (full) {
            td.className += ' dgn-full';
            td.appendChild(el('span', { title: 'Η εβδομάδα είναι πλήρης για το ' + cls, 'aria-label': label + 'η εβδομάδα είναι πλήρης' }));
          } else if (!canWrite() || past || !state.loaded) {
            // Το γκρι «κλειστό για δήλωση» αφορά μόνο όσους δηλώνουν· οι επισκέπτες δεν το βλέπουν.
            td.className += ' dgn-free-ro' + (soon && canWrite() ? ' dgn-soon' : '');
          } else {
            td.className += ' dgn-free';
            td.appendChild(el('button', { type: 'button', 'aria-label': label + 'ελεύθερο, πατήστε για δήλωση', onclick: cellClick.bind(null, cls, ds) }));
          }
          if (!inMonth) td.className += ' dgn-out';
          if (state.selected === key || state.flash === key) td.className += ' dgn-sel';
          tr.appendChild(td);
        });
        tbody.appendChild(tr);
      }
    }
    table.appendChild(tbody);
    tableBox.appendChild(table);
    renderDetail();
  }

  function renderDetail() {
    var e = state.selected && state.exams[state.selected];
    detail.textContent = '';
    if (!e) { detail.hidden = true; padForDetail(); return; }
    detail.hidden = false;
    // Σύντομο: «ΠΑΠΑΔΑΚΗ · Φυσική, Β2 · Τρί 6/10» — με έμφαση στο όνομα (το μόνο που δεν φαίνεται στον πίνακα).
    var p = el('p', null, e.teacher
      ? [el('strong', { text: e.teacher }), ' · ' + e.subject + ', ' + e.cls + ' · ' + upDate(e.date)]
      : [el('strong', { text: e.subject }), ', ' + e.cls + ' · ' + upDate(e.date)]);
    var actions = el('div', { class: 'dgn-actions' });
    // Οι καθηγητές διαγράφουν δικές τους δηλώσεις από σήμερα και μετά· περασμένες μόνο η Διεύθυνση.
    if (canWrite() && canDelete(e) && (isAdmin() || e.date >= cfg.today)) {
      if (!state.confirmDel) {
        actions.appendChild(el('button', { type: 'button', class: 'dgn-ghost', text: 'Διαγραφή', onclick: function () { state.confirmDel = true; renderDetail(); } }));
      } else {
        actions.appendChild(el('span', { text: 'Να διαγραφεί;' }));
        actions.appendChild(el('button', { type: 'button', class: 'dgn-danger', text: 'Ναι, διαγραφή', onclick: function () { remove(e); } }));
        actions.appendChild(el('button', { type: 'button', class: 'dgn-ghost', text: 'Άκυρο', onclick: function () { state.confirmDel = false; renderDetail(); } }));
      }
    }
    // Στην ερώτηση «Να διαγραφεί;» αρκεί το «Άκυρο» (επιστρέφει σε «Διαγραφή | Κλείσιμο»).
    if (!state.confirmDel) actions.appendChild(el('button', { type: 'button', class: 'dgn-ghost', text: 'Κλείσιμο', onclick: function () { state.selected = null; state.confirmDel = false; render(); } }));
    detail.appendChild(p); detail.appendChild(actions);
    padForDetail();
  }
  // Όσο το πλαίσιο είναι ανοιχτό, κενό στο κάτω μέρος ίσο με την απόστασή του από το κάτω άκρο της οθόνης
  // (μετριέται, ώστε να ισχύει και σε iPhone με γραμμή Home),
  // ώστε να μην κρύβει τις οδηγίες και το «Έξοδος» όταν κυλήσουμε ως το τέλος.
  function padForDetail() {
    root.style.paddingBottom = detail.hidden ? '' : Math.ceil(window.innerHeight - detail.getBoundingClientRect().top) + 'px';
  }

  if (ssoError) {
    var SSO_ERR = {
      role: 'Η είσοδος επιτρέπεται μόνο σε εκπαιδευτικούς. Ο λογαριασμός ΠΣΔ με τον οποίο συνδεθήκατε δεν είναι λογαριασμός εκπαιδευτικού.',
      unit: 'Ο λογαριασμός σας στο ΠΣΔ δεν ανήκει σε εκπαιδευτικό αυτής της σχολικής μονάδας. Αν υπηρετείτε στο σχολείο, ενημερώστε τη Διεύθυνση.',
      nounit: 'Η είσοδος μέσω ΠΣΔ δεν έχει ολοκληρωθεί στις ρυθμίσεις (λείπει ο κωδικός σχολικής μονάδας). Ενημερώστε τη Διεύθυνση.',
      server: 'Η σύνδεση με το ΠΣΔ δεν ολοκληρώθηκε. Δοκιμάστε ξανά σε λίγο.'
    };
    setMsg(SSO_ERR[ssoError] || SSO_ERR.server, 'err');
  }
  // Οι δηλώσεις του πρώτου μήνα έρχονται μέσα στη σελίδα: ο πίνακας εμφανίζεται αμέσως γεμάτος.
  // Το load() τρέχει κανονικά αμέσως μετά (ονόματα, «δικές μου», νεότερες αλλαγές).
  (function usePreload() {
    var p = cfg.preload, r0 = monthRange();
    if (!p || !p.exams || p.from !== ymd(r0.start) || p.to !== ymd(r0.end)) return;
    p.exams.forEach(function (e) { state.exams[e.cls + '|' + e.date] = e; });
    state.loaded = true;
  })();
  try {
    renderTop();
    render();
    load();
    renderUpcoming();
    loadUpcoming();
  } catch (e) {
    root.textContent = '';
    root.appendChild(el('p', { class: 'dgn-empty', text: 'Η σελίδα δεν φόρτωσε σωστά. Ανανεώστε τη σελίδα (Ctrl+F5 ή σύρετε προς τα κάτω στο κινητό).' }));
    if (window.console) console.error('Exams Planner:', e);
  }
  // ανανέωση κάθε λεπτό, ώστε να φαίνονται οι δηλώσεις των συναδέλφων
  setInterval(function () { if (document.visibilityState === 'visible' && !state.busy) { load(); loadUpcoming(); } }, 60000);
})();
