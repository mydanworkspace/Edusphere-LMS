<?php
declare(strict_types=1);
/* ============================================================
   api.php - JSON endpoints used by index.html
   GET  ?action=data      public course, news, timetable, fee and settings data
   POST ?action=register | login | logout | me | ping | progress | apply | message | log
   Every database query uses prepared statements. Every POST must come from the same site.
   ============================================================ */
define('IS_API', true);
require __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
start_session();

function out(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400): void
{
    out(['ok' => false, 'msg' => $msg], $code);
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

/* Same-origin check for POST: a custom header forces browsers to run a CORS check, and Origin must match this host.
   This stops other websites from making a logged-in student's browser post to us (CSRF). */
$in = [];
if ($method === 'POST') {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
        fail('Bad request', 403);
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $oh = (string)parse_url($origin, PHP_URL_HOST);
        $op = parse_url($origin, PHP_URL_PORT);
        if (strcasecmp($oh . ($op ? ':' . $op : ''), (string)($_SERVER['HTTP_HOST'] ?? '')) !== 0) {
            fail('Bad origin', 403);
        }
    }
    $raw = file_get_contents('php://input', false, null, 0, 20000);
    $in = json_decode($raw ?: '[]', true);
    if (!is_array($in)) {
        fail('Bad JSON');
    }
}
/* The portal encodes < > & quotes while typing (extra XSS protection in the browser). Decode here so the database holds plain text;
   every place that shows it (admin.php, the portal) escapes it again on output. */
$str = static fn(string $k, int $max): string => mb_substr(trim(html_entity_decode((string)($in[$k] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, $max);
$sid = (int)($_SESSION['student_id'] ?? 0);
$sname = (string)($_SESSION['student_name'] ?? '');
/* A student the admin blocked or deleted loses access immediately, even with an open session. */
if ($sid && $method === 'POST' && $action !== 'logout') {
    $chk = pdo()->prepare('SELECT status, pw_version FROM students WHERE id = ?');
    $chk->execute([$sid]);
    $cr = $chk->fetch();
    if (!$cr || $cr['status'] !== 'active' || (int)$cr['pw_version'] !== (int)($_SESSION['pw_version'] ?? 0)) {   // blocked, deleted, or password/username reset by the admin
        unset($_SESSION['student_id'], $_SESSION['student_name']);
        $sid = 0; $sname = '';
    }
}

function student_progress(int $id): array
{
    $st = pdo()->prepare('SELECT resource_id FROM progress WHERE student_id = ?');
    $st->execute([$id]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
/* Resources the admin removed from this student's progress and locked: [resource id => reason] */
function student_locks(int $id)   // returns an array, or an empty object so JSON shows {} instead of []
{
    $st = pdo()->prepare('SELECT resource_id, reason FROM progress_revoked WHERE student_id = ?');
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: new stdClass();
}
function valid_password(string $p): bool
{
    return strlen($p) >= 6 && strlen($p) <= 64 && preg_match('/[A-Za-z]/', $p) && preg_match('/\d/', $p);
}
const USER_RE = '/^[A-Za-z][A-Za-z0-9 ._-]{2,29}$/';

switch ($action) {

    /* ---------------- public data for the portal ---------------- */
    case 'data':
        $pdo = pdo();
        $courses = [];
        foreach ($pdo->query('SELECT * FROM courses ORDER BY sort_order, code') as $c) {
            $courses[$c['id']] = ['code' => $c['code'], 'title' => $c['title'], 'who' => $c['instructor'], 'mail' => $c['email'], 'tel' => $c['phone'],
                'credits' => (int)$c['credits'], 'kw' => $c['keywords'], 'why' => $c['why'], 'gain' => $c['gain'], 'units' => []];
        }
        $units = [];
        foreach ($pdo->query('SELECT * FROM units ORDER BY sort_order, id') as $u) {
            if (isset($courses[$u['course_id']])) {
                $courses[$u['course_id']]['units'][] = ['t' => $u['title'], 'r' => []];
                $units[$u['id']] = [$u['course_id'], count($courses[$u['course_id']]['units']) - 1];
            }
        }
        foreach ($pdo->query('SELECT * FROM resources ORDER BY sort_order, id') as $r) {
            if (isset($units[$r['unit_id']])) {
                [$cid, $ui] = $units[$r['unit_id']];
                $courses[$cid]['units'][$ui]['r'][] = ['rid' => (int)$r['id'], 't' => $r['title'], 'url' => $r['url'], 'm' => (int)$r['minutes'], 'k' => $r['kind']];
            }
        }
        $news = [];
        foreach ($pdo->query('SELECT * FROM news WHERE active = 1 ORDER BY posted_on DESC, id DESC LIMIT 10') as $n) {
            $news[] = ['d' => date('j M Y', strtotime($n['posted_on'])), 't' => $n['title'], 'b' => $n['body']];
        }
        $assess = [];
        foreach ($pdo->query('SELECT * FROM assessments ORDER BY week, id') as $a) {
            $assess[] = ['n' => $a['name'], 'w' => (int)$a['week'], 'p' => (int)$a['weight'], 'd' => $a['details']];
        }
        $tt = [1 => [null, null, null], 2 => [null, null, null], 3 => [null, null, null], 4 => [null, null, null], 5 => [null, null, null]];
        foreach ($pdo->query('SELECT t.day, t.slot, c.code FROM timetable t LEFT JOIN courses c ON c.id = t.course_id') as $t) {
            if ($t['day'] >= 1 && $t['day'] <= 5 && $t['slot'] >= 1 && $t['slot'] <= 3) {
                $tt[(int)$t['day']][(int)$t['slot'] - 1] = $t['code'];
            }
        }
        $fees = [];
        foreach ($pdo->query('SELECT * FROM fees ORDER BY category, sort_order, id') as $f) {
            $fees[] = ['cat' => $f['category'], 'mod' => $f['module'], 'cr' => (int)$f['credits'], 'fee' => (int)$f['fee'], 'st' => $f['status']];
        }
        $slots = [];
        foreach ([1, 2, 3] as $n) {   // editable class times, stored as "08:00-10:00"
            $parts = explode('-', setting('slot_' . $n, ['', '08:00-10:00', '11:00-13:00', '14:00-16:00'][$n]));
            $h = static function (string $t): float { [$hh, $mm] = array_pad(explode(':', trim($t)), 2, '0'); return (int)$hh + (int)$mm / 60; };
            $slots[] = [$h($parts[0] ?? '8'), $h($parts[1] ?? '10')];
        }
        out(['ok' => true, 'slots' => $slots, 'courses' => array_values($courses), 'news' => $news, 'assess' => $assess, 'tt' => $tt, 'fees' => $fees,
            'semStart' => setting('semester_start', '2026-10-19') . 'T00:00:00+03:00', 'meet' => setting('meet_url', 'https://meet.google.com/'),
            'whatsapp' => preg_replace('/\D/', '', setting('whatsapp', '254700000000'))]);

    /* ---------------- accounts ---------------- */
    case 'register':
        $name = $str('username', 30);
        $pw = (string)($in['password'] ?? '');
        if (!preg_match(USER_RE, $name)) {
            fail('Username must start with a letter and be 3 to 30 characters (letters, numbers, space, . _ -).');
        }
        if (!valid_password($pw)) {
            fail('Password needs 6 to 64 characters with letters AND at least one number.');
        }
        if (recent_count('student_register', 60) >= 10) {
            fail('Too many accounts created from this connection. Try again later.', 429);
        }
        try {
            pdo()->prepare('INSERT INTO students (username, display, password_hash, last_login_at, last_active_at) VALUES (?, ?, ?, NOW(), NOW())')
                ->execute([$name, $name, password_hash($pw, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                fail('That username is already taken. Use "Log in" instead.');
            }
            throw $e;
        }
        session_regenerate_id(true);                       // new session id after login (stops session fixation)
        $_SESSION['student_id'] = (int)pdo()->lastInsertId();
        $_SESSION['student_name'] = $name;
        $_SESSION['prev'] = null;
        $_SESSION['pw_version'] = 1;
        log_activity('student', $name, 'student_register', 'New student account');
        out(['ok' => true, 'display' => $name, 'prev' => null, 'progress' => [], 'locks' => new stdClass()]);

    case 'login':
        $name = $str('username', 30);
        $pw = (string)($in['password'] ?? '');
        if (recent_count('student_login_failed', 15) >= 8) {
            fail('Too many failed attempts. Wait 15 minutes and try again.', 429);
        }
        $st = pdo()->prepare('SELECT id, display, password_hash, status, pw_version, UNIX_TIMESTAMP(last_active_at) * 1000 AS prev FROM students WHERE username = ?');
        $st->execute([$name]);
        $s = $st->fetch();
        // Always run password_verify (against a dummy hash if the user is unknown) so timing does not reveal which usernames exist.
        $hash = $s ? $s['password_hash'] : '$2y$10$usesomesillystringforsaltandhashxxxxxxxxxxxxxxxxxxxxxxxxxx';
        $good = password_verify($pw, $hash) && $s;
        if (!$good) {
            log_activity('visitor', mb_substr($name, 0, 40), 'student_login_failed', 'Wrong username or password');
            usleep(300000);
            fail('Wrong username or password.');
        }
        if ($s['status'] === 'blocked') {
            log_activity('student', $s['display'], 'student_login_failed', 'Blocked account tried to log in');
            fail('This account has been blocked. Please contact the school.', 403);
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            pdo()->prepare('UPDATE students SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $s['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['student_id'] = (int)$s['id'];
        $_SESSION['student_name'] = $s['display'];
        $_SESSION['prev'] = $s['prev'] !== null ? (int)$s['prev'] : null;
        $_SESSION['pw_version'] = (int)$s['pw_version'];   // an admin reset raises this number and ends older logins
        pdo()->prepare('UPDATE students SET last_login_at = NOW(), last_active_at = NOW() WHERE id = ?')->execute([$s['id']]);
        log_activity('student', $s['display'], 'student_login', 'Logged in');
        out(['ok' => true, 'display' => $s['display'], 'prev' => $_SESSION['prev'], 'progress' => student_progress((int)$s['id']), 'locks' => student_locks((int)$s['id'])]);

    case 'logout':
        if ($sid) {
            log_activity('student', $sname, 'student_logout', 'Logged out');
        }
        unset($_SESSION['student_id'], $_SESSION['student_name'], $_SESSION['prev']);
        out(['ok' => true]);

    case 'me':
        if (!$sid) {
            out(['ok' => false]);
        }
        $st = pdo()->prepare('SELECT display, status, pw_version FROM students WHERE id = ?');
        $st->execute([$sid]);
        $me = $st->fetch();
        if (!$me || $me['status'] !== 'active' || (int)$me['pw_version'] !== (int)($_SESSION['pw_version'] ?? 0)) {   // deleted, blocked or password/username reset by the admin
            unset($_SESSION['student_id'], $_SESSION['student_name']);
            out(['ok' => false]);
        }
        $_SESSION['student_name'] = $me['display'];   // picks up a name the admin changed
        out(['ok' => true, 'display' => $me['display'], 'prev' => $_SESSION['prev'] ?? null, 'progress' => student_progress($sid), 'locks' => student_locks($sid)]);

    case 'ping':
        if ($sid) {
            pdo()->prepare('UPDATE students SET last_active_at = NOW() WHERE id = ?')->execute([$sid]);
        }
        out(['ok' => (bool)$sid]);

    /* ---------------- study tracker ---------------- */
    case 'progress':
        if (!$sid) {
            fail('Please log in first.', 401);
        }
        $rid = (int)($in['rid'] ?? 0);
        $on = !empty($in['studied']);
        $st = pdo()->prepare('SELECT r.title, c.code FROM resources r JOIN units u ON u.id = r.unit_id JOIN courses c ON c.id = u.course_id WHERE r.id = ?');
        $st->execute([$rid]);
        $r = $st->fetch();
        if (!$r) {
            fail('Unknown resource.');
        }
        if ($on) {
            $lk = pdo()->prepare('SELECT reason FROM progress_revoked WHERE student_id = ? AND resource_id = ?');
            $lk->execute([$sid, $rid]);
            if ($reason = $lk->fetchColumn()) {   // the admin removed this tick: it cannot be re-marked until unlocked
                log_activity('student', $sname, 'progress_blocked', $r['code'] . ' | ' . $r['title']);
                fail('Your lecturer removed this tick: ' . $reason . '. Please contact them to unlock it.', 403);
            }
            pdo()->prepare('INSERT IGNORE INTO progress (student_id, resource_id) VALUES (?, ?)')->execute([$sid, $rid]);
        } else {
            pdo()->prepare('DELETE FROM progress WHERE student_id = ? AND resource_id = ?')->execute([$sid, $rid]);
        }
        log_activity('student', $sname, $on ? 'resource_studied' : 'resource_unstudied', $r['code'] . ' | ' . $r['title']);
        out(['ok' => true]);

    /* ---------------- public forms ---------------- */
    case 'apply':
        $name = $str('name', 80); $email = $str('email', 120); $phone = $str('phone', 25); $prog = $str('programme', 60); $why = $str('why', 800);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[+0-9 ()-]{9,25}$/', $phone) || $prog === '') {
            fail('Please fill in name, a valid email, a valid phone number and a programme.');
        }
        if (recent_count('application_submitted', 60) >= 5) {
            fail('Too many applications from this connection. Try again later.', 429);
        }
        pdo()->prepare('INSERT INTO applications (name, email, phone, programme, statement, ip) VALUES (?, ?, ?, ?, ?, ?)')->execute([$name, $email, $phone, $prog, $why, client_ip()]);
        log_activity($sid ? 'student' : 'visitor', $sid ? $sname : $name, 'application_submitted', $prog);
        out(['ok' => true]);

    case 'message':
        if (!$sid) {
            fail('Please log in first.', 401);
        }
        $name = $str('name', 80); $email = $str('email', 120); $cat = $str('category', 40); $txt = $str('comment', 1000);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $cat === '') {
            fail('Please fill in name, a valid email and a category.');
        }
        if (recent_count('message_sent', 60) >= 10) {
            fail('Too many messages from this connection. Try again later.', 429);
        }
        pdo()->prepare('INSERT INTO messages (student_id, name, email, category, comment) VALUES (?, ?, ?, ?, ?)')->execute([$sid, $name, $email, $cat, $txt]);
        log_activity('student', $sname, 'message_sent', $cat);
        out(['ok' => true]);

    /* ---------------- page views (whitelisted shape only, so the log cannot be filled with junk) ---------------- */
    case 'log':
        $route = $str('route', 30);
        if ($sid && ($in['event'] ?? '') === 'page_view' && preg_match('/^[a-z]{3,12}(\/[A-Z]{2,4}\d{3})?$/', $route)) {
            log_activity('student', $sname, 'page_view', $route);
        }
        out(['ok' => true]);

    default:
        fail('Unknown action', 404);
}
