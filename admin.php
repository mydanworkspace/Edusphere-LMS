<?php
declare(strict_types=1);
/* ============================================================
   admin.php - EduSphere administrator dashboard
   Sections: Overview, Courses, Units, Resources, News, Timetable, Assessments, Fees,
   Students, Applications, Messages, Activity log, Settings, Admins.
   Security: password_hash login with lockout, CSRF token on every POST, prepared statements,
   escaped output, strict CSP with nonce, idle timeout, every admin action written to the activity log.
   ============================================================ */
require __DIR__ . '/db.php';
start_session();

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; script-src 'nonce-$nonce'; font-src https://fonts.gstatic.com; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('X-Frame-Options: DENY');                 // admin pages can never be shown inside another site's frame (clickjacking)
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

/* ---------------- small helpers ---------------- */
function flash(string $type, string $msg): void { $_SESSION['flash'] = [$type, $msg]; }
function redirect(string $url): void { header('Location: ' . $url); exit; }
function admin_id(): int
{
    if (empty($_SESSION['admin_id'])) { return 0; }
    if (time() - (int)($_SESSION['admin_seen'] ?? 0) > 1800) {      // 30 minutes idle: log out
        unset($_SESSION['admin_id'], $_SESSION['admin_name']);
        return 0;
    }
    $_SESSION['admin_seen'] = time();
    return (int)$_SESSION['admin_id'];
}
function admin_name(): string { return (string)($_SESSION['admin_name'] ?? ''); }
function good_admin_password(string $p): bool { return strlen($p) >= 10 && strlen($p) <= 72 && preg_match('/[A-Za-z]/', $p) && preg_match('/\d/', $p); }
const ADMIN_RE = '/^[A-Za-z][A-Za-z0-9._-]{2,29}$/';
const STUDENT_RE = '/^[A-Za-z][A-Za-z0-9 ._-]{2,29}$/';
/* Random temporary password: 6 letters + 4 digits (no look-alike characters like 0/O or 1/l), shuffled. */
function gen_password(): string
{
    $l = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz'; $d = '23456789'; $c = [];
    for ($i = 0; $i < 6; $i++) { $c[] = $l[random_int(0, strlen($l) - 1)]; }
    for ($i = 0; $i < 4; $i++) { $c[] = $d[random_int(0, strlen($d) - 1)]; }
    for ($i = count($c) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$c[$i], $c[$j]] = [$c[$j], $c[$i]]; }
    return implode('', $c);
}
function slot_times(): array
{
    $def = [1 => '08:00-10:00', 2 => '11:00-13:00', 3 => '14:00-16:00']; $o = [];
    foreach ($def as $n => $d) { $o[$n] = explode('-', setting('slot_' . $n, $d)) + [1 => '00:00']; }
    return $o;
}

function fld(string $n, string $l, string $t, array $o = []): array { return ['name' => $n, 'label' => $l, 'type' => $t] + $o; }

/* ---------------- what each editable section looks like ----------------
   Column and table names only ever come from this trusted list, never from the visitor. */
function entities(): array
{
    $phone = '/^[+0-9 ()-]{7,25}$/';
    return [
        'courses' => ['title' => 'Courses', 'table' => 'courses', 'order' => 'sort_order, code', 'list' => ['code', 'title', 'instructor', 'credits', 'sort_order'],
            'fields' => [
                fld('code', 'Course code', 'text', ['max' => 10, 'req' => 1, 're' => '/^[A-Z]{2,4}\d{3}$/', 'help' => 'Letters then 3 digits, e.g. CS101']),
                fld('title', 'Title', 'text', ['max' => 120, 'req' => 1]),
                fld('instructor', 'Instructor', 'text', ['max' => 80, 'req' => 1]),
                fld('email', 'Instructor email', 'email', ['max' => 120, 'req' => 1]),
                fld('phone', 'Instructor phone', 'text', ['max' => 25, 'req' => 1, 're' => $phone, 'help' => 'Digits, spaces, + ( ) -']),
                fld('credits', 'Credit hours', 'int', ['min' => 1, 'max' => 12, 'req' => 1]),
                fld('keywords', 'Search keywords', 'text', ['max' => 200, 'help' => 'Words students might type in the search bar']),
                fld('why', 'Why this course', 'textarea', ['max' => 800, 'req' => 1]),
                fld('gain', 'Skills students gain', 'textarea', ['max' => 800, 'req' => 1]),
                fld('sort_order', 'Sort order', 'int', ['min' => 0, 'max' => 999, 'def' => 0]),
            ]],
        'units' => ['title' => 'Units (course outline)', 'table' => 'units', 'order' => 'course_id, sort_order', 'list' => ['course_id', 'title', 'sort_order'],
            'fields' => [
                fld('course_id', 'Course', 'fk', ['req' => 1, 'sql' => "SELECT id, CONCAT(code, ' - ', title) FROM courses ORDER BY code"]),
                fld('title', 'Unit title', 'text', ['max' => 120, 'req' => 1]),
                fld('sort_order', 'Sort order', 'int', ['min' => 0, 'max' => 999, 'def' => 0]),
            ]],
        'resources' => ['title' => 'Study resources (notes and videos)', 'table' => 'resources', 'order' => 'unit_id, sort_order', 'list' => ['unit_id', 'title', 'kind', 'minutes'],
            'fields' => [
                fld('unit_id', 'Unit', 'fk', ['req' => 1, 'sql' => "SELECT u.id, CONCAT(c.code, ' / ', u.title) FROM units u JOIN courses c ON c.id = u.course_id ORDER BY c.code, u.sort_order"]),
                fld('title', 'Title', 'text', ['max' => 160, 'req' => 1]),
                fld('url', 'Link (https://...)', 'url', ['max' => 500, 'req' => 1]),
                fld('kind', 'Type', 'select', ['req' => 1, 'opts' => ['Video' => 'Video', 'Notes' => 'Notes']]),
                fld('minutes', 'Study minutes', 'int', ['min' => 1, 'max' => 600, 'req' => 1, 'help' => '90 means 1h 30m']),
                fld('sort_order', 'Sort order', 'int', ['min' => 0, 'max' => 999, 'def' => 0]),
            ]],
        'news' => ['title' => 'School news', 'table' => 'news', 'order' => 'posted_on DESC, id DESC', 'list' => ['posted_on', 'title', 'active'],
            'fields' => [
                fld('title', 'Headline', 'text', ['max' => 160, 'req' => 1]),
                fld('body', 'Details', 'textarea', ['max' => 800, 'req' => 1]),
                fld('posted_on', 'Date', 'date', ['req' => 1]),
                fld('active', 'Show on the portal', 'bool', ['def' => 1]),
            ]],
        'assessments' => ['title' => 'CATs, assignments and exams', 'table' => 'assessments', 'order' => 'week, id', 'list' => ['name', 'week', 'weight', 'details'],
            'help' => 'Due dates are Friday 17:00 of the chosen week, counted from the semester start date in Settings. Weights should add up to 100.',
            'fields' => [
                fld('name', 'Name', 'text', ['max' => 60, 'req' => 1]),
                fld('week', 'Week number', 'int', ['min' => 1, 'max' => 20, 'req' => 1]),
                fld('weight', 'Weight (%)', 'int', ['min' => 0, 'max' => 100, 'req' => 1]),
                fld('details', 'Details', 'text', ['max' => 200, 'req' => 1]),
            ]],
        'fees' => ['title' => 'Fees (Finances page)', 'table' => 'fees', 'order' => 'category, sort_order, id', 'list' => ['category', 'module', 'credits', 'fee', 'status'],
            'fields' => [
                fld('category', 'Category', 'text', ['max' => 60, 'req' => 1]),
                fld('module', 'Module', 'text', ['max' => 120, 'req' => 1]),
                fld('credits', 'Credit hours', 'int', ['min' => 1, 'max' => 12, 'req' => 1]),
                fld('fee', 'Fee (KES)', 'int', ['min' => 0, 'max' => 10000000, 'req' => 1]),
                fld('status', 'Status', 'select', ['req' => 1, 'opts' => ['Available' => 'Available', 'Full' => 'Full']]),
                fld('sort_order', 'Sort order', 'int', ['min' => 0, 'max' => 999, 'def' => 0]),
            ]],
        'applications' => ['title' => 'Applications', 'table' => 'applications', 'order' => 'created_at DESC', 'no_create' => 1, 'detail' => 'statement',
            'list' => ['created_at', 'name', 'email', 'phone', 'programme', 'status'],
            'fields' => [fld('status', 'Status', 'select', ['req' => 1, 'opts' => ['new' => 'new', 'reviewing' => 'reviewing', 'accepted' => 'accepted', 'rejected' => 'rejected']])]],
        'messages' => ['title' => 'Support messages', 'table' => 'messages', 'order' => 'created_at DESC', 'no_create' => 1, 'detail' => 'comment',
            'list' => ['created_at', 'name', 'email', 'category', 'status'],
            'fields' => [fld('status', 'Status', 'select', ['req' => 1, 'opts' => ['open' => 'open', 'resolved' => 'resolved']])]],
    ];
}

/* Fills in the choices of select and fk (foreign key) fields. */
function prepare_fields(array $fields): array
{
    $out = [];
    foreach ($fields as $f) {
        if ($f['type'] === 'fk') {
            $f['_opts'] = [];
            foreach (pdo()->query($f['sql'])->fetchAll(PDO::FETCH_NUM) as $r) { $f['_opts'][(string)$r[0]] = $r[1]; }
        } elseif ($f['type'] === 'select') {
            $f['_opts'] = array_map('strval', $f['opts']);
        }
        $out[$f['name']] = $f;
    }
    return $out;
}

/* Checks every submitted field on the server. Returns [values, errors]. */
function validate(array $fields, array $post): array
{
    $vals = []; $errs = [];
    foreach ($fields as $n => $f) {
        $raw = $post['f_' . $n] ?? '';
        $raw = is_string($raw) ? trim($raw) : '';
        $label = $f['label'];
        if ($f['type'] === 'bool') { $vals[$n] = isset($post['f_' . $n]) ? 1 : 0; continue; }
        if ($raw === '') {
            if (!empty($f['req']) && !array_key_exists('def', $f)) { $errs[] = "$label is required."; continue; }
            if (in_array($f['type'], ['fk'], true)) { $vals[$n] = null; continue; }
            if (array_key_exists('def', $f) && in_array($f['type'], ['int'], true)) { $vals[$n] = $f['def']; continue; }
            if (!empty($f['req'])) { $errs[] = "$label is required."; continue; }
            $vals[$n] = ''; continue;
        }
        switch ($f['type']) {
            case 'int':
                if (!preg_match('/^\d{1,9}$/', $raw) || (int)$raw < ($f['min'] ?? 0) || (int)$raw > ($f['max'] ?? PHP_INT_MAX)) { $errs[] = "$label must be a number from " . ($f['min'] ?? 0) . ' to ' . ($f['max'] ?? 'any') . '.'; break; }
                $vals[$n] = (int)$raw; break;
            case 'email':
                if (!filter_var($raw, FILTER_VALIDATE_EMAIL) || mb_strlen($raw) > $f['max']) { $errs[] = "$label is not a valid email."; break; }
                $vals[$n] = $raw; break;
            case 'url':
                $ok = filter_var($raw, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $raw) && mb_strlen($raw) <= $f['max'];   // only http(s): blocks javascript: links
                if (!$ok) { $errs[] = "$label must be a full http:// or https:// link."; break; }
                $vals[$n] = $raw; break;
            case 'date':
                $d = DateTime::createFromFormat('Y-m-d', $raw);
                if (!$d || $d->format('Y-m-d') !== $raw) { $errs[] = "$label must be a valid date."; break; }
                $vals[$n] = $raw; break;
            case 'select':
            case 'fk':
                if (!array_key_exists($raw, $f['_opts'])) { $errs[] = "$label has an invalid choice."; break; }
                $vals[$n] = $f['type'] === 'fk' ? (int)$raw : $raw; break;
            default:   // text, textarea
                if (mb_strlen($raw) > $f['max']) { $errs[] = "$label is too long (max {$f['max']} characters)."; break; }
                if (!empty($f['re']) && !preg_match($f['re'], $raw)) { $errs[] = "$label has the wrong format." . (!empty($f['help']) ? ' ' . $f['help'] . '.' : ''); break; }
                $vals[$n] = $raw;
        }
    }
    return [$vals, $errs];
}

function list_cell(array $row, string $col, array $fields): string
{
    $v = $row[$col] ?? '';
    $f = $fields[$col] ?? null;
    if ($f) {
        if ($f['type'] === 'bool') { return $v ? 'Yes' : 'No'; }
        if ($f['type'] === 'select' || $f['type'] === 'fk') { return e($f['_opts'][(string)$v] ?? ($v === null ? 'None' : $v)); }
        if ($f['name'] === 'fee') { return 'KES ' . number_format((int)$v); }
    }
    return e(mb_strimwidth((string)$v, 0, 70, '...'));
}

/* ---------------- state ---------------- */
$pdo = pdo();
$ip = client_ip();
$adminCount = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
$ENT = entities();

/* ================= POST actions ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        http_response_code(403);
        exit('Your form expired or is not valid. Go back, reload the page and try again.');
    }
    $do = (string)($_POST['do'] ?? '');
    $back = 'admin.php';

    if ($adminCount === 0 && $do === 'setup') {
        $u = trim((string)($_POST['username'] ?? '')); $p = (string)($_POST['password'] ?? '');
        if (!hash_equals(ADMIN_SETUP_KEY, (string)($_POST['setup_key'] ?? ''))) { flash('err', 'Wrong setup key. It is the ADMIN_SETUP_KEY value in db.php.'); }
        elseif (!preg_match(ADMIN_RE, $u)) { flash('err', 'Username: 3 to 30 characters, start with a letter, then letters, numbers . _ -'); }
        elseif (!good_admin_password($p)) { flash('err', 'Admin password needs 10 or more characters with letters and numbers.'); }
        elseif ($p !== (string)($_POST['password2'] ?? '')) { flash('err', 'The two passwords do not match.'); }
        else {
            $pdo->prepare('INSERT INTO admins (username, password_hash, last_login_at) VALUES (?, ?, NOW())')->execute([$u, password_hash($p, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$pdo->lastInsertId(); $_SESSION['admin_name'] = $u; $_SESSION['admin_seen'] = time();
            log_activity('admin', $u, 'admin_setup', 'First administrator created');
            flash('ok', 'Admin account created. You are signed in.');
        }
        redirect($back);
    }

    if ($do === 'login') {
        $u = trim((string)($_POST['username'] ?? '')); $p = (string)($_POST['password'] ?? '');
        if (recent_count('admin_login_failed', 15) >= 5) { flash('err', 'Too many failed attempts from this connection. Wait 15 minutes.'); redirect($back); }
        $st = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = ?');
        $st->execute([$u]);
        $a = $st->fetch();
        $hash = $a ? $a['password_hash'] : '$2y$10$usesomesillystringforsaltandhashxxxxxxxxxxxxxxxxxxxxxxxxxx';
        if ($a && password_verify($p, $hash)) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$a['id']; $_SESSION['admin_name'] = $a['username']; $_SESSION['admin_seen'] = time();
            $pdo->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([$a['id']]);
            log_activity('admin', $a['username'], 'admin_login', 'Signed in');
        } else {
            log_activity('visitor', mb_substr($u, 0, 40), 'admin_login_failed', 'Wrong admin username or password');
            usleep(400000);
            flash('err', 'Wrong username or password.');
        }
        redirect($back);
    }

    if (!admin_id()) { redirect($back); }          // everything below needs a signed-in admin
    $who = admin_name();

    if ($do === 'logout') {
        log_activity('admin', $who, 'admin_logout', 'Signed out');
        unset($_SESSION['admin_id'], $_SESSION['admin_name']);
        session_regenerate_id(true);
        redirect($back);
    }

    $sec = (string)($_POST['sec'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($do === 'save' && isset($ENT[$sec])) {
        $cfg = $ENT[$sec]; $back = 'admin.php?p=' . $sec;
        if (!empty($cfg['no_create']) && $id < 1) { redirect($back); }
        $fields = prepare_fields($cfg['fields']);
        [$vals, $errs] = validate($fields, $_POST);
        if ($errs) {
            $_SESSION['old'] = [$sec, $id, $_POST];
            flash('err', implode(' ', $errs));
            redirect($back . '&edit=' . $id . '#form');
        }
        try {
            if ($id > 0) {
                $set = implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($vals)));
                $pdo->prepare("UPDATE `{$cfg['table']}` SET $set WHERE id = ?")->execute(array_merge(array_values($vals), [$id]));
                log_activity('admin', $who, 'admin_update', "$sec #$id");
                flash('ok', 'Saved changes.');
            } else {
                $cols = implode(', ', array_map(static fn($c) => "`$c`", array_keys($vals)));
                $marks = implode(', ', array_fill(0, count($vals), '?'));
                $pdo->prepare("INSERT INTO `{$cfg['table']}` ($cols) VALUES ($marks)")->execute(array_values($vals));
                log_activity('admin', $who, 'admin_create', "$sec #" . $pdo->lastInsertId());
                flash('ok', 'Added.');
            }
        } catch (PDOException $ex) {
            $_SESSION['old'] = [$sec, $id, $_POST];
            flash('err', $ex->getCode() === '23000' ? 'That would duplicate or break a link to another record (for example the same code, or the same day and slot already in use).' : 'Could not save. Check the values and try again.');
            error_log($ex->getMessage());
            redirect($back . '&edit=' . $id . '#form');
        }
        redirect($back);
    }

    if ($do === 'delete' && isset($ENT[$sec])) {
        $cfg = $ENT[$sec]; $back = 'admin.php?p=' . $sec;
        try {
            $pdo->prepare("DELETE FROM `{$cfg['table']}` WHERE id = ?")->execute([$id]);
            log_activity('admin', $who, 'admin_delete', "$sec #$id");
            flash('ok', 'Deleted.');
        } catch (PDOException $ex) { flash('err', 'Could not delete this record.'); error_log($ex->getMessage()); }
        redirect($back);
    }

    if (in_array($do, ['student_details', 'student_password', 'student_revoke', 'student_unlock', 'student_reset_progress'], true)) {
        $row = $pdo->prepare('SELECT id, username, display FROM students WHERE id = ?'); $row->execute([$id]); $stu = $row->fetch();
        if (!$stu) { flash('err', 'Student not found.'); redirect('admin.php?p=students'); }
        $back = 'admin.php?p=student&id=' . $id;
        $nm = $stu['display'];

        if ($do === 'student_details') {
            $un = trim((string)($_POST['username'] ?? '')); $dp = trim((string)($_POST['display'] ?? '')); $stt = (string)($_POST['status'] ?? '');
            if (!preg_match(STUDENT_RE, $un) || !preg_match(STUDENT_RE, $dp)) { flash('err', 'Username and display name: 3 to 30 characters, start with a letter, then letters, numbers, space . _ -'); }
            elseif (!in_array($stt, ['active', 'blocked'], true)) { flash('err', 'Invalid status.'); }
            else {
                try {
                    $bump = strcasecmp($un, $stu['username']) !== 0 ? 1 : 0;   // a new login name ends the student's old logins
                    $pdo->prepare('UPDATE students SET username = ?, display = ?, status = ?, pw_version = pw_version + ? WHERE id = ?')->execute([$un, $dp, $stt, $bump, $id]);
                    log_activity('admin', $who, 'admin_update', "student $nm: details (username $un, name $dp, status $stt)");
                    flash('ok', 'Student details saved.' . ($bump ? ' The student must log in again with the new username.' : ''));
                } catch (PDOException $ex) { flash('err', $ex->getCode() === '23000' ? 'That username is already used by another student.' : 'Could not save.'); }
            }
        }

        if ($do === 'student_password') {
            $gen = isset($_POST['generate']); $np = $gen ? gen_password() : (string)($_POST['new_password'] ?? '');
            if (!$gen && (strlen($np) < 6 || strlen($np) > 64 || !preg_match('/[A-Za-z]/', $np) || !preg_match('/\d/', $np))) { flash('err', 'New password needs 6 to 64 characters with letters and a number, or tick "Generate a temporary password".'); }
            else {
                $pdo->prepare('UPDATE students SET password_hash = ?, pw_version = pw_version + 1 WHERE id = ?')->execute([password_hash($np, PASSWORD_DEFAULT), $id]);   // pw_version + 1 logs the student out everywhere
                log_activity('admin', $who, 'admin_password_reset', "student $nm");
                flash('ok', 'Password reset for ' . $nm . '. Give the student this password (shown only once): ' . $np);
            }
        }

        if ($do === 'student_revoke') {
            $rid = (int)($_POST['rid'] ?? 0); $why = trim((string)($_POST['reason'] ?? '')); $lock = isset($_POST['lock']);
            $rs = $pdo->prepare('SELECT r.title, c.code FROM progress p JOIN resources r ON r.id = p.resource_id JOIN units u ON u.id = r.unit_id JOIN courses c ON c.id = u.course_id WHERE p.student_id = ? AND p.resource_id = ?');
            $rs->execute([$id, $rid]); $res = $rs->fetch();
            if (!$res) { flash('err', 'That resource is not marked as studied.'); }
            elseif (mb_strlen($why) < 3 || mb_strlen($why) > 200) { flash('err', 'Give a reason of 3 to 200 characters.'); }
            else {
                $pdo->prepare('DELETE FROM progress WHERE student_id = ? AND resource_id = ?')->execute([$id, $rid]);   // progress % drops immediately
                if ($lock) {
                    $pdo->prepare('INSERT INTO progress_revoked (student_id, resource_id, reason, revoked_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason), revoked_by = VALUES(revoked_by), revoked_at = NOW()')->execute([$id, $rid, $why, $who]);
                }
                log_activity('admin', $who, 'admin_revoke_progress', "student $nm: " . $res['code'] . ' | ' . $res['title'] . " ($why)" . ($lock ? ' [locked]' : ''));
                flash('ok', 'Removed "' . $res['title'] . '" from ' . $nm . "'s progress." . ($lock ? ' It is locked until you unlock it.' : ''));
            }
        }

        if ($do === 'student_unlock') {
            $pdo->prepare('DELETE FROM progress_revoked WHERE student_id = ? AND resource_id = ?')->execute([$id, (int)($_POST['rid'] ?? 0)]);
            log_activity('admin', $who, 'admin_update', "student $nm: unlocked resource #" . (int)($_POST['rid'] ?? 0)); flash('ok', 'Unlocked. The student can mark it as studied again.');
        }

        if ($do === 'student_reset_progress') {
            $del = $pdo->prepare('DELETE FROM progress WHERE student_id = ?'); $del->execute([$id]);
            log_activity('admin', $who, 'admin_revoke_progress', "student $nm: all progress cleared (" . $del->rowCount() . ' resources)');
            flash('ok', 'All progress cleared for ' . $nm . '.');
        }
        redirect($back);
    }

    if ($do === 'timetable_save') {
        $ids = array_map('strval', $pdo->query('SELECT id FROM courses')->fetchAll(PDO::FETCH_COLUMN)); $cells = []; $count = 0; $bad = '';
        for ($d = 1; $d <= 5 && !$bad; $d++) {
            for ($sl = 1; $sl <= 3; $sl++) {
                $v = (string)($_POST["t_{$d}_{$sl}"] ?? '');
                if ($v !== '' && !in_array($v, $ids, true)) { $bad = 'Unknown course chosen.'; break; }
                $cells[] = [$d, $sl, $v === '' ? null : (int)$v]; if ($v !== '') { $count++; }
            }
        }
        $times = [];
        for ($sl = 1; $sl <= 3 && !$bad; $sl++) {
            $a = (string)($_POST["s{$sl}_start"] ?? ''); $b = (string)($_POST["s{$sl}_end"] ?? '');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $a) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $b) || $a >= $b) { $bad = "Slot $sl: start time must be before end time."; break; }
            if ($sl > 1 && $a < explode('-', $times[$sl - 1])[1]) { $bad = "Slot $sl starts before slot " . ($sl - 1) . ' ends.'; break; }
            $times[$sl] = $a . '-' . $b;
        }
        if ($bad) { flash('err', $bad); }
        else {
            $pdo->beginTransaction();
            $up = $pdo->prepare('INSERT INTO timetable (day, slot, course_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE course_id = VALUES(course_id)');
            foreach ($cells as $c) { $up->execute($c); }
            $us = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
            foreach ($times as $n => $t) { $us->execute(['slot_' . $n, $t]); }
            $pdo->commit();
            log_activity('admin', $who, 'admin_update', "timetable saved: $count classes a week; times " . implode(', ', $times));
            flash('ok', "Timetable saved: $count live classes a week.");
        }
        redirect('admin.php?p=timetable');
    }

    if ($do === 'student_delete') {
        $row = $pdo->prepare('SELECT display FROM students WHERE id = ?'); $row->execute([$id]); $disp = $row->fetchColumn();
        if ($disp !== false) {
            $pdo->prepare('DELETE FROM students WHERE id = ?')->execute([$id]);
            log_activity('admin', $who, 'admin_delete', "student $disp (and their progress)");
            flash('ok', 'Student deleted.');
        }
        redirect('admin.php?p=students');
    }

    if ($do === 'admin_add') {
        $u = trim((string)($_POST['username'] ?? '')); $p = (string)($_POST['password'] ?? '');
        if (!preg_match(ADMIN_RE, $u)) { flash('err', 'Username: 3 to 30 characters, start with a letter, then letters, numbers . _ -'); }
        elseif (!good_admin_password($p)) { flash('err', 'Password needs 10 or more characters with letters and numbers.'); }
        else {
            try {
                $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')->execute([$u, password_hash($p, PASSWORD_DEFAULT)]);
                log_activity('admin', $who, 'admin_create', "admin $u"); flash('ok', 'Administrator added.');
            } catch (PDOException $ex) { flash('err', 'That admin username already exists.'); }
        }
        redirect('admin.php?p=admins');
    }

    if ($do === 'admin_delete') {
        if ($id === admin_id()) { flash('err', 'You cannot delete the account you are using.'); }
        elseif ((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() < 2) { flash('err', 'At least one administrator must remain.'); }
        else { $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]); log_activity('admin', $who, 'admin_delete', "admin #$id"); flash('ok', 'Administrator deleted.'); }
        redirect('admin.php?p=admins');
    }

    if ($do === 'settings_save') {
        $sd = (string)($_POST['semester_start'] ?? ''); $mu = trim((string)($_POST['meet_url'] ?? '')); $wa = preg_replace('/\D/', '', (string)($_POST['whatsapp'] ?? ''));
        $d = DateTime::createFromFormat('Y-m-d', $sd);
        if (!$d || $d->format('Y-m-d') !== $sd || $d->format('N') !== '1') { flash('err', 'Semester start must be a valid date and a Monday.'); }
        elseif (!filter_var($mu, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $mu) || strlen($mu) > 500) { flash('err', 'Live class link must be a full http(s) link.'); }
        elseif (strlen($wa) < 9 || strlen($wa) > 15) { flash('err', 'WhatsApp number needs 9 to 15 digits with country code, e.g. 254712345678.'); }
        else {
            $up = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
            foreach (['semester_start' => $sd, 'meet_url' => $mu, 'whatsapp' => $wa] as $k => $v) { $up->execute([$k, $v]); }
            log_activity('admin', $who, 'admin_update', 'settings'); flash('ok', 'Settings saved.');
        }
        redirect('admin.php?p=settings');
    }

    if ($do === 'purge_log') {
        $days = (int)($_POST['days'] ?? 0);
        if (in_array($days, [30, 90, 180, 365], true)) {
            $st = $pdo->prepare('DELETE FROM activity_log WHERE created_at < (NOW() - INTERVAL ' . $days . ' DAY)'); $st->execute();
            log_activity('admin', $who, 'admin_delete', 'activity log older than ' . $days . ' days (' . $st->rowCount() . ' rows)');
            flash('ok', 'Old log entries removed.');
        }
        redirect('admin.php?p=activity');
    }
    redirect($back);
}

/* ================= page output ================= */
$admin = admin_id();
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

function head_html(string $title, string $nonce, bool $nav = false): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow"><title>' . e($title) . ' | EduSphere Admin</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="css/style.css">';
    echo '<style nonce="' . e($nonce) . '">
      .anav{background:var(--primary-dark);} .anav ul{list-style:none;margin:0;padding:var(--space-2) 0;display:flex;flex-wrap:wrap;gap:var(--space-1)}
      .anav a{display:inline-block;color:#fff;text-decoration:none;padding:.4rem .75rem;border-radius:var(--radius-sm);font-size:.9rem;transition:all .3s ease}
      .anav a:hover{background:rgba(255,255,255,.18)} .anav a[aria-current="page"]{background:var(--accent);color:var(--on-accent);font-weight:600}
      .pill{background:#b91c1c;color:#fff;border-radius:999px;padding:0 .45rem;font-size:.75rem;margin-left:.3rem}
      .stats{display:grid;gap:var(--space-3);grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin-bottom:var(--space-4)}
      .stat{background:#fff;border:1px solid var(--border);border-top:4px solid var(--accent);border-radius:var(--radius);padding:var(--space-3);box-shadow:var(--shadow)}
      .stat b{display:block;font-size:1.8rem;color:var(--primary)} .stat span{color:var(--muted);font-size:.85rem}
      .acts{display:flex;gap:var(--space-1);flex-wrap:wrap;align-items:center} .acts form{margin:0}
      .btn-sm{padding:.3rem .7rem;font-size:.85rem} .btn-danger{background:#b91c1c;color:#fff} .btn-danger:hover{background:#991b1b;transform:translateY(-2px);box-shadow:var(--shadow-lg)}
      .form-grid{display:grid;gap:var(--space-3);grid-template-columns:repeat(auto-fit,minmax(240px,1fr))} .wide{grid-column:1/-1}
      td details summary{font-weight:400} .login-box{max-width:420px;margin:var(--space-5) auto}
      .filters{display:flex;flex-wrap:wrap;gap:var(--space-2);align-items:end;margin-bottom:var(--space-3)} .filters .field{margin:0}
      .mono{font-family:ui-monospace,Consolas,monospace;font-size:.8rem} .pager{display:flex;gap:var(--space-2);align-items:center;margin-top:var(--space-3)}
      tfoot td, tfoot th{background:#e2e8f0} input.chk{width:auto}
      .astick{position:sticky;top:0;z-index:50;box-shadow:0 2px 8px rgba(15,23,42,.25)} html{scroll-padding-top:7rem}
      @media(max-width:899px){.anav ul{flex-wrap:nowrap;overflow-x:auto}.anav li{flex:none}}
    </style></head><body>';
}
function flash_html(?array $f): void
{
    if (!$f) { return; }
    echo $f[0] === 'ok' ? '<div class="msg" role="status">' . e($f[1]) . '</div>' : '<div class="error-box" role="alert"><h2>Problem</h2><p>' . e($f[1]) . '</p></div>';
}
function tail_html(string $nonce): void
{
    echo '<script nonce="' . e($nonce) . '">
      document.querySelectorAll("form[data-confirm]").forEach(function(f){f.addEventListener("submit",function(ev){if(!confirm(f.dataset.confirm))ev.preventDefault();});});
      document.querySelectorAll("[data-showpw]").forEach(function(c){c.addEventListener("change",function(){document.querySelectorAll(c.dataset.showpw).forEach(function(i){i.type=c.checked?"text":"password";});});});
    </script></body></html>';
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function mini_form(string $do, string $sec, int $id, string $label, string $cls, string $confirm = ''): string
{
    return '<form method="post" action="admin.php"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="do" value="' . e($do) . '"><input type="hidden" name="sec" value="' . e($sec) . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<button class="btn btn-sm ' . $cls . '" type="submit">' . e($label) . '</button></form>';
}

/* ---------------- first run: create the first admin ---------------- */
if ($adminCount === 0) {
    head_html('Set up', $nonce);
    echo '<main id="main-content"><div class="wrap"><div class="form-card login-box"><h1>Create the first admin</h1>';
    flash_html($flash);
    echo '<p>No administrator exists yet. Enter the setup key from <code>db.php</code> and choose a strong password. This form disappears once an admin exists.</p>
    <form method="post" action="admin.php">' . csrf_field() . '<input type="hidden" name="do" value="setup">
      <div class="field"><label for="sk">Setup key</label><input id="sk" name="setup_key" type="password" required autocomplete="off"></div>
      <div class="field"><label for="u">Admin username</label><input id="u" name="username" required maxlength="30" autocomplete="username"></div>
      <div class="field"><label for="p">Password (10+ characters, letters and numbers)</label><input id="p" name="password" type="password" required minlength="10" maxlength="72" autocomplete="new-password"></div>
      <div class="field"><label for="p2">Confirm password</label><input id="p2" name="password2" type="password" required minlength="10" maxlength="72" autocomplete="new-password"></div>
      <div class="field"><label><input type="checkbox" data-showpw="#sk,#p,#p2" class="chk"> Show passwords</label></div>
      <button class="btn btn-primary" type="submit">Create admin</button></form></div></div></main>';
    tail_html($nonce);
    exit;
}

/* ---------------- login ---------------- */
if (!$admin) {
    head_html('Sign in', $nonce);
    echo '<main id="main-content"><div class="wrap"><div class="form-card login-box"><h1>Admin sign in</h1>';
    flash_html($flash);
    echo '<form method="post" action="admin.php">' . csrf_field() . '<input type="hidden" name="do" value="login">
      <div class="field"><label for="u">Username</label><input id="u" name="username" required maxlength="30" autocomplete="username"></div>
      <div class="field"><label for="p">Password</label><input id="p" name="password" type="password" required maxlength="72" autocomplete="current-password"></div>
      <div class="field"><label><input type="checkbox" data-showpw="#p" class="chk"> Show password</label></div>
      <button class="btn btn-primary" type="submit">Sign in</button></form>
      <p class="hint">Five wrong attempts lock this connection for 15 minutes. Sessions end after 30 minutes without activity.</p></div></div></main>';
    tail_html($nonce);
    exit;
}

/* ---------------- signed-in dashboard ---------------- */
$p = (string)($_GET['p'] ?? 'overview');
$sections = ['overview' => 'Overview', 'courses' => 'Courses', 'units' => 'Units', 'resources' => 'Resources', 'news' => 'News', 'timetable' => 'Timetable',
    'assessments' => 'CATs & exams', 'fees' => 'Fees', 'students' => 'Students', 'applications' => 'Applications', 'messages' => 'Messages', 'activity' => 'Activity log', 'settings' => 'Settings', 'admins' => 'Admins'];
if (!isset($sections[$p]) && $p !== 'student') { $p = 'overview'; }
$newApps = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'new'")->fetchColumn();
$openMsgs = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE status = 'open'")->fetchColumn();

/* CSV export of the activity log (read-only, admin only). Cells starting with = + - @ are neutralised so Excel cannot run them as formulas. */
function activity_filters(): array
{
    $w = []; $a = [];
    $ev = (string)($_GET['ev'] ?? ''); if ($ev !== '' && preg_match('/^[a-z_]{1,40}$/', $ev)) { $w[] = 'event = ?'; $a[] = $ev; }
    $ac = trim((string)($_GET['actor'] ?? '')); if ($ac !== '') { $w[] = 'actor_name LIKE ?'; $a[] = '%' . addcslashes(mb_substr($ac, 0, 40), '%_\\') . '%'; }
    $fr = (string)($_GET['from'] ?? ''); if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fr)) { $w[] = 'created_at >= ?'; $a[] = $fr . ' 00:00:00'; }
    $to = (string)($_GET['to'] ?? ''); if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $w[] = 'created_at <= ?'; $a[] = $to . ' 23:59:59'; }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $a];
}
if ($p === 'activity' && isset($_GET['export'])) {
    [$wh, $ar] = activity_filters();
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="edusphere-activity-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w'); fputcsv($o, ['time', 'actor type', 'actor', 'event', 'detail', 'ip']);
    $st = $pdo->prepare('SELECT created_at, actor_type, actor_name, event, detail, ip FROM activity_log' . $wh . ' ORDER BY id DESC LIMIT 20000'); $st->execute($ar);
    while ($r = $st->fetch(PDO::FETCH_NUM)) { fputcsv($o, array_map(static fn($c) => preg_match('/^[=+\-@\t\r]/', (string)$c) ? "'" . $c : $c, $r)); }
    log_activity('admin', admin_name(), 'admin_export', 'activity log CSV');
    exit;
}

head_html($sections[$p] ?? 'Student', $nonce, true);
echo '<div class="astick"><header class="site-header"><div class="wrap site-header__bar"><span class="brand">EduSphere Admin</span><div class="auth"><span>Signed in as ' . e(admin_name()) . '</span>
  <a class="btn btn-secondary btn-sm" href="index.html" target="_blank" rel="noopener noreferrer">View portal</a>
  <form method="post" action="admin.php">' . csrf_field() . '<input type="hidden" name="do" value="logout"><button class="btn btn-secondary btn-sm" type="submit">Sign out</button></form></div></div></header>';
echo '<nav class="anav" aria-label="Admin sections"><div class="wrap"><ul>';
foreach ($sections as $k => $label) {
    $badge = ($k === 'applications' && $newApps) ? '<span class="pill">' . $newApps . '</span>' : (($k === 'messages' && $openMsgs) ? '<span class="pill">' . $openMsgs . '</span>' : '');
    echo '<li><a href="admin.php?p=' . $k . '"' . (($k === $p || ($k === 'students' && $p === 'student')) ? ' aria-current="page"' : '') . '>' . e($label) . $badge . '</a></li>';
}
echo '</ul></div></nav></div><main id="main-content"><div class="wrap">';   // .astick keeps the header and section menu fixed while scrolling
flash_html($flash);

/* ---- Overview ---- */
if ($p === 'overview') {
    $c = static fn(string $sql): int => (int)pdo()->query($sql)->fetchColumn();
    echo '<h1>Overview</h1><div class="stats">';
    foreach ([['Students', $c('SELECT COUNT(*) FROM students')], ['Courses', $c('SELECT COUNT(*) FROM courses')], ['Resources', $c('SELECT COUNT(*) FROM resources')],
        ['New applications', $newApps], ['Open messages', $openMsgs], ['Logins today', $c("SELECT COUNT(*) FROM activity_log WHERE event = 'student_login' AND created_at >= CURDATE()")],
        ['Active now (15 min)', $c('SELECT COUNT(*) FROM students WHERE last_active_at > (NOW() - INTERVAL 15 MINUTE)')], ['Resources studied (all time)', $c('SELECT COUNT(*) FROM progress')]] as $s) {
        echo '<div class="stat"><b>' . (int)$s[1] . '</b><span>' . e($s[0]) . '</span></div>';
    }
    echo '</div><div class="grid grid--2"><section class="card"><h2>Activity, last 7 days</h2>';
    $days = $pdo->query('SELECT DATE(created_at) d, COUNT(*) n FROM activity_log WHERE created_at >= (CURDATE() - INTERVAL 6 DAY) GROUP BY d ORDER BY d')->fetchAll(PDO::FETCH_KEY_PAIR);
    $max = max(1, $days ? max($days) : 1);
    for ($i = 6; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $n = (int)($days[$d] ?? 0); echo '<p>' . e(date('D j M', strtotime($d))) . ': <strong>' . $n . '</strong><br><progress max="' . $max . '" value="' . $n . '" aria-label="' . e($d) . '"></progress></p>'; }
    echo '</section><section class="card"><h2>Busiest events, last 7 days</h2><div class="table-scroll"><table><thead><tr><th scope="col">Event</th><th scope="col" class="num">Count</th></tr></thead><tbody>';
    foreach ($pdo->query('SELECT event, COUNT(*) n FROM activity_log WHERE created_at >= (CURDATE() - INTERVAL 6 DAY) GROUP BY event ORDER BY n DESC LIMIT 10') as $r) { echo '<tr><td>' . e($r['event']) . '</td><td class="num">' . (int)$r['n'] . '</td></tr>'; }
    echo '</tbody></table></div></section></div><h2>Latest activity</h2><div class="table-scroll"><table><thead><tr><th scope="col">Time</th><th scope="col">Who</th><th scope="col">Event</th><th scope="col">Detail</th></tr></thead><tbody>';
    foreach ($pdo->query('SELECT created_at, actor_type, actor_name, event, detail FROM activity_log ORDER BY id DESC LIMIT 15') as $r) {
        echo '<tr><td>' . e($r['created_at']) . '</td><td>' . e($r['actor_name'] ?: '-') . ' <small>(' . e($r['actor_type']) . ')</small></td><td>' . e($r['event']) . '</td><td>' . e($r['detail']) . '</td></tr>';
    }
    echo '</tbody></table></div><p><a href="admin.php?p=activity">See the full activity log</a></p>';
}

/* ---- Generic sections (courses, units, resources, news, timetable, assessments, fees, applications, messages) ---- */
elseif (isset($ENT[$p])) {
    $cfg = $ENT[$p]; $fields = prepare_fields($cfg['fields']);
    $edit = (int)($_GET['edit'] ?? 0); $row = []; $isNew = $edit === 0;
    if ($edit > 0) {
        $st = $pdo->prepare("SELECT * FROM `{$cfg['table']}` WHERE id = ?"); $st->execute([$edit]); $row = $st->fetch() ?: [];
        if (!$row) { $edit = 0; $isNew = true; }
    }
    $old = $_SESSION['old'] ?? null; unset($_SESSION['old']);
    $canForm = empty($cfg['no_create']) || $edit > 0;
    echo '<h1>' . e($cfg['title']) . '</h1>';
    if (!empty($cfg['help'])) { echo '<p>' . e($cfg['help']) . '</p>'; }
    if ($canForm) {
        echo '<section class="card" id="form"><h2>' . ($isNew ? 'Add new' : 'Edit #' . $edit) . '</h2><form method="post" action="admin.php">' . csrf_field()
            . '<input type="hidden" name="do" value="save"><input type="hidden" name="sec" value="' . e($p) . '"><input type="hidden" name="id" value="' . $edit . '"><div class="form-grid">';
        foreach ($fields as $n => $f) {
            $v = $row[$n] ?? ($f['def'] ?? ''); if ($old && $old[0] === $p && (int)$old[1] === $edit) { $v = $old[2]['f_' . $n] ?? ''; }
            $id = 'f_' . $n; $req = !empty($f['req']) && !array_key_exists('def', $f) && $f['type'] !== 'bool' ? ' required' : '';
            $wide = $f['type'] === 'textarea' ? ' wide' : '';
            echo '<div class="field' . $wide . '"><label for="' . $id . '">' . e($f['label']) . '</label>';
            switch ($f['type']) {
                case 'textarea': echo '<textarea id="' . $id . '" name="' . $id . '" rows="3" maxlength="' . (int)$f['max'] . '"' . $req . '>' . e($v) . '</textarea>'; break;
                case 'int': echo '<input id="' . $id . '" name="' . $id . '" type="number" min="' . (int)($f['min'] ?? 0) . '" max="' . (int)($f['max'] ?? 999999999) . '" value="' . e($v) . '"' . $req . '>'; break;
                case 'date': echo '<input id="' . $id . '" name="' . $id . '" type="date" value="' . e($v) . '"' . $req . '>'; break;
                case 'bool': echo '<input id="' . $id . '" name="' . $id . '" class="chk" type="checkbox" value="1"' . ($v ? ' checked' : '') . '>'; break;
                case 'select': case 'fk':
                    echo '<select id="' . $id . '" name="' . $id . '"' . $req . '>' . ($f['type'] === 'fk' && empty($f['req']) ? '<option value="">None</option>' : '');
                    foreach ($f['_opts'] as $ov => $ol) { echo '<option value="' . e($ov) . '"' . ((string)$v === (string)$ov ? ' selected' : '') . '>' . e($ol) . '</option>'; }
                    echo '</select>'; break;
                default: echo '<input id="' . $id . '" name="' . $id . '" type="' . ($f['type'] === 'email' ? 'email' : ($f['type'] === 'url' ? 'url' : 'text')) . '" maxlength="' . (int)$f['max'] . '" value="' . e($v) . '"' . $req . '>';
            }
            if (!empty($f['help'])) { echo '<p class="hint">' . e($f['help']) . '</p>'; }
            echo '</div>';
        }
        echo '</div><p class="acts"><button class="btn btn-primary" type="submit">' . ($isNew ? 'Add' : 'Save changes') . '</button>' . (!$isNew ? '<a class="btn btn-secondary" href="admin.php?p=' . e($p) . '">Cancel</a>' : '') . '</p></form></section>';
    }
    $rows = $pdo->query("SELECT * FROM `{$cfg['table']}` ORDER BY {$cfg['order']} LIMIT 500")->fetchAll();
    echo '<h2>All records (' . count($rows) . ')</h2><div class="table-scroll"><table><thead><tr>';
    foreach ($cfg['list'] as $col) { echo '<th scope="col">' . e($fields[$col]['label'] ?? ucwords(str_replace('_', ' ', $col))) . '</th>'; }
    echo '<th scope="col">Actions</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($cfg['list'] as $col) { echo '<td>' . list_cell($r, $col, $fields) . '</td>'; }
        echo '<td><div class="acts"><a class="btn btn-secondary btn-sm" href="admin.php?p=' . e($p) . '&amp;edit=' . (int)$r['id'] . '#form">' . (!empty($cfg['no_create']) ? 'Change status' : 'Edit') . '</a>'
            . mini_form('delete', $p, (int)$r['id'], 'Delete', 'btn-danger', 'Delete this record? This cannot be undone.') . '</div>';
        if (!empty($cfg['detail']) && ($r[$cfg['detail']] ?? '') !== '') { echo '<details><summary>Read text</summary><p>' . nl2br(e($r[$cfg['detail']])) . '</p></details>'; }
        echo '</td></tr>';
    }
    if (!$rows) { echo '<tr><td colspan="' . (count($cfg['list']) + 1) . '">Nothing here yet.</td></tr>'; }
    echo '</tbody>';
    if ($p === 'assessments') { $tw = array_sum(array_column($rows, 'weight')); echo '<tfoot><tr><th colspan="2" scope="row">Total weight</th><td>' . $tw . '%</td><td>' . ($tw === 100 ? 'OK' : 'Should be 100%') . '</td><td></td></tr></tfoot>'; }
    echo '</table></div>';
}

/* ---- Students list ---- */
elseif ($p === 'students') {
    $total = max(1, (int)$pdo->query('SELECT COALESCE(SUM(minutes), 0) FROM resources')->fetchColumn());
    $rows = $pdo->query('SELECT s.*, COUNT(p.resource_id) AS done, COALESCE(SUM(r.minutes), 0) AS mins FROM students s LEFT JOIN progress p ON p.student_id = s.id LEFT JOIN resources r ON r.id = p.resource_id GROUP BY s.id ORDER BY s.created_at DESC LIMIT 500')->fetchAll();
    echo '<h1>Students (' . count($rows) . ')</h1><p>Open a student to change their details, reset a forgotten password, remove study marks they did not earn, or block the account. Passwords are stored only as hashes: nobody can read them, so a forgotten one is reset, never looked up.</p>
    <div class="table-scroll"><table><thead><tr><th scope="col">Student</th><th scope="col">Joined</th><th scope="col">Last login</th><th scope="col">Last active</th><th scope="col">Study progress</th><th scope="col">Manage</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $pct = (int)round($r['mins'] / $total * 100);
        echo '<tr><td><strong>' . e($r['display']) . '</strong><br><small>login: ' . e($r['username']) . '</small><br><span class="badge ' . ($r['status'] === 'active' ? 'badge--ok' : 'badge--full') . '">' . e($r['status']) . '</span></td><td>' . e($r['created_at']) . '</td><td>' . e($r['last_login_at'] ?? '-') . '</td><td>' . e($r['last_active_at'] ?? '-') . '</td>
        <td>' . $pct . '%<br><progress max="100" value="' . $pct . '" aria-label="Progress of ' . e($r['display']) . '"></progress><br><small>' . (int)$r['done'] . ' resources, ' . (int)$r['mins'] . ' min</small></td>
        <td><a class="btn btn-primary btn-sm" href="admin.php?p=student&amp;id=' . (int)$r['id'] . '">Manage</a></td></tr>';
    }
    if (!$rows) { echo '<tr><td colspan="6">No students yet.</td></tr>'; }
    echo '</tbody></table></div>';
}

/* ---- One student: details, password reset, progress check ---- */
elseif ($p === 'student') {
    $sid = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM students WHERE id = ?'); $st->execute([$sid]); $s = $st->fetch();
    if (!$s) { echo '<h1>Student not found</h1><p><a href="admin.php?p=students">Back to students</a></p>'; }
    else {
        $sf = static fn(string $do, array $hid = []): string => '<form method="post" action="admin.php">' . csrf_field() . '<input type="hidden" name="do" value="' . e($do) . '"><input type="hidden" name="id" value="' . (int)$_GET['id'] . '">'
            . implode('', array_map(static fn($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">', array_keys($hid), $hid));
        $q = $pdo->prepare('SELECT c.code, c.title ctitle, u.title utitle, r.id rid, r.title, r.kind, r.minutes, p.studied_at, v.reason, v.revoked_by, v.revoked_at
            FROM resources r JOIN units u ON u.id = r.unit_id JOIN courses c ON c.id = u.course_id
            LEFT JOIN progress p ON p.resource_id = r.id AND p.student_id = ?
            LEFT JOIN progress_revoked v ON v.resource_id = r.id AND v.student_id = ?
            ORDER BY c.sort_order, c.code, u.sort_order, u.id, r.sort_order, r.id');
        $q->execute([$sid, $sid]); $courses = []; $allT = 0; $allD = 0;
        foreach ($q as $r) {
            $c = &$courses[$r['code']]; $c['title'] = $r['ctitle']; $c['rows'][] = $r; $c['t'] = ($c['t'] ?? 0) + (int)$r['minutes']; $c['d'] = ($c['d'] ?? 0) + ($r['studied_at'] ? (int)$r['minutes'] : 0); unset($c);
            $allT += (int)$r['minutes']; $allD += $r['studied_at'] ? (int)$r['minutes'] : 0;
        }
        $allP = $allT ? (int)round($allD / $allT * 100) : 0;
        echo '<p><a href="admin.php?p=students">&larr; All students</a></p><h1>' . e($s['display']) . ' <span class="badge ' . ($s['status'] === 'active' ? 'badge--ok' : 'badge--full') . '">' . e($s['status']) . '</span></h1>
        <p>Joined ' . e($s['created_at']) . '. Last login ' . e($s['last_login_at'] ?? 'never') . '. Last active ' . e($s['last_active_at'] ?? 'never') . '. Overall progress <strong>' . $allP . '%</strong> (' . $allD . ' of ' . $allT . ' minutes).</p>
        <div class="grid grid--2">
        <section class="card"><h2>Student details</h2>' . $sf('student_details') . '<div class="field"><label for="un">Login username</label><input id="un" name="username" required maxlength="30" value="' . e($s['username']) . '"></div>
          <div class="field"><label for="dp">Display name</label><input id="dp" name="display" required maxlength="30" value="' . e($s['display']) . '"></div>
          <div class="field"><label for="sts">Account status</label><select id="sts" name="status"><option value="active"' . ($s['status'] === 'active' ? ' selected' : '') . '>active</option><option value="blocked"' . ($s['status'] === 'blocked' ? ' selected' : '') . '>blocked (cannot log in)</option></select></div>
          <p class="hint">Changing the username logs the student out of every device.</p><button class="btn btn-primary" type="submit">Save details</button></form></section>
        <section class="card"><h2>Reset password</h2><p>For a student who forgot their password. Their old logins stop working at once.</p>' . $sf('student_password') . '
          <div class="field"><label for="npw">New password</label><input id="npw" name="new_password" type="password" maxlength="64" autocomplete="new-password"><p class="hint">6 to 64 characters, letters and a number.</p></div>
          <div class="field"><label><input class="chk" type="checkbox" name="generate" value="1"> Generate a temporary password instead (shown once)</label></div>
          <div class="field"><label><input class="chk" type="checkbox" data-showpw="#npw"> Show password</label></div>
          <button class="btn btn-primary" type="submit">Reset password</button></form></section></div>
        <h2>Study progress check</h2><p>If a student ticked a resource without studying it, remove the tick here. Their progress drops straight away. With "Lock" ticked the student cannot tick it again until you unlock it, and they see your reason.</p>';
        foreach ($courses as $code => $c) {
            $cp = $c['t'] ? (int)round($c['d'] / $c['t'] * 100) : 0;
            echo '<details' . ($c['d'] ? ' open' : '') . '><summary>' . e($code . ' ' . $c['title']) . ': ' . $cp . '% (' . (int)$c['d'] . ' of ' . (int)$c['t'] . ' min)</summary><div class="table-scroll"><table><thead><tr><th scope="col">Resource</th><th scope="col">Type</th><th scope="col">Minutes</th><th scope="col">Status</th><th scope="col">Admin action</th></tr></thead><tbody>';
            foreach ($c['rows'] as $r) {
                echo '<tr><td>' . e($r['title']) . '<br><small>' . e($r['utitle']) . '</small></td><td>' . e($r['kind']) . '</td><td class="num">' . (int)$r['minutes'] . '</td><td>';
                echo $r['studied_at'] ? '<span class="badge badge--ok">Studied</span><br><small>' . e($r['studied_at']) . '</small>' : ($r['reason'] !== null ? '<span class="badge badge--full">Locked</span><br><small>' . e($r['reason']) . ' (' . e($r['revoked_by']) . ', ' . e($r['revoked_at']) . ')</small>' : 'Not studied');
                echo '</td><td>';
                if ($r['studied_at']) {
                    echo $sf('student_revoke', ['rid' => $r['rid']]) . '<div class="acts"><label class="visually-hidden" for="rs' . (int)$r['rid'] . '">Reason</label><input id="rs' . (int)$r['rid'] . '" name="reason" required minlength="3" maxlength="200" placeholder="Reason, e.g. video not watched">
                    <label><input class="chk" type="checkbox" name="lock" value="1" checked> Lock</label><button class="btn btn-danger btn-sm" type="submit">Not studied</button></div></form>';
                } elseif ($r['reason'] !== null) {
                    echo $sf('student_unlock', ['rid' => $r['rid']]) . '<button class="btn btn-secondary btn-sm" type="submit">Unlock</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div></details>';
        }
        echo '<section class="card"><h2>Danger zone</h2><div class="acts"><form method="post" action="admin.php" data-confirm="Clear ALL study progress for this student?">' . csrf_field() . '<input type="hidden" name="do" value="student_reset_progress"><input type="hidden" name="id" value="' . $sid . '"><button class="btn btn-danger" type="submit">Clear all progress</button></form>'
            . '<form method="post" action="admin.php" data-confirm="Delete this student and all their progress? This cannot be undone.">' . csrf_field() . '<input type="hidden" name="do" value="student_delete"><input type="hidden" name="id" value="' . $sid . '"><button class="btn btn-danger" type="submit">Delete student</button></form></div></section>';
    }
}

/* ---- Timetable: weekly grid with editable class times ---- */
elseif ($p === 'timetable') {
    $opts = $pdo->query("SELECT id, CONCAT(code, ' - ', title) FROM courses ORDER BY code")->fetchAll(PDO::FETCH_KEY_PAIR);
    $cur = []; $n = 0;
    foreach ($pdo->query('SELECT day, slot, course_id FROM timetable') as $t) { $cur[$t['day'] . '_' . $t['slot']] = $t['course_id']; if ($t['course_id']) { $n++; } }
    $tm = slot_times(); $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'];
    echo '<h1>Live class timetable</h1><p>Choose the course for each slot (or "No class") and set the class times (East Africa Time). Students see the red <strong>LIVE NOW</strong> button during these times. Currently <strong>' . $n . '</strong> live classes a week.</p>
    <form method="post" action="admin.php">' . csrf_field() . '<input type="hidden" name="do" value="timetable_save"><div class="table-scroll"><table><caption>Weekly timetable</caption><thead><tr><th scope="col">Day</th>';
    foreach ([1, 2, 3] as $sl) {
        echo '<th scope="col">Slot ' . $sl . '<br><label class="visually-hidden" for="s' . $sl . 'a">Slot ' . $sl . ' start</label><input id="s' . $sl . 'a" name="s' . $sl . '_start" type="time" required value="' . e($tm[$sl][0]) . '"> to
          <label class="visually-hidden" for="s' . $sl . 'b">Slot ' . $sl . ' end</label><input id="s' . $sl . 'b" name="s' . $sl . '_end" type="time" required value="' . e($tm[$sl][1]) . '"></th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($days as $d => $dn) {
        echo '<tr><th scope="row">' . $dn . '</th>';
        foreach ([1, 2, 3] as $sl) {
            $v = (string)($cur[$d . '_' . $sl] ?? '');
            echo '<td><label class="visually-hidden" for="t' . $d . $sl . '">' . $dn . ' slot ' . $sl . '</label><select id="t' . $d . $sl . '" name="t_' . $d . '_' . $sl . '"><option value="">No class</option>';
            foreach ($opts as $id => $label) { echo '<option value="' . (int)$id . '"' . ((string)$id === $v ? ' selected' : '') . '>' . e($label) . '</option>'; }
            echo '</select></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div><p><button class="btn btn-primary" type="submit">Save timetable</button></p></form>';
}

/* ---- Activity log ---- */
elseif ($p === 'activity') {
    [$wh, $ar] = activity_filters();
    $per = 50; $pg = max(1, (int)($_GET['pg'] ?? 1));
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM activity_log' . $wh); $cnt->execute($ar); $total = (int)$cnt->fetchColumn();
    $st = $pdo->prepare('SELECT * FROM activity_log' . $wh . ' ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($pg - 1) * $per)); $st->execute($ar);
    $events = $pdo->query('SELECT DISTINCT event FROM activity_log ORDER BY event')->fetchAll(PDO::FETCH_COLUMN);
    $q = static fn(array $extra = []): string => http_build_query(array_filter(array_merge(['p' => 'activity', 'ev' => $_GET['ev'] ?? '', 'actor' => $_GET['actor'] ?? '', 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? ''], $extra), static fn($v) => $v !== '' && $v !== null));
    echo '<h1>Activity log (' . $total . ')</h1><p>Every login, page view, resource studied, application, message and admin change. IP addresses are kept for security; remove old entries below to protect privacy.</p>
    <form method="get" action="admin.php" class="filters"><input type="hidden" name="p" value="activity">
      <div class="field"><label for="ev">Event</label><select id="ev" name="ev"><option value="">All events</option>';
    foreach ($events as $ev) { echo '<option value="' . e($ev) . '"' . (($_GET['ev'] ?? '') === $ev ? ' selected' : '') . '>' . e($ev) . '</option>'; }
    echo '</select></div><div class="field"><label for="actor">Who</label><input id="actor" name="actor" maxlength="40" value="' . e($_GET['actor'] ?? '') . '"></div>
      <div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="' . e($_GET['from'] ?? '') . '"></div>
      <div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="' . e($_GET['to'] ?? '') . '"></div>
      <button class="btn btn-primary" type="submit">Filter</button><a class="btn btn-secondary" href="admin.php?' . e($q(['export' => 1])) . '">Download CSV</a></form>
    <div class="table-scroll"><table><thead><tr><th scope="col">Time</th><th scope="col">Who</th><th scope="col">Event</th><th scope="col">Detail</th><th scope="col">IP</th></tr></thead><tbody>';
    foreach ($st as $r) { echo '<tr><td>' . e($r['created_at']) . '</td><td>' . e($r['actor_name'] ?: '-') . ' <small>(' . e($r['actor_type']) . ')</small></td><td>' . e($r['event']) . '</td><td>' . e($r['detail']) . '</td><td class="mono">' . e($r['ip']) . '</td></tr>'; }
    if (!$total) { echo '<tr><td colspan="5">No activity matches.</td></tr>'; }
    echo '</tbody></table></div><div class="pager">';
    if ($pg > 1) { echo '<a class="btn btn-secondary btn-sm" href="admin.php?' . e($q(['pg' => $pg - 1])) . '">Newer</a>'; }
    echo '<span>Page ' . $pg . ' of ' . max(1, (int)ceil($total / $per)) . '</span>';
    if ($pg * $per < $total) { echo '<a class="btn btn-secondary btn-sm" href="admin.php?' . e($q(['pg' => $pg + 1])) . '">Older</a>'; }
    echo '</div><section class="card"><h2>Remove old entries</h2><form method="post" action="admin.php" class="acts" data-confirm="Permanently delete old log entries?">' . csrf_field() . '<input type="hidden" name="do" value="purge_log">
      <label for="days">Older than</label><select id="days" name="days"><option value="30">30 days</option><option value="90">90 days</option><option value="180">180 days</option><option value="365">1 year</option></select>
      <button class="btn btn-danger btn-sm" type="submit">Delete old entries</button></form></section>';
}

/* ---- Settings ---- */
elseif ($p === 'settings') {
    echo '<h1>Settings</h1><section class="card"><form method="post" action="admin.php">' . csrf_field() . '<input type="hidden" name="do" value="settings_save"><div class="form-grid">
      <div class="field"><label for="sd">Semester start (a Monday)</label><input id="sd" name="semester_start" type="date" required value="' . e(setting('semester_start', '2026-10-19')) . '"><p class="hint">Week 1 starts here. CAT, assignment and exam dates are counted from it.</p></div>
      <div class="field"><label for="mu">Live class link</label><input id="mu" name="meet_url" type="url" required maxlength="500" value="' . e(setting('meet_url')) . '"><p class="hint">Opened by the "Join class" button.</p></div>
      <div class="field"><label for="wa">WhatsApp support number</label><input id="wa" name="whatsapp" type="text" required maxlength="20" value="' . e(setting('whatsapp')) . '"><p class="hint">Country code, no +, e.g. 254712345678.</p></div></div>
      <p><button class="btn btn-primary" type="submit">Save settings</button></p></form></section>';
}

/* ---- Admins ---- */
elseif ($p === 'admins') {
    echo '<h1>Administrators</h1><div class="table-scroll"><table><thead><tr><th scope="col">Username</th><th scope="col">Created</th><th scope="col">Last login</th><th scope="col">Actions</th></tr></thead><tbody>';
    foreach ($pdo->query('SELECT id, username, created_at, last_login_at FROM admins ORDER BY id') as $r) {
        echo '<tr><td>' . e($r['username']) . ((int)$r['id'] === admin_id() ? ' (you)' : '') . '</td><td>' . e($r['created_at']) . '</td><td>' . e($r['last_login_at'] ?? '-') . '</td><td>'
            . ((int)$r['id'] === admin_id() ? '' : mini_form('admin_delete', '', (int)$r['id'], 'Delete', 'btn-danger', 'Delete this administrator?')) . '</td></tr>';
    }
    echo '</tbody></table></div><section class="card"><h2>Add an administrator</h2><form method="post" action="admin.php">' . csrf_field() . '<input type="hidden" name="do" value="admin_add"><div class="form-grid">
      <div class="field"><label for="nu">Username</label><input id="nu" name="username" required maxlength="30" autocomplete="off"></div>
      <div class="field"><label for="np">Password (10+ characters, letters and numbers)</label><input id="np" name="password" type="password" required minlength="10" maxlength="72" autocomplete="new-password"></div></div>
      <p><label><input class="chk" type="checkbox" data-showpw="#np"> Show password</label></p><p><button class="btn btn-primary" type="submit">Add administrator</button></p></form></section>';
}

echo '</div></main>';
tail_html($nonce);
