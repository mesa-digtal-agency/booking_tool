<?php
/**
 * Minimal mailer. Supports PHP mail() or a small built-in SMTP client.
 * No Composer / PHPMailer required.
 *
 * send_mail($to, $subject, $html_body): bool
 */

function send_mail(string $to, string $subject, string $html_body): bool {
    $cfg = $GLOBALS['CONFIG'];
    $from_email = mail_sanitize_email((string)($cfg['from_email'] ?: 'no-reply@localhost'));
    $from_name  = mail_sanitize_header((string)($cfg['from_name'] ?: business_name()), 120);
    $subject = mail_sanitize_header($subject, 180);

    if (!is_valid_email($to) || !$from_email || $subject === '') return false;

    if (strtolower($cfg['mail_driver']) === 'smtp') {
        return smtp_send($to, $subject, $html_body, $from_email, $from_name);
    }
    return php_mail_send($to, $subject, $html_body, $from_email, $from_name);
}

function php_mail_send(string $to, string $subject, string $html, string $from_email, string $from_name): bool {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= 'From: ' . mail_encode_name($from_name) . " <{$from_email}>\r\n";
    $headers .= "Reply-To: <{$from_email}>\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $subject_enc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    // @ because shared hosts often log warnings we don't care about here.
    return @mail($to, $subject_enc, $html, $headers, '-f' . escapeshellarg($from_email));
}

function mail_encode_name(string $name): string {
    $name = mail_sanitize_header($name, 120);
    if (preg_match('/[^\x20-\x7e]/', $name)) {
        return '=?UTF-8?B?' . base64_encode($name) . '?=';
    }
    return '"' . str_replace('"', '\\"', $name) . '"';
}

function mail_sanitize_header(string $value, int $max_len = 180): string {
    $value = trim(preg_replace('/[\r\n]+/', ' ', $value));
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    if ($max_len > 0 && function_exists('mb_substr')) {
        $value = mb_substr($value, 0, $max_len);
    } elseif ($max_len > 0) {
        $value = substr($value, 0, $max_len);
    }
    return trim($value);
}

function mail_sanitize_email(string $email): string {
    $email = mail_sanitize_header($email, 190);
    return is_valid_email($email) ? strtolower($email) : '';
}

function mail_header_domain(): string {
    $host = (string)parse_url((string)($GLOBALS['CONFIG']['app_url'] ?? ''), PHP_URL_HOST);
    if ($host === '') $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/:\d+$/', '', strtolower(trim($host, " \t\n\r\0\x0B[]")));
    return preg_match('/^[a-z0-9.-]+$/', $host) ? $host : 'localhost';
}

/**
 * Single-file SMTP client. Supports plain, STARTTLS (encryption=tls), implicit TLS (encryption=ssl).
 * AUTH LOGIN only (works with the overwhelming majority of SMTP providers).
 */
function smtp_send(string $to, string $subject, string $html, string $from_email, string $from_name): bool {
    $cfg = $GLOBALS['CONFIG'];
    $host = $cfg['smtp_host'];
    $port = (int)$cfg['smtp_port'] ?: 587;
    $user = $cfg['smtp_username'];
    $pass = $cfg['smtp_password'];
    $enc  = strtolower((string)$cfg['smtp_encryption']);

    if ($host === '' || $port < 1 || $port > 65535) return false;
    if (strpos($host, '://') !== false || strpos($host, '/') !== false || !preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
        error_log('SMTP host is invalid.');
        return false;
    }

    $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0; $errstr = '';
    $ctx = stream_context_create([
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
    ]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log("SMTP connect failed: {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 4096);
            if ($line === false) break;
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $send = function (string $cmd) use ($fp, $read) {
        fwrite($fp, $cmd . "\r\n");
        return $read();
    };
    $expect = function (string $resp, int $code) use ($fp) {
        if ((int)substr($resp, 0, 3) !== $code) {
            error_log("SMTP unexpected: " . trim($resp));
            fclose($fp);
            return false;
        }
        return true;
    };

    $greet = $read();
    if (!$expect($greet, 220)) return false;

    $ehlo_host = mail_header_domain();
    $r = $send('EHLO ' . $ehlo_host);
    if (!$expect($r, 250)) return false;

    if ($enc === 'tls') {
        $r = $send('STARTTLS');
        if (!$expect($r, 220)) return false;
        $crypto = defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')
            ? (STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)
            : STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (!stream_socket_enable_crypto($fp, true, $crypto)) {
            error_log('SMTP STARTTLS handshake failed');
            fclose($fp);
            return false;
        }
        $r = $send('EHLO ' . $ehlo_host);
        if (!$expect($r, 250)) return false;
    }

    if ($user !== '' && $pass !== '') {
        $r = $send('AUTH LOGIN');
        if (!$expect($r, 334)) return false;
        $r = $send(base64_encode($user));
        if (!$expect($r, 334)) return false;
        $r = $send(base64_encode($pass));
        if (!$expect($r, 235)) return false;
    }

    $r = $send('MAIL FROM:<' . $from_email . '>');
    if (!$expect($r, 250)) return false;
    $r = $send('RCPT TO:<' . $to . '>');
    if ((int)substr($r, 0, 3) !== 250 && (int)substr($r, 0, 3) !== 251) {
        error_log('SMTP RCPT rejected: ' . trim($r));
        fclose($fp); return false;
    }
    $r = $send('DATA');
    if (!$expect($r, 354)) return false;

    $headers  = 'From: ' . mail_encode_name($from_name) . " <{$from_email}>\r\n";
    $headers .= 'To: <' . $to . ">\r\n";
    $headers .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= 'Date: ' . date('r') . "\r\n";
    $headers .= 'Message-ID: <' . uuid_v4() . '@' . $ehlo_host . ">\r\n";

    // Dot-stuff any line starting with '.'
    $body = preg_replace('/^\./m', '..', $html);

    fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
    $r = $read();
    if (!$expect($r, 250)) return false;

    $send('QUIT');
    fclose($fp);
    return true;
}
