<?php
/**
 * smtp-mailer.php — minimal dependency-free SMTP client.
 *
 * Used by contact.php and newsletter.php because PHP's built-in mail() is
 * disabled on this host. No Composer, no PHPMailer — just raw sockets
 * speaking SMTP (EHLO/STARTTLS/AUTH LOGIN/MAIL FROM/RCPT TO/DATA).
 *
 * Never throws: every failure path returns ['success' => false, 'error' => ...]
 * so the caller can always respond with valid JSON instead of a blank 500.
 */

declare(strict_types=1);

/**
 * @param array<string,string> $headers Fully-formed header lines (From, To,
 *   Subject, MIME-Version, Content-Type, ...) — written verbatim into the
 *   DATA block above $body.
 * @return array{success: bool, error: ?string}
 */
function smtp_send(
    string $host,
    int $port,
    string $username,
    string $password,
    string $envelopeFrom,
    string $envelopeTo,
    array $headers,
    string $body
): array {
    $timeout = 15;
    $useImplicitTls = $port === 465;
    $target = ($useImplicitTls ? 'ssl://' : '') . $host;

    $socket = @stream_socket_client("$target:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        return ['success' => false, 'error' => "Connection to $host:$port failed: $errstr ($errno)"];
    }
    stream_set_timeout($socket, $timeout);

    $read = function () use ($socket): string {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) {
                break;
            }
            $data .= $line;
            // A multi-line SMTP reply has '-' after the code on all but the last line.
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $write = function (string $cmd) use ($socket): void {
        fwrite($socket, $cmd . "\r\n");
    };

    $expect = function (string $response, string ...$codes): bool {
        foreach ($codes as $code) {
            if (strpos($response, $code) === 0) {
                return true;
            }
        }
        return false;
    };

    $fail = function (string $error) use ($socket): array {
        @fclose($socket);
        return ['success' => false, 'error' => $error];
    };

    $greeting = $read();
    if (!$expect($greeting, '220')) {
        return $fail("Unexpected greeting: $greeting");
    }

    $ehloDomain = strpos($username, '@') !== false ? substr($username, strpos($username, '@') + 1) : 'localhost';

    $write("EHLO $ehloDomain");
    $ehloResp = $read();
    if (!$expect($ehloResp, '250')) {
        return $fail("EHLO failed: $ehloResp");
    }

    if (!$useImplicitTls) {
        $write('STARTTLS');
        $tlsResp = $read();
        if (!$expect($tlsResp, '220')) {
            return $fail("STARTTLS failed: $tlsResp");
        }
        if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $fail('TLS handshake failed.');
        }
        $write("EHLO $ehloDomain");
        $ehloResp = $read();
        if (!$expect($ehloResp, '250')) {
            return $fail("EHLO after STARTTLS failed: $ehloResp");
        }
    }

    $write('AUTH LOGIN');
    $authResp = $read();
    if (!$expect($authResp, '334')) {
        return $fail("AUTH LOGIN not accepted: $authResp");
    }

    $write(base64_encode($username));
    $userResp = $read();
    if (!$expect($userResp, '334')) {
        return $fail("Username rejected: $userResp");
    }

    $write(base64_encode($password));
    $passResp = $read();
    if (!$expect($passResp, '235')) {
        return $fail("Authentication failed: $passResp");
    }

    $write("MAIL FROM:<$envelopeFrom>");
    $mailFromResp = $read();
    if (!$expect($mailFromResp, '250')) {
        return $fail("MAIL FROM rejected: $mailFromResp");
    }

    $write("RCPT TO:<$envelopeTo>");
    $rcptResp = $read();
    if (!$expect($rcptResp, '250', '251')) {
        return $fail("RCPT TO rejected: $rcptResp");
    }

    $write('DATA');
    $dataResp = $read();
    if (!$expect($dataResp, '354')) {
        return $fail("DATA not accepted: $dataResp");
    }

    $headerLines = '';
    foreach ($headers as $name => $value) {
        $headerLines .= "$name: $value\r\n";
    }

    // Dot-stuff any line that starts with a lone '.', per RFC 5321 4.5.2.
    $stuffedBody = preg_replace('/^\./m', '..', $body) ?? $body;

    fwrite($socket, $headerLines . "\r\n" . $stuffedBody . "\r\n.\r\n");
    $sendResp = $read();
    if (!$expect($sendResp, '250')) {
        return $fail("Message rejected: $sendResp");
    }

    $write('QUIT');
    @fclose($socket);

    return ['success' => true, 'error' => null];
}
