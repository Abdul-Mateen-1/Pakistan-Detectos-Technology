<?php
/**
 * ============================================================================
 *  contact.php — Inquiry form handler for Pakistan Detectors Technology
 * ============================================================================
 *
 *  WHAT THIS IS
 *      A single-file, dependency-free endpoint for the website contact form.
 *      No framework, no Composer, no database — only PHP's built-in mail().
 *      Requires PHP 7.4+ (every current Hostinger plan ships 7.4–8.x).
 *
 *  DEPLOYMENT (Hostinger)
 *      In this repo the file lives at  public/api/contact.php  so that
 *      `astro build` copies it verbatim to  dist/api/contact.php .
 *      Upload the contents of dist/ to public_html/ and it becomes:
 *
 *          public_html/api/contact.php  →  https://yourdomain.com/api/contact.php
 *
 *  HOW THE FORM CALLS IT
 *      fetch("/api/contact.php", { method: "POST", body: new FormData(form) })
 *
 *  ACCEPTS   POST only (form-encoded, multipart, or JSON body).
 *            Any other method — including direct GET access — gets a 405.
 *  RETURNS   JSON only:  { "success": true|false, "message": "..." }
 *
 *  QUICK TEST (after deploying)
 *      curl -X POST https://yourdomain.com/api/contact.php \
 *        -d "fullName=Test User" -d "email=test@example.com" \
 *        -d "phone=+92 311 1234567" -d "inquiryType=General inquiry" \
 *        -d "message=This is a test message from curl."
 *
 *  DELIVERABILITY
 *      FROM_EMAIL must be an address on the domain this script is hosted on
 *      (Hostinger hPanel → Emails — creating the mailbox also sets up
 *      SPF/DKIM). Never put the visitor's address in From: — that fails
 *      SPF/DKIM and lands in spam; the visitor's address goes in Reply-To.
 * ============================================================================
 */

declare(strict_types=1);

/* ==========================================================================
 * 1. CONFIGURATION — everything you might want to change lives here
 * ======================================================================== */

/** Inbox that receives the inquiries. */
const RECIPIENT_EMAIL = 'abdul.mateen1771@gmail.com';

/** Sender identity — MUST be on your own domain (see note above). */
const FROM_EMAIL = 'noreply@metaldetectors.pk';
const FROM_NAME  = 'Pakistan Detectors Technology';

/** Prefix for the email subject line. */
const SUBJECT_PREFIX = 'New Website Inquiry';

/** Timezone used for the timestamp shown inside the email. */
const TIMEZONE = 'Asia/Karachi';

/** Name of the hidden form field bots tend to fill in; humans never see it. */
const HONEYPOT_FIELD = 'botcheck';

/** Minimum seconds between submissions from the same browser session. */
const COOLDOWN_SECONDS = 30;

/** The options the form's <select> offers — anything else is rejected. */
const ALLOWED_INQUIRY_TYPES = [
    'Buying a gold detector',
    'Pricing & availability',
    'Shipping & delivery',
    'Technical support',
    'Warranty & maintenance',
    'General inquiry',
];

/** Maximum accepted length per field (characters). */
const MAX_LENGTHS = [
    'fullName' => 100,
    'email'    => 254,
    'phone'    => 30,
    'country'  => 100,
    'city'     => 100,
    'message'  => 5000,
];

/** Exact success message the frontend displays. */
const SUCCESS_MESSAGE = 'Your enquiry has been sent successfully.';

/* ==========================================================================
 * 2. HELPERS
 * ======================================================================== */

/**
 * Send a JSON response and stop execution.
 * $extra lets validation attach a per-field "errors" map for the frontend.
 */
function respond(int $status, bool $success, string $message, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/** Read one field from the parsed input, tolerating missing/non-string values. */
function field(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    return is_scalar($value) ? (string) $value : '';
}

/**
 * Sanitize a single-line value: strip ASCII control characters (which is
 * what makes header injection impossible — CR/LF can never survive this),
 * collapse whitespace runs, and trim.
 */
function clean_line(string $value): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '';
    return trim(preg_replace('/\s{2,}/', ' ', $value) ?? '');
}

/**
 * Sanitize a multi-line value (the message): normalize line endings to \n,
 * strip every other control character, cap runs of blank lines, and trim.
 */
function clean_multiline(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    $value = preg_replace("/\n{4,}/", "\n\n\n", $value) ?? '';
    return trim($value);
}

/** Multibyte-aware character count with a plain fallback. */
function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/**
 * Make a value safe for use in an email header (Subject, display names).
 * Control characters are removed (anti header-injection, again), plain
 * ASCII passes through readable, anything else is RFC 2047 encoded.
 */
function encode_mime_header(string $value): string
{
    $value = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '');
    if ($value === '' || preg_match('/^[\x20-\x7E]+$/', $value)) {
        return $value;
    }
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/** Build a `Display Name <email>` header value with proper quoting/encoding. */
function format_address(string $name, string $email): string
{
    $name = encode_mime_header($name);
    // Quote a plain-ASCII display name if it contains RFC 5322 specials.
    if ($name !== '' && strpos($name, '=?') !== 0 && preg_match('/[^A-Za-z0-9 .\-]/', $name)) {
        $name = '"' . addcslashes($name, '"\\') . '"';
    }
    return $name === '' ? $email : $name . ' <' . $email . '>';
}

/** HTML-escape a value for the HTML email body (anti-XSS in mail clients). */
function esc_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** One label/value row for the HTML email's details table. */
function detail_row(string $label, string $value, string $href = ''): string
{
    $display = $value === '' ? '&mdash;' : esc_html($value);
    if ($href !== '' && $value !== '') {
        $display = '<a href="' . esc_html($href) . '" style="color:#B8860B;text-decoration:none;">' . $display . '</a>';
    }
    return '<tr>'
        . '<td style="padding:10px 16px;border-bottom:1px solid #EEEEEE;font:600 13px/1.4 Arial,Helvetica,sans-serif;color:#666666;white-space:nowrap;vertical-align:top;">' . esc_html($label) . '</td>'
        . '<td style="padding:10px 16px;border-bottom:1px solid #EEEEEE;font:400 14px/1.5 Arial,Helvetica,sans-serif;color:#222222;width:100%;">' . $display . '</td>'
        . '</tr>';
}

/* ==========================================================================
 * 3. RESPONSE HEADERS — always JSON, never cached
 * ======================================================================== */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/* ==========================================================================
 * 4. METHOD GATE — POST only; direct GET access (or anything else) → 405
 * ======================================================================== */

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'Method not allowed — this endpoint only accepts POST requests.');
}

/* ==========================================================================
 * 5. READ INPUT — regular form posts land in $_POST; JSON bodies are parsed
 * ======================================================================== */

$input = $_POST;
if ($input === []) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
}

if ($input === []) {
    respond(400, false, 'Empty submission — please fill out the form and try again.');
}

/* ==========================================================================
 * 6. HONEYPOT — bots fill the hidden field; humans can't see it.
 *    Reply with a fake success so bots don't learn they were caught.
 * ======================================================================== */

if (trim(field($input, HONEYPOT_FIELD)) !== '') {
    respond(200, true, SUCCESS_MESSAGE);
}

/* ==========================================================================
 * 7. RATE LIMIT (optional) — one submission per COOLDOWN_SECONDS per
 *    browser session. Best-effort only; delete this block to disable.
 * ======================================================================== */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (time() - (int) ($_SESSION['contact_last_sent'] ?? 0) < COOLDOWN_SECONDS) {
    respond(429, false, 'You are sending messages too quickly — please wait a moment and try again.');
}

/* ==========================================================================
 * 8. COLLECT + SANITIZE
 * ======================================================================== */

$fullName    = clean_line(field($input, 'fullName'));
$email       = clean_line(field($input, 'email'));
$phone       = clean_line(field($input, 'phone'));
$inquiryType = clean_line(field($input, 'inquiryType'));
$country     = clean_line(field($input, 'country'));   // optional
$city        = clean_line(field($input, 'city'));      // optional
$message     = clean_multiline(field($input, 'message'));

/* ==========================================================================
 * 9. VALIDATE — collect every problem, respond with the first one plus a
 *    per-field map the frontend can use for highlighting later.
 * ======================================================================== */

$errors = [];

if ($fullName === '') {
    $errors['fullName'] = 'Please enter your full name.';
} elseif (text_length($fullName) > MAX_LENGTHS['fullName']) {
    $errors['fullName'] = 'Full name must be ' . MAX_LENGTHS['fullName'] . ' characters or fewer.';
}

if ($email === '') {
    $errors['email'] = 'Please enter your email address.';
} elseif (text_length($email) > MAX_LENGTHS['email']) {
    $errors['email'] = 'Email address must be ' . MAX_LENGTHS['email'] . ' characters or fewer.';
} elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $errors['email'] = 'Please enter a valid email address.';
}

if ($phone === '') {
    $errors['phone'] = 'Please enter your phone or WhatsApp number.';
} elseif (
    text_length($phone) > MAX_LENGTHS['phone']
    || !preg_match('/^[0-9+\-().\x20]+$/', $phone)
    || preg_match_all('/[0-9]/', $phone) < 7
) {
    $errors['phone'] = 'Please enter a valid phone / WhatsApp number.';
}

if (!in_array($inquiryType, ALLOWED_INQUIRY_TYPES, true)) {
    $errors['inquiryType'] = 'Please select an inquiry type.';
}

if ($country !== '' && text_length($country) > MAX_LENGTHS['country']) {
    $errors['country'] = 'Country must be ' . MAX_LENGTHS['country'] . ' characters or fewer.';
}

if ($city !== '' && text_length($city) > MAX_LENGTHS['city']) {
    $errors['city'] = 'City must be ' . MAX_LENGTHS['city'] . ' characters or fewer.';
}

if ($message === '') {
    $errors['message'] = 'Please enter your message.';
} elseif (text_length($message) < 10) {
    $errors['message'] = 'Please tell us a little more — at least 10 characters.';
} elseif (text_length($message) > MAX_LENGTHS['message']) {
    $errors['message'] = 'Message must be ' . MAX_LENGTHS['message'] . ' characters or fewer.';
}

if ($errors !== []) {
    respond(400, false, (string) reset($errors), ['errors' => $errors]);
}

/* ==========================================================================
 * 10. BUILD THE EMAIL — multipart/alternative: plain text + HTML.
 *     Both parts are base64-encoded, which sidesteps every line-length
 *     and 8-bit transport issue regardless of the server's MTA.
 * ======================================================================== */

date_default_timezone_set(TIMEZONE);

$sentAt = date('d M Y, g:i A (T)');
$ip     = clean_line((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$page   = substr(clean_line((string) ($_SERVER['HTTP_REFERER'] ?? '')), 0, 300);

$subject = SUBJECT_PREFIX . ' — ' . $inquiryType . ' — ' . $fullName;

/* ---- Plain-text version ---- */

$textBody = SUBJECT_PREFIX . "\n"
    . str_repeat('=', 46) . "\n\n"
    . 'Full Name:        ' . $fullName . "\n"
    . 'Email:            ' . $email . "\n"
    . 'Phone / WhatsApp: ' . $phone . "\n"
    . 'Inquiry Type:     ' . $inquiryType . "\n"
    . 'Country:          ' . ($country !== '' ? $country : '—') . "\n"
    . 'City:             ' . ($city !== '' ? $city : '—') . "\n\n"
    . "Message:\n"
    . str_repeat('-', 46) . "\n"
    . $message . "\n"
    . str_repeat('-', 46) . "\n\n"
    . 'Sent: ' . $sentAt . "\n"
    . 'IP: ' . $ip . "\n"
    . ($page !== '' ? 'Page: ' . $page . "\n" : '')
    . "\nReply directly to this email to answer " . $fullName . ".\n";

/* ---- HTML version (all user data escaped via esc_html / detail_row) ---- */

$telHref = 'tel:' . preg_replace('/[^0-9+]/', '', $phone);

$htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
    . '<body style="margin:0;padding:24px;background:#F4F4F5;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
    . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#FFFFFF;border:1px solid #E4E4E7;border-radius:8px;overflow:hidden;">'
    // Header bar
    . '<tr><td style="background:#111827;padding:20px 24px;">'
    . '<div style="font:700 16px/1.3 Arial,Helvetica,sans-serif;color:#D4A537;">' . esc_html(FROM_NAME) . '</div>'
    . '<div style="font:400 13px/1.4 Arial,Helvetica,sans-serif;color:#9CA3AF;margin-top:2px;">' . esc_html(SUBJECT_PREFIX) . '</div>'
    . '</td></tr>'
    // Details table
    . '<tr><td style="padding:8px 8px 0;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
    . detail_row('Full Name', $fullName)
    . detail_row('Email', $email, 'mailto:' . $email)
    . detail_row('Phone / WhatsApp', $phone, $telHref)
    . detail_row('Inquiry Type', $inquiryType)
    . detail_row('Country', $country)
    . detail_row('City', $city)
    . '</table></td></tr>'
    // Message block
    . '<tr><td style="padding:16px 24px 8px;">'
    . '<div style="font:600 13px/1.4 Arial,Helvetica,sans-serif;color:#666666;margin-bottom:6px;">Message</div>'
    . '<div style="font:400 14px/1.6 Arial,Helvetica,sans-serif;color:#222222;background:#FAFAFA;border:1px solid #EEEEEE;border-radius:6px;padding:14px 16px;">'
    . nl2br(esc_html($message))
    . '</div></td></tr>'
    // Meta footer
    . '<tr><td style="padding:14px 24px 20px;font:400 12px/1.6 Arial,Helvetica,sans-serif;color:#999999;">'
    . 'Sent ' . esc_html($sentAt) . ' &nbsp;&middot;&nbsp; IP ' . esc_html($ip)
    . ($page !== '' ? ' &nbsp;&middot;&nbsp; Page ' . esc_html($page) : '')
    . '<br>Reply directly to this email to answer ' . esc_html($fullName) . '.'
    . '</td></tr>'
    . '</table></td></tr></table></body></html>';

/* ---- Assemble the multipart body ---- */

$boundary = 'np_' . bin2hex(random_bytes(16));

$body = '--' . $boundary . "\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n"
    . "Content-Transfer-Encoding: base64\r\n\r\n"
    . chunk_split(base64_encode($textBody))
    . '--' . $boundary . "\r\n"
    . "Content-Type: text/html; charset=UTF-8\r\n"
    . "Content-Transfer-Encoding: base64\r\n\r\n"
    . chunk_split(base64_encode($htmlBody))
    . '--' . $boundary . "--\r\n";

/*
 * Headers as an array (PHP 7.2+): PHP joins them correctly and applies its
 * own header-injection protection on top of our sanitization.
 * From = our own domain (SPF/DKIM alignment); Reply-To = the visitor, so
 * hitting "Reply" in the inbox answers them directly.
 */
$headers = [
    'From'         => format_address(FROM_NAME, FROM_EMAIL),
    'Reply-To'     => format_address($fullName, $email),
    'MIME-Version' => '1.0',
    'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
];

/* ==========================================================================
 * 11. SEND — the -f flag sets the envelope sender (helps deliverability on
 *     Hostinger); if the server rejects the flag, retry once without it.
 * ======================================================================== */

$sent = @mail(RECIPIENT_EMAIL, encode_mime_header($subject), $body, $headers, '-f' . FROM_EMAIL);
if (!$sent) {
    $sent = @mail(RECIPIENT_EMAIL, encode_mime_header($subject), $body, $headers);
}

if (!$sent) {
    respond(500, false, 'Sorry — your enquiry could not be sent right now. Please try again shortly, or contact us directly on WhatsApp.');
}

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['contact_last_sent'] = time();
}

respond(200, true, SUCCESS_MESSAGE);
