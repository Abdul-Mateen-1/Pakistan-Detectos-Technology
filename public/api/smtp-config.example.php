<?php
/**
 * smtp-config.example.php — TEMPLATE for SMTP credentials.
 *
 * Copy this file to smtp-config.php (same folder) and fill in the real
 * values for your mailbox. smtp-config.php is gitignored — never commit
 * real SMTP passwords to the repo.
 *
 * Find these values in your hosting control panel: Email Accounts →
 * (your mailbox) → "Connect Devices" / "Configure Mail Client" — it shows
 * the Outgoing (SMTP) server and port, and confirms the username is the
 * full email address.
 */

declare(strict_types=1);

const SMTP_HOST     = 'mail.yourdomain.com';
const SMTP_PORT     = 465; // 465 = implicit SSL, 587 = STARTTLS
const SMTP_USERNAME = 'info@yourdomain.com';
const SMTP_PASSWORD = 'your-mailbox-password';
