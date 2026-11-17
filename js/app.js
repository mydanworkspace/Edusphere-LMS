/* Marks the page as JavaScript-enabled so the reveal animations can start hidden */
document.documentElement.classList.add('js');

/* ==========================================================
   EduSphere Portal - shared script (loaded by every page)
   Holds: sanitizer, accounts + saved progress, course data, study tracker, live timetable,
   assessment calendar, session bar (last seen, time in system, countdowns), search, messaging, charts, animations.
   SECURITY NOTE: all of this runs in the browser. It improves usability and stops basic mistakes only.
   Real login, password storage, validation and output encoding MUST be done on a server over HTTPS.
   ========================================================== */
(function () {
  'use strict';
  const USER_KEY = 'edusphere_username', SESSION_KEY = 'edusphere_session', ACC_KEY = 'edusphere_accounts';
  let MEET_URL = 'https://meet.google.com/';   // replace with your real live-class room links
  const TZ = 'Africa/Nairobi';
  let memoryUser = null;

  /* ===== SECTION START: JS - Sanitizer ===== */
  /* ---------- Sanitizer: ENCODES risky characters (does not just delete them) ---------- */
  const ENT = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;', '/': '&#47;' };
  function sanitizeInput(s, trim) {
    const out = String(s).replace(/[\u0000-\u0008\u000b-\u001f\u007f]/g, '').replace(/[&<>"'`\/]/g, c => ENT[c]);
    return trim ? out.trim() : out;
  }
  function attachSanitizer(el) { el.addEventListener('input', () => { const c = sanitizeInput(el.value, false); if (c !== el.value) el.value = c; }); }

  /* ===== SECTION START: JS - Safe storage helpers ===== */
  /* ---------- Safe storage helpers (JSON in localStorage, never throws) ---------- */
  function lsGet(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } }
  function lsSet(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { } }
  function getUser() { try { const v = localStorage.getItem(USER_KEY); return v !== null ? v : memoryUser; } catch (e) { return memoryUser; } }
  function setUser(v) { memoryUser = v; try { localStorage.setItem(USER_KEY, v); } catch (e) { } }
  function clearUser() { memoryUser = null; try { localStorage.removeItem(USER_KEY); localStorage.removeItem(SESSION_KEY); } catch (e) { } }
  function strongPassword(p) { return p.length >= 6 && /[A-Za-z]/.test(p) && /\d/.test(p); }
  function el(tag, cls, text) { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }

  /* ===== SECTION START: JS - Server connection (api.php on XAMPP: PHP + MySQL) ===== */
  /* When api.php answers, courses, news, timetable, assessments, fees and settings come from the database (edited in admin.php),
     accounts and study progress live on the server, and activity is logged. When the page is opened without the server
     (for example by double-clicking the file) it falls back to the built-in sample data and device-only accounts. */
  let apiOn = false, WA = '254700000000', FEES = null, LOCKS = {};   // LOCKS: resources the admin removed and locked for this student
  async function api(action, body) {
    const o = { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } };
    if (body) { o.method = 'POST'; o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
    const r = await fetch('api.php?action=' + action, o);
    return r.json();
  }
  async function loadData() {
    try {
      const d = await api('data'); if (!d || !d.ok) return;
      COURSES.length = 0; COURSES.push(...d.courses); NEWS.length = 0; NEWS.push(...d.news); ASSESS.length = 0; ASSESS.push(...d.assess);
      Object.keys(TT).forEach(k => { delete TT[k]; }); Object.assign(TT, d.tt);
      if (d.slots) { SLOTS.length = 0; SLOTS.push(...d.slots); }
      SEM_START = Date.parse(d.semStart); MEET_URL = d.meet; WA = d.whatsapp; FEES = d.fees; apiOn = true;
    } catch (e) { apiOn = false; }
  }
  const idsToMap = a => { const o = {}; (a || []).forEach(i => { o['r' + i] = true; }); return o; };
  async function serverAuth(action, name, pw) {
    try {
      const r = await api(action, { username: name, password: pw });
      if (!r.ok) return { ok: false, msg: r.msg || 'Something went wrong. Try again.' };
      LOCKS = r.locks || {}; setUser(r.display); lsSet(SESSION_KEY, { user: r.display, start: Date.now(), prev: r.prev || null }); lsSet(pKey(), idsToMap(r.progress)); return { ok: true };
    } catch (e) { return { ok: false, msg: 'Cannot reach the server. Check that Apache and MySQL are running in XAMPP.' }; }
  }
  async function boot() {   // runs once before the first page is shown
    await loadData(); if (!apiOn) return;
    renderTicker();
    document.querySelectorAll('a.wa').forEach(a => { a.href = 'https://wa.me/' + WA + '?text=Hello%20EduSphere%2C%20I%20need%20support'; });
    try {
      const m = await api('me');   // is there still a valid server session?
      if (m.ok) { LOCKS = m.locks || {}; setUser(m.display); if (!lsGet(SESSION_KEY, null)) lsSet(SESSION_KEY, { user: m.display, start: Date.now(), prev: m.prev || null }); lsSet(pKey(), idsToMap(m.progress)); }
      else if (getUser()) clearUser();
    } catch (e) { }
  }
  function renderTicker() {
    const mv = document.querySelector('.ticker__move'); if (!mv) return; mv.textContent = '';
    [false, true].forEach(dup => NEWS.forEach(n => { const s = el('span', '', n.t); if (dup) s.setAttribute('aria-hidden', 'true'); mv.appendChild(s); }));
  }
  function renderFees() {   // rebuilds the fee table from the database, with rowspan per category and colspan totals
    if (!FEES) return;
    const tb = document.querySelector('[data-view="finances"] tbody'), tf = document.querySelector('[data-view="finances"] tfoot'); tb.textContent = ''; tf.textContent = '';
    let i = 0, cr = 0, fe = 0;
    while (i < FEES.length) {
      let j = i; while (j < FEES.length && FEES[j].cat === FEES[i].cat) j++;
      for (let k = i; k < j; k++) {
        const f = FEES[k], tr = el('tr');
        if (k === i) { const th = el('th', '', f.cat); th.scope = 'rowgroup'; th.rowSpan = j - i; tr.appendChild(th); }
        const st = el('td'); st.appendChild(el('span', 'badge ' + (f.st === 'Available' ? 'badge--ok' : 'badge--full'), f.st));
        tr.append(el('td', '', f.mod), el('td', 'num', String(f.cr)), el('td', 'num', 'KES ' + f.fee.toLocaleString('en-US')), st); tb.appendChild(tr); cr += f.cr; fe += f.fee;
      }
      i = j;
    }
    if (!FEES.length) { const tr = el('tr'), td = el('td', '', 'No modules have been added yet.'); td.colSpan = 5; tr.appendChild(td); tb.appendChild(tr); }
    const r1 = el('tr'), h1 = el('th', '', 'Total credit hours'); h1.scope = 'row'; h1.colSpan = 2; const c1 = el('td', 'num', String(cr)), e1 = el('td'); e1.colSpan = 2; r1.append(h1, c1, e1);
    const r2 = el('tr'), h2 = el('th', '', 'Total fees (KES)'); h2.scope = 'row'; h2.colSpan = 3; r2.append(h2, el('td', 'num', 'KES ' + fe.toLocaleString('en-US')), el('td')); tf.append(r1, r2);
  }

  /* ===== SECTION START: JS - Accounts ===== */
  /* ---------- Accounts: remembers username + a salted password HASH (never the password itself) on THIS device.
     First login with a new username creates the account; later logins must use the same password. Study progress
     is saved under the username, so it comes back when you log in again. Another device or browser has its own
     empty storage: syncing between devices needs a server. ---------- */
  function cyrb53(str) {   // small non-cryptographic fallback hash for browsers without crypto.subtle
    let h1 = 0xdeadbeef, h2 = 0x41c6ce57;
    for (let i = 0; i < str.length; i++) { const c = str.charCodeAt(i); h1 = Math.imul(h1 ^ c, 2654435761); h2 = Math.imul(h2 ^ c, 1597334677); }
    h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
    h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
    return (4294967296 * (2097151 & h2) + (h1 >>> 0)).toString(16);
  }
  async function hashPw(salt, pw, alg) {
    const s = salt + ':' + pw;
    if (alg === 'sha256' && window.crypto && crypto.subtle) {
      const b = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(s));
      return Array.from(new Uint8Array(b)).map(x => x.toString(16).padStart(2, '0')).join('');
    }
    return cyrb53(s);
  }
  function startSession(a) {
    const key = a.display.toLowerCase(), prof = lsGet('edusphere_profile_' + key, {}), now = Date.now();
    lsSet(SESSION_KEY, { user: a.display, start: now, prev: prof.lastActive || null });   // prev = when you were last here
    prof.lastActive = now; lsSet('edusphere_profile_' + key, prof); setUser(a.display); return { ok: true };
  }
  /* Create account: refuses a username that already exists on this device */
  async function register(name, pw) {
    if (apiOn) return serverAuth('register', name, pw);
    const key = name.toLowerCase(), accs = lsGet(ACC_KEY, {});
    if (accs[key]) return { ok: false, msg: 'That username is already taken on this device. Use "Log in" instead.' };
    const salt = Math.random().toString(36).slice(2) + Date.now().toString(36), alg = (window.crypto && crypto.subtle) ? 'sha256' : 'basic';
    accs[key] = { display: name, salt, alg, hash: await hashPw(salt, pw, alg) }; lsSet(ACC_KEY, accs); return startSession(accs[key]);
  }
  /* Log in: verifies the salted hash of the password */
  async function login(name, pw) {
    if (apiOn) return serverAuth('login', name, pw);
    const a = lsGet(ACC_KEY, {})[name.toLowerCase()];
    if (!a) return { ok: false, msg: 'No account found with that username on this device. Choose "Create account" first.' };
    if (await hashPw(a.salt, pw, a.alg) !== a.hash) return { ok: false, msg: 'Wrong password for this username.' };
    return startSession(a);
  }
  function touch() { const u = getUser(); if (!u) return; if (apiOn) api('ping', {}).catch(() => { }); const k = 'edusphere_profile_' + u.toLowerCase(), p = lsGet(k, {}); p.lastActive = Date.now(); lsSet(k, p); }

  /* ===== SECTION START: JS - Course data ===== */
  /* ---------- Course data (sample content: edit freely). V = video, N = notes (article), X = any link.
     Last number = suggested study minutes (90 = 1h 30m). Search links always resolve; X links go to well-known learning sites. ---------- */
  const yt = q => 'https://www.youtube.com/results?search_query=' + encodeURIComponent(q);
  const wk = q => 'https://en.wikipedia.org/w/index.php?search=' + encodeURIComponent(q);
  const V = (t, q, m) => ({ t, url: yt(q), m, k: 'Video' });
  const N = (t, q, m) => ({ t, url: wk(q), m, k: 'Notes' });
  const X = (t, url, m, k) => ({ t, url, m, k: k || 'Notes' });
  const EXTRA = 'Extra: more notes and videos';
  const COURSES = [
    { code: 'CS101', title: 'Introduction to Programming', who: 'Dr. A. Mwangi', mail: 'a.mwangi@edusphere.ac.ke', tel: '+254 700 000 101', credits: 3, kw: 'coding code python software algorithms computer',
      why: 'Programming is the base of every software, data and technology career. This course teaches you to think logically and turn ideas into working code.', gain: 'writing and debugging simple programs, using variables, loops and functions, and breaking problems into steps.',
      units: [
        { t: 'Unit 1: Variables and data types', r: [V('Programming basics for beginners', 'programming basics for beginners full course', 90), N('Variable (computer science)', 'variable computer science', 20)] },
        { t: 'Unit 2: Control flow and loops', r: [V('If statements and loops explained', 'loops and conditionals programming tutorial', 60), N('Control flow', 'control flow programming', 25)] },
        { t: 'Unit 3: Functions', r: [V('Functions for beginners', 'functions programming beginners tutorial', 45), N('Subroutine', 'subroutine function programming', 20)] },
        { t: EXTRA, r: [X('Python tutorial (W3Schools)', 'https://www.w3schools.com/python/', 60), X('The Python Tutorial (official docs)', 'https://docs.python.org/3/tutorial/', 45), X('freeCodeCamp videos on YouTube', 'https://www.youtube.com/@freecodecamp', 120, 'Video')] }] },
    { code: 'CS201', title: 'Data Structures', who: 'Prof. J. Otieno', mail: 'j.otieno@edusphere.ac.ke', tel: '+254 700 000 102', credits: 3, kw: 'arrays lists trees graphs stacks queues algorithms',
      why: 'Choosing the right data structure decides whether software is fast or slow. This course gives you the toolkit used in every technical interview and large system.', gain: 'storing and organising data efficiently, comparing algorithms, and implementing lists, stacks, queues and trees.',
      units: [
        { t: 'Unit 1: Arrays and linked lists', r: [V('Data structures full course', 'data structures arrays linked lists course', 120), N('Linked list', 'linked list', 20)] },
        { t: 'Unit 2: Stacks and queues', r: [V('Stacks and queues explained', 'stacks and queues data structures', 50), N('Stack (abstract data type)', 'stack abstract data type', 15)] },
        { t: 'Unit 3: Trees and graphs', r: [V('Binary trees and graphs', 'binary trees graphs data structures', 90), N('Tree (data structure)', 'tree data structure', 25)] },
        { t: EXTRA, r: [X('Visualise algorithms (VisuAlgo)', 'https://visualgo.net/en', 40), X('Data structures notes (GeeksforGeeks)', 'https://www.geeksforgeeks.org/data-structures/', 60)] }] },
    { code: 'CS301', title: 'Databases', who: 'Dr. F. Abdi', mail: 'f.abdi@edusphere.ac.ke', tel: '+254 700 000 107', credits: 4, kw: 'sql data tables queries normalisation transactions',
      why: 'Almost every app stores data. This course shows how to design reliable databases and ask them questions with SQL.', gain: 'writing SQL queries, designing tables with keys and relationships, and understanding transactions and indexes.',
      units: [
        { t: 'Unit 1: SQL basics', r: [V('SQL tutorial for beginners', 'sql tutorial for beginners full course', 100), N('SQL', 'SQL query language', 25)] },
        { t: 'Unit 2: Database design', r: [V('Database design and normalisation', 'database design normalization explained', 70), N('Database normalization', 'database normalization', 25)] },
        { t: 'Unit 3: Transactions and indexes', r: [V('Transactions and indexes', 'database transactions indexes explained', 60), N('ACID', 'ACID database transactions', 20)] },
        { t: EXTRA, r: [X('SQL tutorial (W3Schools)', 'https://www.w3schools.com/sql/', 60), X('PostgreSQL tutorial (official docs)', 'https://www.postgresql.org/docs/current/tutorial.html', 60)] }] },
    { code: 'MA101', title: 'Calculus I', who: 'Dr. L. Wanjiru', mail: 'l.wanjiru@edusphere.ac.ke', tel: '+254 700 000 103', credits: 3, kw: 'maths limits derivatives integrals differentiation',
      why: 'Calculus describes change and is the language of engineering, economics and science.', gain: 'finding limits, differentiating and integrating functions, and applying them to rates and areas.',
      units: [
        { t: 'Unit 1: Limits', r: [V('Limits for beginners', 'calculus limits explained', 75), N('Limit of a function', 'limit of a function', 25)] },
        { t: 'Unit 2: Derivatives', r: [V('Derivatives full lesson', 'calculus derivatives full lesson', 100), N('Derivative', 'derivative mathematics', 30)] },
        { t: 'Unit 3: Integrals', r: [V('Introduction to integrals', 'calculus integrals introduction', 80), N('Integral', 'integral calculus', 25)] },
        { t: EXTRA, r: [X('Calculus 1 (Khan Academy)', 'https://www.khanacademy.org/math/calculus-1', 120), X('Calculus I notes (Paul\'s Online Notes)', 'https://tutorial.math.lamar.edu/Classes/CalcI/CalcI.aspx', 90)] }] },
    { code: 'MA102', title: 'Linear Algebra', who: 'Dr. N. Chebet', mail: 'n.chebet@edusphere.ac.ke', tel: '+254 700 000 108', credits: 3, kw: 'maths vectors matrices equations eigenvalues',
      why: 'Linear algebra powers graphics, machine learning and engineering models.', gain: 'working with vectors and matrices, solving systems of equations, and finding eigenvalues.',
      units: [
        { t: 'Unit 1: Vectors and matrices', r: [V('Vectors and matrices intro', 'linear algebra vectors matrices introduction', 80), N('Matrix (mathematics)', 'matrix mathematics', 25)] },
        { t: 'Unit 2: Systems of equations', r: [V('Gaussian elimination', 'gaussian elimination linear algebra', 60), N('Gaussian elimination', 'gaussian elimination', 20)] },
        { t: 'Unit 3: Eigenvalues', r: [V('Eigenvalues and eigenvectors', 'eigenvalues eigenvectors explained', 70), N('Eigenvalues and eigenvectors', 'eigenvalues and eigenvectors', 25)] },
        { t: EXTRA, r: [X('Linear algebra (Khan Academy)', 'https://www.khanacademy.org/math/linear-algebra', 120), X('3Blue1Brown videos on YouTube', 'https://www.youtube.com/@3blue1brown', 90, 'Video')] }] },
    { code: 'MA201', title: 'Probability & Statistics', who: 'Dr. P. Kamau', mail: 'p.kamau@edusphere.ac.ke', tel: '+254 700 000 104', credits: 3, kw: 'maths data mean variance distribution hypothesis',
      why: 'Statistics turns raw data into decisions in health, business and research.', gain: 'summarising data, calculating probabilities, and reasoning with common distributions.',
      units: [
        { t: 'Unit 1: Descriptive statistics', r: [V('Statistics basics', 'statistics mean median mode variance', 70), N('Descriptive statistics', 'descriptive statistics', 20)] },
        { t: 'Unit 2: Probability rules', r: [V('Probability explained', 'probability rules explained', 60), N('Probability', 'probability theory', 25)] },
        { t: 'Unit 3: Distributions', r: [V('Normal distribution', 'normal distribution statistics lesson', 55), N('Normal distribution', 'normal distribution', 20)] },
        { t: EXTRA, r: [X('Statistics and probability (Khan Academy)', 'https://www.khanacademy.org/math/statistics-probability', 120), X('Seeing Theory (interactive)', 'https://seeing-theory.brown.edu/', 40)] }] },
    { code: 'BU101', title: 'Principles of Management', who: 'Ms. R. Achieng', mail: 'r.achieng@edusphere.ac.ke', tel: '+254 700 000 105', credits: 3, kw: 'business leadership planning organisation teams',
      why: 'Every organisation needs people who can plan, organise and lead. This course introduces how managers make that work.', gain: 'planning and setting goals, organising resources, motivating teams and making decisions.',
      units: [
        { t: 'Unit 1: What managers do', r: [V('Introduction to management', 'principles of management introduction', 60), N('Management', 'management business', 20)] },
        { t: 'Unit 2: Planning and strategy', r: [V('Strategic planning basics', 'strategic planning for business basics', 50), N('Strategic management', 'strategic management', 25)] },
        { t: 'Unit 3: Leading teams', r: [V('Leadership styles', 'leadership styles explained', 45), N('Leadership', 'leadership', 20)] },
        { t: EXTRA, r: [X('Principles of Management (OpenStax textbook)', 'https://openstax.org/details/books/principles-management', 90)] }] },
    { code: 'BU205', title: 'Financial Accounting', who: 'Mr. D. Njoroge', mail: 'd.njoroge@edusphere.ac.ke', tel: '+254 700 000 106', credits: 4, kw: 'business money accounts balance sheet ledger bookkeeping',
      why: 'Accounting is the language of business. You learn to record transactions and read the financial statements that investors and managers rely on.', gain: 'recording transactions, preparing a balance sheet and income statement, and reading financial results.',
      units: [
        { t: 'Unit 1: The accounting equation', r: [V('Accounting basics', 'accounting basics accounting equation', 80), N('Accounting equation', 'accounting equation', 15)] },
        { t: 'Unit 2: Journals and ledgers', r: [V('Journal entries explained', 'journal entries and ledgers tutorial', 70), N('General ledger', 'general ledger', 20)] },
        { t: 'Unit 3: Financial statements', r: [V('Balance sheet and income statement', 'balance sheet income statement explained', 90), N('Financial statement', 'financial statement', 25)] },
        { t: EXTRA, r: [X('Principles of Financial Accounting (OpenStax textbook)', 'https://openstax.org/details/books/principles-financial-accounting', 90), X('Core finance (Khan Academy)', 'https://www.khanacademy.org/economics-finance-domain/core-finance', 90)] }] }
  ];
  /* Same for every course: edit to match your policy. {who} is replaced with the lecturer's name. */
  const EXPECT = ['Attend your live classes and study the unit notes and videos before each session.', 'Spend about 5 hours a week on independent study and practice.', 'Submit 2 assignments and sit 3 CATs on time.', 'Do your own work: copying is academic misconduct.'];
  const HELP = ['{who} runs the live classes and works through examples step by step.', 'Questions are welcome in class and by email. Replies come within 2 working days.', 'Assignments and CATs are marked with written feedback within one week.', 'Revision sessions are held before the final exam.'];
  /* Assessment calendar: CATs every 3 weeks (weeks 3, 6, 9), assignments every 2 weeks (weeks 2 and 4), final exam week 13.
     Weights: 3 CATs x 10% + 2 assignments x 5% + final exam 60% = 100%. Items fall due Friday 17:00 EAT of their week. */
  let SEM_START = Date.parse('2026-10-19T00:00:00+03:00');   // Monday of week 1: change each semester
  const dueOf = w => SEM_START + (((w - 1) * 7 + 4) * 24 + 17) * 3600000;
  const ASSESS = [
    { n: 'Assignment 1', w: 2, p: 5, d: 'Practical task on Unit 1' }, { n: 'CAT 1', w: 3, p: 10, d: '1-hour test on Unit 1' },
    { n: 'Assignment 2', w: 4, p: 5, d: 'Problem set on Units 1 and 2' }, { n: 'CAT 2', w: 6, p: 10, d: '1-hour test on Units 1 and 2' },
    { n: 'CAT 3', w: 9, p: 10, d: '1-hour test on Units 1 to 3' }, { n: 'Final exam', w: 13, p: 60, d: '3-hour exam on all units' }];
  const NEWS = [
    { d: '10 Nov 2026', t: 'Vice Chancellor urges students to prepare for the upcoming exams', b: 'In his weekly address he asked every student to start revising early and to use the live revision classes.' },
    { d: '9 Nov 2026', t: 'Library extends opening hours during the exam period', b: 'The library will now open from 7:00 to 22:00 on weekdays until the end of the exams.' },
    { d: '8 Nov 2026', t: 'Semester registration closes on 30 November', b: 'Check the Finances page for fees and payment guidelines before you register.' },
    { d: '6 Nov 2026', t: 'Alumni career fair announced for January', b: 'More than 20 employers will meet final-year students on campus.' },
    { d: '5 Nov 2026', t: 'New live-class timetable is now available', b: 'Open the Dashboard to see which classes are live and join with one click.' }];

  /* ===== SECTION START: JS - Study tracker ===== */
  /* ---------- Study tracker: progress saved per user as {resourceId: true}. Survives logout and later logins. ---------- */
  const pKey = () => 'edusphere_progress_' + (getUser() || 'guest').toLowerCase();
  const loadDone = () => lsGet(pKey(), {});
  const saveDone = d => lsSet(pKey(), d);
  function resources(c) { const o = []; c.units.forEach((u, i) => u.r.forEach((r, j) => o.push(Object.assign({ id: r.rid ? 'r' + r.rid : c.code + '-' + i + '-' + j, u: i }, r)))); return o; }
  function totals(c, done) { let t = 0, d = 0; resources(c).forEach(r => { t += r.m; if (done[r.id]) d += r.m; }); return { t, d, pct: t ? Math.round(d / t * 100) : 0 }; }
  const fmt = m => [Math.floor(m / 60) ? Math.floor(m / 60) + 'h' : '', m % 60 ? (m % 60) + 'm' : ''].filter(Boolean).join(' ') || '0m';
  const byCode = code => COURSES.find(c => c.code === code);

  /* ===== SECTION START: JS - Live timetable ===== */
  /* ---------- Live timetable: 11 classes/week (Mon 3, Tue 2, Wed 3, Thu 2, Fri 1), 2-hour slots, East Africa Time ---------- */
  const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const SLOTS = [[8, 10], [11, 13], [14, 16]];
  const TT = { 1: ['CS101', 'MA101', 'BU101'], 2: ['CS201', 'MA201', null], 3: ['CS101', 'BU205', 'MA101'], 4: ['CS201', 'MA201', null], 5: ['BU101', null, null] };
  const pad = n => String(Math.floor(n)).padStart(2, '0') + ':' + String(Math.round((n % 1) * 60)).padStart(2, '0');   // 8.5 -> 08:30
  function nairobiNow() {
    const p = new Intl.DateTimeFormat('en-GB', { timeZone: TZ, weekday: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).formatToParts(new Date());
    const g = t => +p.find(x => x.type === t).value;
    return { d: DAYS.indexOf(p.find(x => x.type === 'weekday').value), h: g('hour') % 24 + g('minute') / 60 + g('second') / 3600 };
  }
  function liveNow() { const n = nairobiNow(), o = []; (TT[n.d] || []).forEach((c, i) => { if (c && n.h >= SLOTS[i][0] && n.h < SLOTS[i][1]) o.push(c); }); return o; }
  function nextClass() {
    const n = nairobiNow();
    for (let k = 0; k < 8; k++) { const d = (n.d + k) % 7; for (let i = 0; i < 3; i++) { const c = (TT[d] || [])[i]; if (c && (k > 0 || SLOTS[i][0] > n.h)) return { code: c, d, i, k, secs: (k * 24 + SLOTS[i][0] - n.h) * 3600 }; } }
    return null;
  }
  function renderLive(banner, box) {
    const n = nairobiNow(), live = liveNow(); banner.textContent = '';
    if (live.length) live.forEach(code => {
      const a = el('a', 'btn btn-live', 'LIVE NOW: ' + code + ' ' + byCode(code).title + ' - Join class'); a.prepend(el('span', 'dot'));
      a.href = MEET_URL; a.target = '_blank'; a.rel = 'noopener noreferrer'; banner.appendChild(a);
    });
    else { const nx = nextClass(); banner.appendChild(el('p', 'msg', nx ? 'No class is live right now. Next: ' + DAYS[nx.d] + ' ' + pad(SLOTS[nx.i][0]) + ' - ' + nx.code + ' ' + byCode(nx.code).title + '.' : 'No classes scheduled.')); }
    box.textContent = '';
    const t = el('table'); t.appendChild(el('caption', '', 'Weekly live timetable (East Africa Time): 11 classes'));
    const hr = el('tr'); ['Day'].concat(SLOTS.map(s => pad(s[0]) + ' - ' + pad(s[1]))).forEach(x => { const th = el('th', '', x); th.scope = 'col'; hr.appendChild(th); });
    const hd = el('thead'); hd.appendChild(hr); t.appendChild(hd); const tb = el('tbody');
    [1, 2, 3, 4, 5].forEach(d => {
      const tr = el('tr'), h = el('th', '', DAYS[d]); h.scope = 'row'; tr.appendChild(h);
      TT[d].forEach((code, i) => {
        const td = el('td', '', code ? code + ' ' + byCode(code).title : 'No class');
        if (code && d === n.d && n.h >= SLOTS[i][0] && n.h < SLOTS[i][1]) { td.className = 'is-live'; td.textContent = 'LIVE: ' + td.textContent; }
        tr.appendChild(td);
      }); tb.appendChild(tr);
    });
    t.appendChild(tb); box.appendChild(t); linkify(box);
  }

  /* ===== SECTION START: JS - Assessment calendar ===== */
  /* ---------- Assessment calendar (CATs and assignments) ---------- */
  const dstr = ms => new Intl.DateTimeFormat('en-GB', { timeZone: TZ, weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).format(ms);
  function renderAssess(box) {
    box.textContent = ''; const now = Date.now(); let nextDone = false;
    const t = el('table'); t.appendChild(el('caption', '', 'CATs and assignments (all courses). Semester starts ' + dstr(SEM_START) + '.'));
    const hr = el('tr'); ['Assessment', 'Details', 'Weight', 'Week', 'Due (17:00)', 'Status'].forEach(x => { const th = el('th', '', x); th.scope = 'col'; hr.appendChild(th); });
    const hd = el('thead'); hd.appendChild(hr); t.appendChild(hd); const tb = el('tbody');
    ASSESS.forEach(a => {
      const due = dueOf(a.w), past = due < now, tr = el('tr'); let st = past ? 'Done' : 'Upcoming', cls = 'badge badge--ok';
      if (!past && !nextDone) { st = 'Next'; nextDone = true; cls = 'badge badge--full'; }
      const th = el('th', '', a.n); th.scope = 'row'; tr.appendChild(th);
      [a.d, a.p + '%', 'Week ' + a.w, dstr(due)].forEach(x => tr.appendChild(el('td', '', x)));
      const td = el('td'); td.appendChild(el('span', cls, st)); tr.appendChild(td); tb.appendChild(tr);
    });
    t.appendChild(tb);
    const ft = el('tfoot'), fr = el('tr'), fh = el('th', '', 'Total'); fh.colSpan = 2; fh.scope = 'row';
    const fd = el('td', '', ASSESS.reduce((s, a) => s + a.p, 0) + '%'); const fe = el('td'); fe.colSpan = 3;
    fr.append(fh, fd, fe); ft.appendChild(fr); t.appendChild(ft); box.appendChild(t);
  }

  /* ===== SECTION START: JS - Link every course code ===== */
  /* ---------- Link every course code (CS101 etc.) to its course page, on any page ---------- */
  function linkify(root) {
    const nodes = [], w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT), re = /\b[A-Z]{2,4}\d{3}\b/;
    while (w.nextNode()) { const n = w.currentNode; if (!n.parentElement.closest('a,button,script,textarea,option,h1,label,input')) if (re.test(n.nodeValue)) nodes.push(n); }
    nodes.forEach(n => {
      const t = n.nodeValue, f = document.createDocumentFragment(); let last = 0;
      for (const m of t.matchAll(/\b[A-Z]{2,4}\d{3}\b/g)) {
        if (!byCode(m[0])) continue;
        f.append(t.slice(last, m.index)); const a = el('a', 'course-link', m[0]); a.href = '#/course/' + m[0]; f.append(a); last = m.index + m[0].length;
      }
      f.append(t.slice(last)); n.replaceWith(f);
    });
  }

  /* ===== SECTION START: JS - Course cards ===== */
  /* ---------- Course cards ---------- */
  function renderCards(box) {
    box.textContent = ''; const done = loadDone();
    COURSES.forEach(c => {
      const t = totals(c, done), a = el('a', 'card card--link reveal'); a.href = '#/course/' + c.code;
      a.append(el('p', 'card__code', c.code), el('h3', '', c.title), el('p', 'card__meta', 'Instructor: ' + c.who));
      const p = el('progress'); p.max = 100; p.value = t.pct; p.setAttribute('aria-label', c.title + ' progress ' + t.pct + ' percent');
      a.append(p, el('p', 'hint', t.pct + '% studied (' + fmt(t.d) + ' of ' + fmt(t.t) + ')'), el('p', 'card__go', 'Open course and units'));
      box.appendChild(a);
    });
  }

  /* ===== SECTION START: JS - Units + resource links with saved "Studied" ticks ===== */
  /* ---------- Units + resource links with saved "Studied" ticks (used on the course page AND the Resources page) ---------- */
  function renderUnits(c, root, onChange) {
    const done = loadDone();
    c.units.forEach((u, i) => {
      const s = el('section', 'card unit reveal'); s.appendChild(el('h3', '', u.t)); const ul = el('ul', 'res');
      resources(c).filter(r => r.u === i).forEach(r => {
        const li = el('li'), cb = el('input'); cb.type = 'checkbox'; cb.id = 'r-' + r.id; cb.checked = !!done[r.id];
        const lb = el('label', '', ' Studied'); lb.htmlFor = cb.id;
        const a = el('a', '', r.t); a.href = /^https?:\/\//i.test(r.url) ? r.url : '#';   // never javascript: links a.target = '_blank'; a.rel = 'noopener noreferrer';
        const set = v => { const d = loadDone(); if (v) d[r.id] = true; else delete d[r.id]; saveDone(d); if (apiOn && r.rid) api('progress', { rid: r.rid, studied: !!v }).then(res => { if (res && res.ok === false) { const d2 = loadDone(); delete d2[r.id]; saveDone(d2); cb.checked = false; onChange(); if (res.msg) alert(res.msg); } }).catch(() => { }); cb.checked = v; onChange(); };   // saved immediately
        a.addEventListener('click', () => { if (!LOCKS[r.rid]) set(true); });   // opening a resource marks it studied (unless the admin locked it)
        cb.addEventListener('change', () => set(cb.checked));
        li.append(a, el('span', 'badge ' + (r.k === 'Video' ? 'badge--full' : 'badge--ok'), r.k + ' | ' + fmt(r.m)), cb, lb);
        if (LOCKS[r.rid]) { cb.disabled = true; cb.checked = false; li.appendChild(el('span', 'badge badge--full', 'Locked by lecturer: ' + LOCKS[r.rid])); }   // the admin removed this tick
        ul.appendChild(li);
      });
      s.appendChild(ul); root.appendChild(s);
    });
  }
  function bullets(arr, who) { const ul = el('ul'); arr.forEach(x => ul.appendChild(el('li', '', x.replace('{who}', who)))); return ul; }

  /* ===== SECTION START: JS - Course page ===== */
  /* ---------- Course page ---------- */
  function renderCourse(arg) {
    const root = document.getElementById('course-root'); root.textContent = '';
    const c = byCode(arg);   // looked up in our own list: URL text is never injected
    if (!c) { root.appendChild(el('p', 'msg', 'Course not found. Choose one from the Courses page.')); return; }
    document.title = c.code + ' ' + c.title + ' | EduSphere Portal'; root.append(el('h1', '', c.code + ': ' + c.title));
    const info = el('div', 'card reveal'); info.appendChild(el('h2', '', 'Instructor: ' + c.who));
    const m = el('a', '', c.mail); m.href = 'mailto:' + c.mail; const tl = el('a', '', c.tel); tl.href = 'tel:' + c.tel.replace(/\s/g, '');
    const l1 = el('p'); l1.append('Email: ', m); const l2 = el('p'); l2.append('Phone: ', tl); info.append(l1, l2, el('p', '', c.credits + ' credit hours'));
    const sum = el('p', 'hint'), bar = el('progress'); bar.max = 100; bar.setAttribute('aria-label', 'Course progress'); info.append(bar, sum); root.appendChild(info);
    const about = el('div', 'grid grid--2');
    const b1 = el('section', 'card reveal'); b1.append(el('h2', '', 'Why this course'), el('p', '', c.why), el('p', '', 'You will gain skills in ' + c.gain));
    const b2 = el('section', 'card reveal'); b2.append(el('h2', '', 'What is expected of you'), bullets(EXPECT, c.who));
    const b3 = el('section', 'card reveal'); b3.append(el('h2', '', 'How your lecturer helps'), bullets(HELP, c.who));
    const b4 = el('section', 'card reveal'); b4.appendChild(el('h2', '', 'How you are assessed'));
    const ul = el('ul'); ASSESS.forEach(a => ul.appendChild(el('li', '', a.n + ' (week ' + a.w + '): ' + a.p + '%. ' + a.d + '.'))); b4.append(ul, el('p', 'hint', 'Total 100%: CATs 30%, assignments 10%, final exam 60%. See exact dates on the Dashboard.'));
    about.append(b1, b2, b3, b4); root.appendChild(about);
    root.appendChild(el('h2', '', 'Course outline and study resources'));
    const refresh = () => { const t = totals(c, loadDone()); bar.value = t.pct; sum.textContent = t.pct + '% studied: ' + fmt(t.d) + ' of ' + fmt(t.t) + ' of resources.'; };
    renderUnits(c, root, refresh); refresh();
  }

  /* ===== SECTION START: JS - Resources page ===== */
  /* ---------- Resources page: every notes/video link, grouped by course ---------- */
  function renderResources() {
    const root = document.getElementById('res-root'), jump = document.getElementById('res-jump'); root.textContent = ''; jump.textContent = '';
    COURSES.forEach(c => {
      const li = el('li'), a = el('a', '', c.code); a.href = '#/resources'; a.addEventListener('click', e => { e.preventDefault(); document.getElementById('res-' + c.code).scrollIntoView({ behavior: 'smooth' }); }); li.appendChild(a); jump.appendChild(li);
      const sec = el('section', 'section'); sec.id = 'res-' + c.code;
      const h = el('h2', '', c.code + ': ' + c.title), go = el('a', '', 'Open full course page'); go.href = '#/course/' + c.code;
      const bar = el('progress'); bar.max = 100; bar.setAttribute('aria-label', c.title + ' progress'); const sum = el('p', 'hint');
      const refresh = () => { const t = totals(c, loadDone()); bar.value = t.pct; sum.textContent = t.pct + '% studied (' + fmt(t.d) + ' of ' + fmt(t.t) + ')'; };
      const p = el('p'); p.append(go); sec.append(h, p, bar, sum); renderUnits(c, sec, refresh); refresh(); root.appendChild(sec);
    });
  }

  /* ===== SECTION START: JS - My Progress ===== */
  /* ---------- My Progress: pie charts (CSS conic-gradient) and a bar graph, % of study time ---------- */
  function pie(pct, label) {
    const w = el('div', 'pie-wrap'), p = el('div', 'pie'); p.style.setProperty('--p', 0); p.setAttribute('role', 'img'); p.setAttribute('aria-label', label + ': ' + pct + ' percent studied');
    p.appendChild(el('span', 'pie__v', pct + '%')); requestAnimationFrame(() => requestAnimationFrame(() => p.style.setProperty('--p', pct))); w.appendChild(p); return w;
  }
  function renderProgress() {
    ['overall', 'pies', 'bars'].forEach(i => { document.getElementById(i).textContent = ''; });
    const done = loadDone(), all = COURSES.map(c => Object.assign({ c }, totals(c, done)));
    const T = all.reduce((a, x) => a + x.t, 0), D = all.reduce((a, x) => a + x.d, 0), P = T ? Math.round(D / T * 100) : 0;
    document.getElementById('overall').append(pie(P, 'Overall'), el('p', 'hint', 'Overall: ' + fmt(D) + ' studied of ' + fmt(T) + ' (' + P + '%). Remaining: ' + fmt(T - D) + '.'));
    const g = document.getElementById('pies');
    all.forEach(x => { const d = el('div', 'card reveal'); d.append(el('h3', '', x.c.code), pie(x.pct, x.c.title), el('p', 'hint', x.c.title + ': ' + fmt(x.d) + ' of ' + fmt(x.t))); g.appendChild(d); });
    const bars = document.getElementById('bars');
    all.forEach(x => { const col = el('div', 'bar'), f = el('div', 'bar__fill'); f.style.setProperty('--h', 0); col.append(el('span', 'bar__v', x.pct + '%'), f, el('span', 'bar__l', x.c.code)); bars.appendChild(col); requestAnimationFrame(() => requestAnimationFrame(() => f.style.setProperty('--h', x.pct))); });
  }

  /* ===== SECTION START: JS - School news carousel ===== */
  /* ---------- School news carousel: shows one update at a time and animates to the next ---------- */
  let newsTimer = null, newsPaused = false;
  function renderNews(box) {
    const wrap = box.parentNode, old = wrap.querySelector('.news-ctl'); if (old) old.remove(); box.textContent = ''; clearInterval(newsTimer);
    const slides = NEWS.map((n, i) => { const a = el('article', 'slide' + (i ? '' : ' is-on')); a.append(el('span', 'badge badge--full', 'NEW | ' + n.d), el('h3', '', n.t), el('p', '', n.b)); box.appendChild(a); return a; });
    const ctl = el('div', 'news-ctl'), prev = el('button', 'btn btn-secondary', 'Previous'), next = el('button', 'btn btn-secondary', 'Next'), dots = el('div', 'dots');
    prev.type = next.type = 'button'; let i = 0;
    const ds = slides.map((s, k) => { const d = el('button', 'dotbtn'); d.type = 'button'; d.setAttribute('aria-label', 'Show update ' + (k + 1)); d.addEventListener('click', () => show(k)); dots.appendChild(d); return d; });
    function show(k) { slides[i].classList.remove('is-on'); ds[i].classList.remove('on'); i = (k + slides.length) % slides.length; slides[i].classList.add('is-on'); ds[i].classList.add('on'); }
    prev.addEventListener('click', () => show(i - 1)); next.addEventListener('click', () => show(i + 1)); ctl.append(prev, dots, next); wrap.appendChild(ctl); ds[0].classList.add('on');
    if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {   // no autoplay for reduced-motion users
      const pause = v => () => { newsPaused = v; };
      wrap.onmouseenter = pause(true); wrap.onmouseleave = pause(false); wrap.onfocusin = pause(true); wrap.onfocusout = pause(false);
      newsTimer = setInterval(() => { if (!newsPaused && !wrap.closest('.view').hidden) show(i + 1); }, 5000);
    }
  }

  /* ===== SECTION START: JS - Session bar ===== */
  /* ---------- Session bar: last seen, time in system, countdown to next live class and next deadline ---------- */
  const dur = s => { s = Math.max(0, Math.floor(s)); const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), x = s % 60, p = v => String(v).padStart(2, '0'); return (d ? d + 'd ' : '') + p(h) + ':' + p(m) + ':' + p(x); };
  let statusOn = false;
  function refreshLast() {
    const s = lsGet(SESSION_KEY, null);
    document.getElementById('sb-last').textContent = s && s.prev ? new Intl.DateTimeFormat('en-GB', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }).format(s.prev) + ' EAT' : 'First visit';
  }
  function startStatus() {
    if (statusOn) return; statusOn = true;
    const tm = document.getElementById('sb-time'), cc = document.getElementById('sb-class'), cd = document.getElementById('sb-due');
    const tick = () => {
      if (!getUser()) return; const s = lsGet(SESSION_KEY, null), start = s ? s.start : Date.now();
      tm.textContent = dur((Date.now() - start) / 1000);
      const live = liveNow(), nx = nextClass(); cc.textContent = live.length ? 'LIVE now: ' + live.join(', ') : nx ? nx.code + ' in ' + dur(nx.secs) : 'None scheduled';
      const a = ASSESS.find(x => dueOf(x.w) > Date.now()); cd.textContent = a ? a.n + ' in ' + dur((dueOf(a.w) - Date.now()) / 1000) : 'No deadlines left';
    };
    tick(); setInterval(tick, 1000); setInterval(touch, 15000); window.addEventListener('pagehide', touch);
  }

  /* ===== SECTION START: JS - Search ===== */
  /* ---------- Search: matches every typed word against courses, units, resources and pages ---------- */
  const PAGES = [['Dashboard', '#/dashboard', 'home overview welcome news updates countdown'], ['All courses', '#/courses', 'courses units subjects modules'],
    ['Finances, fees, payment, terms and policy', '#/finances', 'fees payment tuition kes money cost finance mpesa refund terms policy instalments'], ['My progress', '#/progress', 'progress charts graph pie hours percentage study tracker'],
    ['Live classes and timetable', '#/live', 'live timetable schedule meet online lecture class'], ['CATs and assignments calendar', '#/live', 'cat cats assignment assignments exam test deadline due dates'],
    ['Contact and support', '#/support', 'feedback help support form issue'], ['Study resources: all notes and videos', '#/resources', 'resources notes videos links library articles'],
    ['Campus map and video', '#/campus', 'map campus video location'], ['Apply for admission', '#/apply', 'admission application join enrol apply']];
  function buildIndex() {
    const ix = PAGES.map(p => ({ t: p[0], u: p[1], s: (p[0] + ' ' + p[2]).toLowerCase() }));
    COURSES.forEach(c => {
      ix.push({ t: c.code + ' ' + c.title, u: '#/course/' + c.code, s: (c.code + ' ' + c.title + ' ' + c.who + ' ' + c.kw).toLowerCase() });
      c.units.forEach(u => { ix.push({ t: u.t + ' (' + c.code + ')', u: '#/course/' + c.code, s: (u.t + ' ' + c.code + ' ' + c.title + ' ' + c.kw).toLowerCase() });
        u.r.forEach(r => ix.push({ t: r.k + ': ' + r.t + ' (' + c.code + ')', u: '#/course/' + c.code, s: (r.k + ' ' + r.t + ' ' + c.code + ' ' + c.title + ' ' + c.kw).toLowerCase() })); });
    });
    return ix;
  }
  function initSearch() {
    const q = document.getElementById('q'), list = document.getElementById('results'); if (!q) return; const ix = buildIndex();
    q.addEventListener('input', () => {
      const words = q.value.toLowerCase().split(/\s+/).filter(Boolean); list.textContent = '';
      if (!words.length) { list.hidden = true; return; }
      const hits = ix.filter(i => words.every(w => i.s.includes(w))).slice(0, 8);
      if (!hits.length) list.appendChild(el('li', 'results__none', 'No matches. Try "fees", "calculus" or "cat".'));
      hits.forEach(h => { const li = el('li'), a = el('a', '', h.t); a.href = h.u; li.appendChild(a); list.appendChild(li); }); list.hidden = false;
    });
    document.addEventListener('click', e => { if (!e.target.closest('.search')) list.hidden = true; });
    q.addEventListener('keydown', e => { if (e.key === 'Escape') list.hidden = true; });
  }

  /* ===== SECTION START: JS - Message someone ===== */
  /* ---------- Message someone: Gmail, Outlook or WhatsApp ---------- */
  function initMail() {
    const dlg = document.getElementById('mail-dialog'), btn = document.getElementById('mail-btn'); if (!dlg || !btn) return;
    const to = document.getElementById('mail-to'), wa = document.getElementById('mail-wa'), txt = document.getElementById('mail-sub'), msg = document.getElementById('mail-msg'); attachSanitizer(txt);
    const bad = (f, m) => { msg.textContent = m; f.setAttribute('aria-invalid', 'true'); f.focus(); };
    btn.addEventListener('click', () => { msg.textContent = ''; dlg.showModal ? dlg.showModal() : dlg.setAttribute('open', ''); to.focus(); });
    document.getElementById('mail-cancel').addEventListener('click', () => dlg.close());
    const openMail = base => {
      to.removeAttribute('aria-invalid'); const email = to.value.trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return bad(to, 'Enter a valid email address, e.g. name@school.ac.ke.');
      window.open(base.replace('{to}', encodeURIComponent(email)).replace('{su}', encodeURIComponent('Message from EduSphere')).replace('{body}', encodeURIComponent(txt.value)), '_blank', 'noopener,noreferrer'); dlg.close();
    };
    document.getElementById('mail-gmail').addEventListener('click', () => openMail('https://mail.google.com/mail/?view=cm&fs=1&to={to}&su={su}&body={body}'));
    document.getElementById('mail-outlook').addEventListener('click', () => openMail('https://outlook.live.com/mail/0/deeplink/compose?to={to}&subject={su}&body={body}'));
    document.getElementById('mail-whatsapp').addEventListener('click', () => {
      wa.removeAttribute('aria-invalid'); let n = wa.value.replace(/[^\d]/g, ''); if (/^0\d{9}$/.test(n)) n = '254' + n.slice(1);   // 07xx... becomes 2547xx...
      if (n.length < 9 || n.length > 15) return bad(wa, 'Enter the WhatsApp number with country code, e.g. 254712345678.');
      window.open('https://wa.me/' + n + '?text=' + encodeURIComponent(txt.value), '_blank', 'noopener,noreferrer'); dlg.close();
    });
  }

  function reveal() {
    const items = document.querySelectorAll('.reveal:not(.in)');
    if (!('IntersectionObserver' in window)) { items.forEach(i => i.classList.add('in')); return; }
    const io = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }), { threshold: .1 });
    items.forEach((i, n) => { i.style.transitionDelay = Math.min(n % 6, 5) * 60 + 'ms'; io.observe(i); });
  }

  /* ===== SECTION START: JS - Router ===== */
  /* ---------- Router: each page is a <section class="view">, chosen by the URL hash (#/dashboard, #/course/CS101 ...) ---------- */
  const ROUTES = {
    home: { v: 'home', t: 'Welcome', pub: 1 }, login: { v: 'home', t: 'Log in', pub: 1, scroll: 'login' }, about: { v: 'home', t: 'About us', pub: 1, scroll: 'about' }, contacts: { v: 'home', t: 'Contacts', pub: 1, scroll: 'contacts' },
    apply: { v: 'apply', t: 'Apply', pub: 1 }, dashboard: { v: 'dashboard', t: 'Dashboard' }, live: { v: 'dashboard', t: 'Live classes', scroll: 'live' }, courses: { v: 'courses', t: 'Courses' },
    course: { v: 'course', t: 'Course' }, resources: { v: 'resources', t: 'Study Resources' }, progress: { v: 'progress', t: 'My Progress' }, finances: { v: 'finances', t: 'Finances' },
    support: { v: 'support', t: 'Support' }, campus: { v: 'campus', t: 'Campus map and video' } };
  const go = h => { if (location.hash === h) route(); else location.hash = h; };
  function applyAuthUI() {   // switches the page chrome between logged-out and logged-in
    const u = getUser(), $ = id => document.getElementById(id);
    $('nav-public').hidden = !!u; $('nav-private').hidden = !u; $('search-wrap').hidden = !u; $('statusbar').hidden = !u; $('logout-btn').hidden = !u;
    $('login-status').textContent = u ? 'Signed in as ' + u : 'Not signed in'; $('brand').href = u ? '#/dashboard' : '#/home';
    $('login-form').hidden = !!u; $('auth-tabs').hidden = !!u; $('signed-in').hidden = !u; $('signed-name').textContent = u || '';
    if (u) { refreshLast(); startStatus(); }
  }
  function route() {
    const parts = (location.hash || '#/home').replace(/^#\/?/, '').split('/'), name = parts[0], arg = parts[1], r = ROUTES[name];
    if (!r) return go('#/home');
    if (!r.pub && !getUser()) return go('#/home');   // protected page while logged out: back to login (client-side convenience only)
    document.querySelectorAll('.view').forEach(v => { v.hidden = v.dataset.view !== r.v; });
    document.title = (name === 'course' && byCode(arg) ? arg + ' course' : r.t) + ' | EduSphere Portal';
    document.querySelectorAll('[data-r]').forEach(a => { if (a.dataset.r === name || (name === 'course' && a.dataset.r === 'courses')) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
    document.getElementById('primary-nav').classList.remove('is-open'); document.getElementById('nav-toggle').setAttribute('aria-expanded', 'false');
    const user = getUser();
    if (apiOn && user) api('log', { event: 'page_view', route: name + (arg ? '/' + arg : '') }).catch(() => { });   // shows in the admin activity log
    if (r.v === 'dashboard') {
      document.getElementById('welcome-name').textContent = 'Welcome, ' + (user || 'student');
      renderLive(document.getElementById('live-banner'), document.getElementById('live-table')); renderAssess(document.getElementById('assess-table'));
      renderCards(document.getElementById('dash-grid')); renderNews(document.getElementById('news-box'));
    }
    if (r.v === 'courses') renderCards(document.getElementById('course-grid'));
    if (r.v === 'course') renderCourse(arg);
    if (r.v === 'resources') renderResources();
    if (r.v === 'progress') renderProgress();
    if (r.v === 'finances') renderFees();
    const main = document.getElementById('main-content'); linkify(main); reveal();
    const t = r.scroll && document.getElementById(r.scroll); if (t) t.scrollIntoView(); else window.scrollTo(0, 0);
    main.focus({ preventScroll: true });
  }

  /* ===== SECTION START: JS - Init ===== */
  /* ---------- Init ---------- */
  async function init() {
    await boot();   // load database content and restore the server session first
    const fixedTop = document.getElementById('sticky-top');   // keep --sticky-h equal to the fixed header's height
    if (fixedTop) { const setH = () => document.documentElement.style.setProperty('--sticky-h', fixedTop.offsetHeight + 'px'); setH(); if ('ResizeObserver' in window) new ResizeObserver(setH).observe(fixedTop); else window.addEventListener('resize', setH); }
    document.getElementById('logout-btn').addEventListener('click', () => { touch(); if (apiOn) api('logout', {}).catch(() => { }); clearUser(); if (window.EduSphere.resetLogin) window.EduSphere.resetLogin(); applyAuthUI(); go('#/home'); });
    const tg = document.getElementById('nav-toggle'), nav = document.getElementById('primary-nav');
    tg.addEventListener('click', () => { const o = nav.classList.toggle('is-open'); tg.setAttribute('aria-expanded', String(o)); });
    initSearch(); initMail(); applyAuthUI(); if (getUser()) touch();
    setInterval(() => {   // keep live banner, timetable and the nav dot fresh every 30 seconds
      const dot = document.getElementById('live-dot'); if (dot) dot.hidden = !liveNow().length;
      if (getUser() && !document.querySelector('[data-view="dashboard"]').hidden) renderLive(document.getElementById('live-banner'), document.getElementById('live-table'));
    }, 30000);
    const dot = document.getElementById('live-dot'); if (dot) dot.hidden = !liveNow().length;
    window.addEventListener('hashchange', route); route();
  }
  window.EduSphere = { sanitizeInput, attachSanitizer, getUser, setUser, clearUser, strongPassword, register, login, el, api, apiOn: () => apiOn, afterLogin: () => { applyAuthUI(); go('#/dashboard'); } };
  document.addEventListener('DOMContentLoaded', init);
})();

  


/* ===== SECTION START: JS - Login page (tabs, password eye, confirm password) ===== */
(function () {
  'use strict';
  const ES = window.EduSphere, $ = id => document.getElementById(id);
  let mode = 'login';
  const box = $('login-errors'), form = $('login-form');
  ES.attachSanitizer($('user'));   // passwords are NOT sanitized: they must keep every character

  /* Eye buttons: toggle input type password <-> text, update aria-label and aria-pressed, keep the caret */
  const eyes = document.querySelectorAll('.eye');
  function resetEyes() { eyes.forEach(b => { $(b.dataset.for).type = 'password'; b.setAttribute('aria-pressed', 'false'); b.setAttribute('aria-label', 'Show password'); }); }
  eyes.forEach(b => b.addEventListener('click', () => {
    const inp = $(b.dataset.for), reveal = inp.type === 'password', s = inp.selectionStart, e = inp.selectionEnd;
    inp.type = reveal ? 'text' : 'password'; b.setAttribute('aria-pressed', String(reveal)); b.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
    inp.focus(); try { inp.setSelectionRange(s, e); } catch (x) { }
  }));

  function strength(p) {   // text label as well as colour
    const ok = ES.strongPassword(p); if (!ok) return 'Strength: weak';
    return p.length >= 10 && /[A-Z]/.test(p) && /[^A-Za-z0-9]/.test(p) ? 'Strength: strong' : p.length >= 8 ? 'Strength: okay' : 'Strength: weak (use 8+ characters)';
  }
  function update() {
    const p = $('pass').value, p2 = $('pass2').value, st = $('pw-strength'), m = $('pw-match');
    st.hidden = mode !== 'create' || !p; st.textContent = strength(p);
    if (mode !== 'create' || !p2) { m.textContent = ''; m.className = 'hint'; return; }
    const same = p === p2; m.textContent = same ? 'Passwords match' : 'Passwords do not match'; m.className = 'hint ' + (same ? 'match-ok' : 'match-bad');
  }
  function setMode(next) {
    mode = next; const create = next === 'create';
    $('tab-login').setAttribute('aria-selected', String(!create)); $('tab-create').setAttribute('aria-selected', String(create));
    $('tab-login').tabIndex = create ? -1 : 0; $('tab-create').tabIndex = create ? 0 : -1;
    $('auth-panel').setAttribute('aria-labelledby', create ? 'tab-create' : 'tab-login');
    $('login-h').textContent = create ? 'Create your account' : 'Student login'; $('auth-submit').textContent = create ? 'Create account' : 'Log in';
    $('confirm-field').hidden = !create; $('pass2').required = create; $('pass').autocomplete = create ? 'new-password' : 'current-password';
    box.hidden = true; resetEyes(); update();
  }
  $('tab-login').addEventListener('click', () => setMode('login')); $('tab-create').addEventListener('click', () => setMode('create'));
  document.querySelector('.tabs').addEventListener('keydown', e => { if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { const c = mode === 'login' ? 'create' : 'login'; setMode(c); $('tab-' + c).focus(); } });
  $('pass').addEventListener('input', update); $('pass2').addEventListener('input', update);

  function show(errs) {
    box.textContent = ''; const h = document.createElement('h2'); h.textContent = 'Please fix the following:'; const ul = document.createElement('ul');
    errs.forEach(t => { const li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });   // textContent, never innerHTML
    box.append(h, ul); box.hidden = false; box.focus();
  }
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const u = $('user'), p = $('pass'), p2 = $('pass2'), errs = []; [u, p, p2].forEach(x => x.removeAttribute('aria-invalid'));
    const name = u.value.trim();
    if (!/^[A-Za-z][A-Za-z0-9 ._-]{2,29}$/.test(name)) { errs.push('Username must start with a letter and be 3 to 30 characters (letters, numbers, space, . _ -).'); u.setAttribute('aria-invalid', 'true'); }
    if (!ES.strongPassword(p.value)) { errs.push('Password needs at least 6 characters with letters AND at least one number, e.g. Mary2026.'); p.setAttribute('aria-invalid', 'true'); }
    if (mode === 'create' && p.value !== p2.value) { errs.push('Passwords do not match. Retype the confirmation (use the eye button to check).'); p2.setAttribute('aria-invalid', 'true'); }
    if (errs.length) return show(errs);
    const clean = ES.sanitizeInput(name, true), r = mode === 'create' ? await ES.register(clean, p.value) : await ES.login(clean, p.value);
    if (!r.ok) { (mode === 'create' ? u : p).setAttribute('aria-invalid', 'true'); return show([r.msg]); }
    ES.resetLogin(); ES.afterLogin();
  });
  ES.resetLogin = () => { form.reset(); setMode('login'); box.hidden = true; };   // clears typed passwords on login and logout
})();

/* ===== SECTION START: JS - Apply form ===== */
(function () {

    const ES = window.EduSphere, f = document.getElementById('app-form'), box = document.getElementById('app-errors'), ok = document.getElementById('app-ok');
    ['a-name', 'a-why'].forEach(id => ES.attachSanitizer(document.getElementById(id)));
    f.addEventListener('submit', async e => {
      e.preventDefault(); const errs = [], g = id => document.getElementById(id);
      f.querySelectorAll('[aria-invalid]').forEach(x => x.removeAttribute('aria-invalid'));
      const bad = (id, m) => { errs.push(m); g(id).setAttribute('aria-invalid', 'true'); };
      if (!g('a-name').value.trim()) bad('a-name', 'Full name is required.');
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(g('a-email').value.trim())) bad('a-email', 'Enter a valid email address.');
      if (!/^[+0-9 ()-]{9,20}$/.test(g('a-tel').value.trim())) bad('a-tel', 'Enter a valid phone number.');
      if (!g('a-prog').value) bad('a-prog', 'Choose a programme.');
      if (errs.length) {
        box.textContent = ''; const ul = document.createElement('ul');
        errs.forEach(t => { const li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });
        box.appendChild(ul); box.hidden = false; ok.textContent = ''; box.focus(); return;
      }
      box.hidden = true;
      if (ES.apiOn()) {   // saved on the server: the admin sees it in admin.php
        const r = await ES.api('apply', { name: g('a-name').value.trim(), email: g('a-email').value.trim(), phone: g('a-tel').value.trim(), programme: g('a-prog').value, why: g('a-why').value.trim() });
        if (!r.ok) { box.textContent = r.msg || 'Could not send the application. Try again.'; box.hidden = false; box.focus(); return; }
        ok.textContent = 'Thank you, ' + g('a-name').value.trim() + '. Your application has been received.';
      } else ok.textContent = 'Thank you, ' + g('a-name').value.trim() + '. Your application has been recorded (demo).';
      f.reset();
    });
})();

/* ===== SECTION START: JS - Contact form ===== */
(function () {

    const ES = window.EduSphere, form = document.getElementById('contact-form'), errBox = document.getElementById('form-errors');
    const nameEl = document.getElementById('name'), comment = document.getElementById('comment');
    const MAX = 5 * 1024 * 1024, OK = ['pdf', 'png', 'jpg', 'jpeg'];
    [nameEl, comment].forEach(ES.attachSanitizer);
    comment.addEventListener('input', () => { document.getElementById('count').textContent = comment.value.length + ' / 1000 characters'; });
    form.onsubmit = async function (event) {
      const errors = [], bad = [];
      form.querySelectorAll('[aria-invalid]').forEach(f => f.removeAttribute('aria-invalid'));
      nameEl.value = ES.sanitizeInput(nameEl.value, true); comment.value = ES.sanitizeInput(comment.value, true);
      const email = form.email.value.trim(), at = email.indexOf('@');
      if (!nameEl.value) { errors.push('Name is required.'); bad.push(nameEl); }
      if (!email) { errors.push('Email is required.'); bad.push(form.email); }
      else if (at < 1 || email.indexOf('.', at) < at + 2 || email.endsWith('.')) { errors.push('Email must contain "@" and a domain with a dot.'); bad.push(form.email); }
      if (!form.category.value) { errors.push('Select a department or issue category.'); bad.push(form.category); }
      const file = form.upload.files[0];
      if (file) {
        const ext = file.name.split('.').pop().toLowerCase();
        if (!OK.includes(ext)) { errors.push('File type not allowed. Use PDF, PNG or JPG.'); bad.push(form.upload); }
        else if (file.size > MAX) { errors.push('File is larger than 5 MB.'); bad.push(form.upload); }
      }
      if (!ES.getUser()) errors.push('You are not logged in. Log in from the home page first.');
      if (errors.length) {
        event.preventDefault();
        errBox.textContent = ''; const h = document.createElement('h2'); h.textContent = 'Please fix the following:'; const ul = document.createElement('ul');
        errors.forEach(t => { const li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });
        errBox.append(h, ul); errBox.hidden = false; bad.forEach(f => f.setAttribute('aria-invalid', 'true'));
        errBox.scrollIntoView({ behavior: 'smooth', block: 'center' }); errBox.focus(); return false;
      }
      errBox.hidden = true; event.preventDefault();   // stop the normal page submit, then send with fetch
      if (ES.apiOn()) {   // saved on the server: the admin sees it in admin.php
        const r = await ES.api('message', { name: nameEl.value, email: form.email.value.trim(), category: form.category.value, comment: comment.value });
        if (!r.ok) { errBox.textContent = r.msg || 'Could not send your message. Try again.'; errBox.hidden = false; errBox.focus(); return false; }
      }
      alert('Login Successful & Form Submitted Successfully!'); form.reset();
      document.getElementById('count').textContent = '0 / 1000 characters'; return false;
    };
})();
  