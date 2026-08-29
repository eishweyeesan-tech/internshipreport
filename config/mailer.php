<?php
/**
 * ============================================================================
 * InternReport Management System — Direct Email Dispatch Engine
 * File: config/mailer.php
 * ============================================================================
 * Supports both Live SMTP (e.g. Gmail SMTP, SendGrid, Mailgun) and
 * Localhost / Offline Debug Logging with HTML Previews.
 */

if (!defined('MAILER_CONFIG_LOADED')) {
    define('MAILER_CONFIG_LOADED', true);

    // ── DEFAULT SMTP SETTINGS ───────────────────────────────────────────────
    // Set 'MAIL_SMTP_ENABLED' to true and fill credentials for real email delivery to Instructor.
    // When false, emails are safely logged to uploads/mail_previews/
    define('MAIL_SMTP_ENABLED', true);
    define('MAIL_SMTP_HOST',    'smtp.gmail.com');
    define('MAIL_SMTP_PORT',    587); // 587 (TLS) or 465 (SSL)
    define('MAIL_SMTP_SECURE',  'tls'); // 'tls', 'ssl', or 'none'
    define('MAIL_SMTP_USER',    'eishweyeesan@gmail.com');
    define('MAIL_SMTP_PASS',    'akkqtwkwkbjwncvf'); // Gmail App Password
    define('MAIL_FROM_EMAIL',   'eishweyeesan@gmail.com');
    define('MAIL_FROM_NAME',    'InternReport System');

    // ── PUBLIC BASE URL FOR MOBILE / LAN / LIVE HOSTING ──────────────────────
    // ဖုန်းမှ တိုက်ရိုက် ဖွင့်နိုင်ရန် သင်၏ PC Wi-Fi IP သို့မဟုတ် Domain / Public Tunnel URL
    define('APP_URL', 'http://192.168.100.78/internreportsystem');
}

/**
 * Send an email using Pure PHP Socket SMTP or PHP mail() or Localhost Logger
 *
 * @param string $to Recipient email
 * @param string $to_name Recipient display name
 * @param string $subject Email subject
 * @param string $html_body HTML formatted body
 * @param string $alt_body Plain-text fallback body
 * @return array ['success' => bool, 'mode' => 'smtp'|'mail'|'debug_log', 'message' => string, 'preview_file' => ?string]
 */
function send_system_email(string $to, string $to_name, string $subject, string $html_body, string $alt_body = ''): array
{
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'mode'    => 'error',
            'message' => "Invalid recipient email address: '{$to}'",
            'preview_file' => null
        ];
    }

    if (empty($alt_body)) {
        $alt_body = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $html_body));
    }

    $preview_file = null;
    $log_dir = dirname(__DIR__) . '/uploads/mail_previews';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0777, true);
    }
    
    // Save preview HTML file for inspection and local testing
    $safe_name = 'mail_preview_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $to) . '_' . date('Ymd_His') . '.html';
    $preview_path = $log_dir . '/' . $safe_name;
    @file_put_contents($preview_path, $html_body);
    $preview_file = 'uploads/mail_previews/' . $safe_name;

    // Log to text log file
    $log_file = dirname(__DIR__) . '/logs/mail.log';
    $log_folder = dirname($log_file);
    if (!is_dir($log_folder)) {
        @mkdir($log_folder, 0777, true);
    }
    $log_entry = sprintf(
        "[%s] TO: %s <%s> | SUBJECT: %s | PREVIEW: %s\n",
        date('Y-m-d H:i:s'),
        $to_name,
        $to,
        $subject,
        $preview_file
    );
    @file_put_contents($log_file, $log_entry, FILE_APPEND);

    // If SMTP is enabled and configured, attempt socket delivery
    if (MAIL_SMTP_ENABLED && !empty(MAIL_SMTP_HOST) && !empty(MAIL_SMTP_USER)) {
        $smtp_result = send_smtp_socket(
            MAIL_SMTP_HOST,
            MAIL_SMTP_PORT,
            MAIL_SMTP_SECURE,
            MAIL_SMTP_USER,
            MAIL_SMTP_PASS,
            MAIL_FROM_EMAIL,
            MAIL_FROM_NAME,
            $to,
            $to_name,
            $subject,
            $html_body,
            $alt_body
        );

        if ($smtp_result['success']) {
            return [
                'success'      => true,
                'mode'         => 'smtp',
                'message'      => 'Email successfully dispatched via SMTP.',
                'preview_file' => $preview_file
            ];
        } else {
            // SMTP failed; log error and report
            $error_entry = sprintf("[%s] SMTP ERROR for %s: %s\n", date('Y-m-d H:i:s'), $to, $smtp_result['error']);
            @file_put_contents($log_file, $error_entry, FILE_APPEND);
            
            return [
                'success'      => false,
                'mode'         => 'smtp_failed',
                'message'      => 'SMTP Delivery Error: ' . $smtp_result['error'],
                'preview_file' => $preview_file
            ];
        }
    }

    // Default Localhost / Debug Mode
    return [
        'success'      => true,
        'mode'         => 'debug_log',
        'message'      => 'Email dispatched to log (Localhost Debug Mode).',
        'preview_file' => $preview_file
    ];
}

/**
 * Pure PHP Socket SMTP Client
 */
function send_smtp_socket(
    string $host,
    int $port,
    string $secure,
    string $username,
    string $password,
    string $from_email,
    string $from_name,
    string $to_email,
    string $to_name,
    string $subject,
    string $html_body,
    string $alt_body
): array {
    $timeout = 15;
    $errno   = 0;
    $errstr  = '';

    $target_host = ($secure === 'ssl') ? 'ssl://' . $host : $host;
    $socket = @fsockopen($target_host, $port, $errno, $errstr, $timeout);

    if (!$socket) {
        return ['success' => false, 'error' => "Cannot connect to SMTP server {$host}:{$port} ({$errstr})"];
    }

    $read = function () use ($socket) {
        $response = '';
        while ($line = @fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $response;
    };

    $write = function ($cmd) use ($socket) {
        @fputs($socket, $cmd . "\r\n");
    };

    $init_resp = $read();
    if (substr($init_resp, 0, 3) !== '220') {
        @fclose($socket);
        return ['success' => false, 'error' => "Server did not say 220: {$init_resp}"];
    }

    $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $write("EHLO " . $hostname);
    $ehlo_resp = $read();

    if ($secure === 'tls' && strpos($ehlo_resp, 'STARTTLS') !== false) {
        $write("STARTTLS");
        $tls_resp = $read();
        if (substr($tls_resp, 0, 3) === '220') {
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                @fclose($socket);
                return ['success' => false, 'error' => 'Failed to negotiate TLS encryption'];
            }
            $write("EHLO " . $hostname);
            $read();
        }
    }

    if (!empty($username) && !empty($password)) {
        $write("AUTH LOGIN");
        $auth_resp = $read();
        if (substr($auth_resp, 0, 3) !== '334') {
            @fclose($socket);
            return ['success' => false, 'error' => "AUTH LOGIN rejected: {$auth_resp}"];
        }

        $write(base64_encode($username));
        $u_resp = $read();
        if (substr($u_resp, 0, 3) !== '334') {
            @fclose($socket);
            return ['success' => false, 'error' => "Username rejected: {$u_resp}"];
        }

        $write(base64_encode($password));
        $p_resp = $read();
        if (substr($p_resp, 0, 3) !== '235') {
            @fclose($socket);
            return ['success' => false, 'error' => "Authentication failed: {$p_resp}"];
        }
    }

    $write("MAIL FROM: <{$from_email}>");
    $mail_resp = $read();
    if (substr($mail_resp, 0, 3) !== '250') {
        @fclose($socket);
        return ['success' => false, 'error' => "MAIL FROM error: {$mail_resp}"];
    }

    $write("RCPT TO: <{$to_email}>");
    $rcpt_resp = $read();
    if (substr($rcpt_resp, 0, 3) !== '250' && substr($rcpt_resp, 0, 3) !== '251') {
        @fclose($socket);
        return ['success' => false, 'error' => "RCPT TO error: {$rcpt_resp}"];
    }

    $write("DATA");
    $data_resp = $read();
    if (substr($data_resp, 0, 3) !== '354') {
        @fclose($socket);
        return ['success' => false, 'error' => "DATA rejected: {$data_resp}"];
    }

    $boundary = "----=_NextPart_" . md5(uniqid(microtime(true), true));
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encoded_from_name = '=?UTF-8?B?' . base64_encode($from_name) . '?=';
    $encoded_to_name = '=?UTF-8?B?' . base64_encode($to_name) . '?=';

    $headers = [
        "From: {$encoded_from_name} <{$from_email}>",
        "To: {$encoded_to_name} <{$to_email}>",
        "Subject: {$encoded_subject}",
        "Date: " . date('r'),
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        "X-Mailer: InternReport-Mailer/1.0"
    ];

    $email_content  = implode("\r\n", $headers) . "\r\n\r\n";
    $email_content .= "--{$boundary}\r\n";
    $email_content .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $email_content .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $email_content .= chunk_split(base64_encode($alt_body)) . "\r\n";

    $email_content .= "--{$boundary}\r\n";
    $email_content .= "Content-Type: text/html; charset=UTF-8\r\n";
    $email_content .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $email_content .= chunk_split(base64_encode($html_body)) . "\r\n";
    $email_content .= "--{$boundary}--\r\n";
    $email_content .= "\r\n.";

    $write($email_content);
    $send_resp = $read();

    $write("QUIT");
    $read();
    @fclose($socket);

    if (substr($send_resp, 0, 3) === '250') {
        return ['success' => true];
    }

    return ['success' => false, 'error' => "Message rejected: {$send_resp}"];
}

/**
 * Generate a responsive HTML Email Template for Instructor Review
 */
function build_instructor_email_html(
    string $instructor_name,
    string $student_name,
    string $student_roll,
    string $company_name,
    int $week_number,
    string $week_range,
    string $review_link,
    string $expires_at
): string {
    $inst_display = $instructor_name ?: 'Company Instructor';
    $comp_display = $company_name ?: 'Host Company';
    $roll_display = $student_roll ?: '—';
    $exp_display  = date('M d, Y - h:i A', strtotime($expires_at));

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internship Report Review Request — Week {$week_number}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #f1f5f9; padding: 40px 0; }
        .main-card { max-width: 580px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08); border: 1px solid #e2e8f0; }
        .header { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); padding: 32px 36px; text-align: center; color: #ffffff; }
        .header h1 { margin: 0 0 8px 0; font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
        .header p { margin: 0; font-size: 13px; opacity: 0.9; }
        .content { padding: 36px; color: #334155; }
        .greeting { font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
        .intro-text { font-size: 14px; line-height: 1.6; color: #475569; margin-bottom: 24px; }
        .info-card { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 28px; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-weight: 600; color: #64748b; }
        .info-value { font-weight: 700; color: #0f172a; text-align: right; }
        .badge { display: inline-block; padding: 4px 10px; background-color: #ede9fe; color: #6d28d9; border-radius: 6px; font-size: 12px; font-weight: 700; }
        .btn-container { text-align: center; margin: 32px 0 24px 0; }
        .cta-btn { display: inline-block; padding: 15px 36px; background-color: #6366f1; color: #ffffff !important; text-decoration: none; font-size: 15px; font-weight: 700; border-radius: 10px; box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4); }
        .cta-btn:hover { background-color: #4f46e5; }
        .security-note { font-size: 12px; line-height: 1.5; color: #64748b; background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 6px; margin-bottom: 24px; }
        .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 24px; text-align: center; font-size: 11px; color: #94a3b8; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="main-card">
            <div class="header">
                <h1>InternReport Management System</h1>
                <p>Company Instructor Report Evaluation Request</p>
            </div>
            <div class="content">
                <div class="greeting">Dear {$inst_display},</div>
                <p class="intro-text">
                    Your intern, <strong>{$student_name}</strong>, has completed daily work logs and weekly reflections for <strong>Week {$week_number}</strong> and submitted the report for your review and digital signature.
                </p>

                <div class="info-card">
                    <div class="info-row">
                        <span class="info-label">Student Name</span>
                        <span class="info-value">{$student_name}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Roll Number</span>
                        <span class="info-value">{$roll_display}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Host Company</span>
                        <span class="info-value">{$comp_display}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Report Period</span>
                        <span class="info-value">Week {$week_number} ({$week_range})</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Evaluation Status</span>
                        <span class="info-value"><span class="badge">Pending Instructor Review</span></span>
                    </div>
                </div>

                <div class="btn-container">
                    <a href="{$review_link}" class="cta-btn" target="_blank">
                        ✍️ Review &amp; Sign Report (Week {$week_number})
                    </a>
                </div>

                <div class="security-note">
                    <strong>🔒 Secure Direct Access:</strong> No account login or password is required. You can review all daily logs, provide performance feedback/grade, and add your signature with one click.
                    <br><br>
                    <strong>⏰ Expiry:</strong> This single-use link expires on <strong>{$exp_display}</strong>.
                </div>

                <p style="font-size: 12px; color: #64748b; margin-bottom: 0;">
                    If the button above does not open, copy and paste this link into your browser:<br>
                    <a href="{$review_link}" style="color: #6366f1; word-break: break-all; font-size: 11px;">{$review_link}</a>
                </p>
            </div>
            <div class="footer">
                This is an automated notification sent by InternReport System on behalf of {$student_name}.<br>
                For questions regarding the internship, please reply directly or contact the University Department.
            </div>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Dispatch Instructor Magic Link Email for a student and week
 *
 * @param mysqli $db Database connection
 * @param int $student_id Student user ID
 * @param int $week_number Week number
 * @param string $token 32-64 char magic link token
 * @param string|null $override_email Optional override email
 * @return array Result metadata
 */
function send_instructor_magic_link(
    mysqli $db,
    int $student_id,
    int $week_number,
    string $token,
    ?string $override_email = null
): array {
    // 1. Fetch Student & Instructor Profile
    $stmt = $db->prepare("SELECT sp.*, u.username, u.email AS student_account_email FROM student_profiles sp JOIN users u ON u.id = sp.user_id WHERE sp.user_id = ? LIMIT 1");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $profile = $res ? $res->fetch_assoc() : null;

    if (!$profile) {
        return [
            'success' => false,
            'mode'    => 'error',
            'message' => 'Student profile not found.'
        ];
    }

    $instructor_email = trim($override_email ?: ($profile['instructor_email'] ?? ''));
    $instructor_name  = trim($profile['instructor_name'] ?? 'Company Instructor');
    $student_name     = trim(($profile['full_name'] ?? '') ?: ($profile['username'] ?? 'Student'));
    $student_roll     = trim($profile['student_roll'] ?? '');
    $company_name     = trim($profile['company_name'] ?? '');

    if (empty($instructor_email)) {
        return [
            'success' => false,
            'mode'    => 'no_email',
            'message' => 'No instructor email registered for this student. Please update instructor details in your profile.'
        ];
    }

    // 2. Fetch Magic Link Expiry
    $token_stmt = $db->prepare("SELECT expires_at FROM magic_links WHERE token = ? LIMIT 1");
    $token_stmt->bind_param("s", $token);
    $token_stmt->execute();
    $t_res = $token_stmt->get_result();
    $t_row = $t_res ? $t_res->fetch_assoc() : null;
    $expires_at = $t_row['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+7 days'));

    // 3. Compute Week Date Range for email display
    $week_range = "Week {$week_number}";
    $intern_start = $profile['internship_start_date'] ?? null;
    if ($intern_start) {
        $start = new DateTime($intern_start);
        $day_of_week = (int) $start->format('N');
        $days_to_sat = $day_of_week === 6 ? 0 : (6 - $day_of_week + 7) % 7;
        $end_w1 = (clone $start)->modify("+{$days_to_sat} days");

        if ($week_number === 1) {
            $ws = $start->format('d M');
            $we = $end_w1->format('d M Y');
            $week_range = "{$ws} – {$we}";
        } else {
            $w_start = (clone $end_w1)->modify('+1 day');
            if ($week_number > 2) {
                $w_start->modify('+' . (($week_number - 2) * 7) . ' days');
            }
            $w_end = (clone $w_start)->modify('+6 days');
            $week_range = $w_start->format('d M') . ' – ' . $w_end->format('d M Y');
        }
    }

    // 4. Construct Public Magic Link URL
    if (defined('APP_URL') && !empty(APP_URL)) {
        $base_url = rtrim(APP_URL, '/\\');
        $review_link = "{$base_url}/instructor/view-report.php?token={$token}";
    } else {
        $is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $is_https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        $raw_script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $app_dir = preg_replace('#/(student|instructor|supervisor|admin|api|config)/[^/]+$#i', '', $raw_script);
        if ($app_dir === $raw_script) {
            $app_dir = dirname($raw_script);
        }
        $app_dir = rtrim($app_dir, '/\\');
        
        $review_link = "{$scheme}://{$host}{$app_dir}/instructor/view-report.php?token={$token}";
    }

    // 5. Build HTML & Plaintext Body
    $html_body = build_instructor_email_html(
        $instructor_name,
        $student_name,
        $student_roll,
        $company_name,
        $week_number,
        $week_range,
        $review_link,
        $expires_at
    );

    $alt_body = "Dear {$instructor_name},\n\n"
        . "Your intern, {$student_name} ({$student_roll}), has submitted the Week {$week_number} report ({$week_range}) for review and evaluation.\n\n"
        . "Please review and digitally sign the report by opening the following link:\n"
        . "{$review_link}\n\n"
        . "This link expires on {$expires_at}.\n\n"
        . "InternReport Management System";

    $subject = "Internship Report Review — {$student_name} (Week {$week_number})";

    // 6. Send Email
    $send_res = send_system_email($instructor_email, $instructor_name, $subject, $html_body, $alt_body);
    $send_res['recipient_email'] = $instructor_email;
    $send_res['instructor_name']  = $instructor_name;
    $send_res['student_name']     = $student_name;
    $send_res['review_link']      = $review_link;

    return $send_res;
}
