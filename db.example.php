<?php
declare(strict_types=1);
/* ============================================================
   db.php - database connection and shared helpers
   Used by admin.php and api.php. Never open this file in a browser directly.
   ============================================================ */

/* ---- Settings: XAMPP defaults. Change DB_PASS if you set a MySQL root password. ---- */
const DB_HOST = '127.0.0.1';
const DB_NAME = 'edusphere';
const DB_USER = 'root';
const DB_PASS = '';
/* Needed ONCE to create the first admin on admin.php. Change it before you start, then it is never used again. */
const ADMIN_SETUP_KEY = 'your_secret_key_here';

date_default_timezone_set('Africa/Nairobi');
ini_set('display_errors', '0');          // never show PHP errors to visitors (they can leak paths and queries)
ini_set('log_errors', '1');
error_reporting(E_ALL);

/* PDO connection: prepared statements only, real (not emulated) prepares, exceptions on errors. */
function pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET time_zone = '+03:00'");   // East Africa Time, so NOW() matches the portal clock
        } catch (PDOException $e) {
            error_log('EduSphere DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            if (defined('IS_API')) {
                header('Content-Type: application/json');
                exit('{"ok":false,"msg":"Database unavailable"}');
            }
            exit('Database connection failed. Start MySQL in the XAMPP control panel and import database.sql in phpMyAdmin.');
        }
    }
    return $pdo;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
}

/* Session cookie: HttpOnly (JavaScript cannot read it), SameSite=Lax (blocks most cross-site posts), Secure on HTTPS. */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    ini_set('session.use_strict_mode', '1');
    session_name('edusphere');
    session_set_cookie_params(['lifetime' => 0, 'path' => $path, 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/* Escape text for HTML output (stops stored XSS). */
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* REMOTE_ADDR only: X-Forwarded-For can be forged by the visitor. */
function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/* Write one row to the activity log. Never lets a logging problem break the page. */
function log_activity(string $type, string $actor, string $event, string $detail = ''): void
{
    try {
        pdo()->prepare('INSERT INTO activity_log (actor_type, actor_name, event, detail, ip, agent) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$type, mb_substr($actor, 0, 40), mb_substr($event, 0, 40), mb_substr($detail, 0, 500), client_ip(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
    } catch (Throwable $ex) {
        error_log('log_activity failed: ' . $ex->getMessage());
    }
}

/* How many times has this IP produced this event recently? Used to slow down password guessing and form spam. */
function recent_count(string $event, int $minutes): int
{
    $st = pdo()->prepare('SELECT COUNT(*) FROM activity_log WHERE event = ? AND ip = ? AND created_at > (NOW() - INTERVAL ' . (int)$minutes . ' MINUTE)');
    $st->execute([$event, client_ip()]);
    return (int)$st->fetchColumn();
}

function setting(string $key, string $default = ''): string
{
    $st = pdo()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    $t = $_POST['csrf'] ?? '';
    return is_string($t) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}
