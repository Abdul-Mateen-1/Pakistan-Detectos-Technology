<?php
/**
 * newsletter.php — Newsletter signup handler for Pakistan Detectors Technology
 *
 * Companion to contact.php (same deployment: lands at public_html/api/).
 * There is no mailing-list service connected — each signup is delivered as
 * an email notification to the business inbox, which acts as the list until
 * a real service (Mailchimp/ConvertKit/etc.) is wired in.
 *
 * Sends via authenticated SMTP (see smtp-mailer.php) rather than PHP's
 * built-in mail() — mail() is disabled on this host. Requires
 * smtp-config.php (gitignored, real credentials) alongside this file on
 * the server — see smtp-config.example.php for the template.
 *
 * ACCEPTS   POST only ({ email, botcheck } form-encoded).
 * RETURNS   JSON: { "success": true|false, "message": "..." }
 * REQUIRES  PHP 7.4+.
 */

declare(strict_types=1);

/* ---- Configuration ---- */
const RECIPIENT_EMAIL  = 'abdul.mateen1771@gmail.com';
const FROM_NAME        = 'Pakistan Detectors Technology';
const HONEYPOT_FIELD   = 'botcheck';
const COOLDOWN_SECONDS = 30;
const SUCCESS_MESSAGE  = "Thanks — you're on the list. We'll be in touch.";

/* ---- Helpers ---- */
function respond(int $status, bool $success, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- Response headers ---- */
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/* ---- SMTP config (mail() is disabled on this host) ---- */
$smtpConfigPath = __DIR__ . '/smtp-config.php';
if (!is_file($smtpConfigPath)) {
    respond(500, false, 'Mail is not configured on this server yet.');
}
require $smtpConfigPath;
require __DIR__ . '/smtp-mailer.php';

/* ---- POST only ---- */
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'Method not allowed — this endpoint only accepts POST requests.');
}

/* ---- Honeypot: bots fill the hidden field; reply with a fake success ---- */
if (trim((string) ($_POST[HONEYPOT_FIELD] ?? '')) !== '') {
    respond(200, true, SUCCESS_MESSAGE);
}

/* ---- Rate limit (best-effort, per browser session) ---- */
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (time() - (int) ($_SESSION['newsletter_last_sent'] ?? 0) < COOLDOWN_SECONDS) {
    respond(429, false, 'You are subscribing too quickly — please wait a moment and try again.');
}

/* ---- Validate the email (control chars stripped = no header injection) ---- */
$email = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($_POST['email'] ?? '')) ?? '');
if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    respond(400, false, 'Please enter a valid email address.');
}

/* ---- Notify the business inbox ---- */
date_default_timezone_set('Asia/Karachi');

$body = "New newsletter subscription from the website:\n\n"
    . 'Email: ' . $email . "\n\n"
    . 'Sent: ' . date('d M Y, g:i A (T)') . "\n"
    . 'IP: ' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n"
    . ((string) ($_SERVER['HTTP_REFERER'] ?? '') !== '' ? 'Page: ' . substr((string) $_SERVER['HTTP_REFERER'], 0, 300) . "\n" : '');

$headers = [
    'Date'         => date('r'),
    'Message-ID'   => '<' . bin2hex(random_bytes(16)) . '@' . substr(SMTP_USERNAME, strpos(SMTP_USERNAME, '@') + 1) . '>',
    'From'         => FROM_NAME . ' <' . SMTP_USERNAME . '>',
    'To'           => RECIPIENT_EMAIL,
    'Reply-To'     => $email, // validated above, cannot contain CR/LF
    'Subject'      => 'New Newsletter Subscription',
    'MIME-Version' => '1.0',
    'Content-Type' => 'text/plain; charset=UTF-8',
];

$result = smtp_send(SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD, SMTP_USERNAME, RECIPIENT_EMAIL, $headers, $body);

if (!$result['success']) {
    respond(500, false, 'Sorry — the subscription could not be processed right now. Please try again later.');
}

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['newsletter_last_sent'] = time();
}

respond(200, true, SUCCESS_MESSAGE);
