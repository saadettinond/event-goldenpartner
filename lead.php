<?php
/*
 * Randevu formu alıcısı.
 * 1) talepler/talepler.csv dosyasına yazar (yedek liste, Excel ile açılır)
 * 2) ayarlar.php'deki adrese e-posta atar
 * 3) Token girildiyse CRM webhook'una (goldenpartner.telos.com.tr) lead olarak gönderir
 */
declare(strict_types=1);
date_default_timezone_set('Europe/Istanbul');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out(int $code, array $body): void { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['ok' => false, 'error' => 'Geçersiz istek.']);

// Gizli ayarlar sunucudaki ayarlar.php'de durur (repoya girmez); yoksa örnek dosya kullanılır
$cfg  = require (is_file(__DIR__ . '/ayarlar.php') ? __DIR__ . '/ayarlar.php' : __DIR__ . '/ayarlar.ornek.php');
$dir  = __DIR__ . '/talepler';
if (!is_dir($dir)) @mkdir($dir, 0750, true);
$logf = $dir . '/hata.log';
function logx(string $f, string $m): void { @file_put_contents($f, '[' . date('c') . "] $m\n", FILE_APPEND | LOCK_EX); }

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 20000) out(413, ['ok' => false, 'error' => 'İstek çok büyük.']);
$in = json_decode($raw, true);
if (!is_array($in)) out(400, ['ok' => false, 'error' => 'Geçersiz veri.']);

$s = static function ($v, int $max = 200): string {
    $v = is_scalar($v) ? (string)$v : '';
    $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '');
    return mb_substr($v, 0, $max);
};

// Spam: gizli alan dolu ya da form 2 saniyeden kısa sürede gönderildiyse sessizce "tamam" de
if ($s($in['website'] ?? '') !== '' || (int)($in['t'] ?? 0) < 2) out(200, ['ok' => true]);

// Basit hız sınırı: IP başına 10 dakikada 5 talep
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rlf = $dir . '/.rl_' . substr(hash('sha256', $ip), 0, 16);
$hits = array_filter(array_map('intval', @file($rlf, FILE_IGNORE_NEW_LINES) ?: []), fn($t) => $t > time() - 600);
if (count($hits) >= 5) out(429, ['ok' => false, 'error' => 'Çok fazla deneme yapıldı, birkaç dakika sonra tekrar deneyin.']);
$hits[] = time(); @file_put_contents($rlf, implode("\n", $hits), LOCK_EX);

$name    = $s($in['name'] ?? '', 120);
$cc      = preg_replace('/[^0-9+]/', '', $s($in['cc'] ?? '+90', 6)) ?: '+90';
$digits  = preg_replace('/\D/', '', $s($in['phone'] ?? '', 40));
$digits  = ltrim($digits, '0');
$phone   = $cc . $digits;
// Liste/e-posta için okunur biçim: +90 → 0532 123 45 67, diğerleri → (+44) 7911123456
$phoneTxt = ($cc === '+90' && strlen($digits) === 10)
    ? '0' . substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 2) . ' ' . substr($digits, 8, 2)
    : "($cc) $digits";
$email   = $s($in['email'] ?? '', 160);
$program = $s($in['program'] ?? '', 80);
$day     = $s($in['day'] ?? '', 40) ?: 'Belirtilmedi';
$kvkk    = !empty($in['kvkk']);
$ticari  = !empty($in['ticari']);
$page    = $s($in['page'] ?? '', 400);
$ref     = $s($in['ref'] ?? '', 400);
$utm = [];
foreach (['utm_source','utm_medium','utm_campaign','utm_content','utm_term','fbclid','gclid'] as $k) {
    if (!empty($in['utm'][$k])) $utm[$k] = $s($in['utm'][$k], 300);
}

if (mb_strlen($name) < 3)   out(422, ['ok' => false, 'error' => 'Ad soyad eksik.']);
if (strlen($digits) < 7)    out(422, ['ok' => false, 'error' => 'Telefon numarası geçersiz.']);
if (!$kvkk)                 out(422, ['ok' => false, 'error' => 'KVKK onayı gerekli.']);
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

$now = date('Y-m-d H:i:s');
$saved = false; $mailed = false; $crm = false;

// 1) CSV
$csv = $dir . '/talepler.csv';
$new = !file_exists($csv);
if ($fh = @fopen($csv, 'ab')) {
    if (flock($fh, LOCK_EX)) {
        if ($new) { fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, ['Tarih','Ad Soyad','Telefon','E-posta','Program','Gün','Ticari ileti onayı','utm_source','utm_medium','utm_campaign','utm_content','Sayfa','Referans','IP'], ';'); }
        $cell = fn($v) => preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; // Excel formül enjeksiyonuna karşı
        $row = array_map($cell, [$now,$name,'',$email,$program,$day,$ticari?'Evet':'Hayır',$utm['utm_source']??'',$utm['utm_medium']??'',$utm['utm_campaign']??'',$utm['utm_content']??'',$page,$ref,$ip]); $row[2] = $phoneTxt;
        fputcsv($fh, $row, ';');
        fflush($fh); flock($fh, LOCK_UN); $saved = true;
    }
    fclose($fh);
}
if (!$saved) logx($logf, 'CSV yazılamadı');

// 2) E-posta
$to = trim((string)($cfg['mail_to'] ?? ''));
if ($to !== '' && strpos($to, 'BURAYA') === false) {
    $subj = '=?UTF-8?B?' . base64_encode('Yeni randevu talebi · ' . $program . ' · ' . $name) . '?=';
    $lines = [
        $cfg['event_name'] ?? 'Etkinlik', str_repeat('-', 40),
        "Ad Soyad : $name", "Telefon  : $phoneTxt", 'E-posta  : ' . ($email ?: '-'),
        "Program  : $program", "Gün      : $day", 'Ticari ileti onayı: ' . ($ticari ? 'Evet' : 'Hayır'),
        "Tarih    : $now", '', 'Sayfa    : ' . $page, 'Referans : ' . ($ref ?: '-'),
    ];
    foreach ($utm as $k => $v) $lines[] = str_pad($k, 9) . ': ' . $v;
    $fromName = '=?UTF-8?B?' . base64_encode((string)($cfg['mail_from_name'] ?? 'Golden Partner')) . '?=';
    $from = (string)($cfg['mail_from'] ?? 'noreply@goldenpartner.com.tr');
    $headers = "From: $fromName <$from>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    if ($email !== '') $headers .= "Reply-To: $email\r\n";
    $mailed = @mail($to, $subj, implode("\n", $lines), $headers, '-f' . $from);
    if (!$mailed) logx($logf, 'E-posta gönderilemedi');
}

// 3) CRM webhook (Golden Partner CRM — goldenpartner.telos.com.tr, Bearer token)
$key = trim((string)($cfg['crm_api_key'] ?? ''));
if ($key !== '' && !empty($cfg['crm_url']) && function_exists('curl_init')) {
    $event = (string)($cfg['event_name'] ?? 'Etkinlik');
    $note  = "$event — Program: $program, Gün: $day" . ($ticari ? ' · Ticari ileti onayı: Evet' : '');
    $payload = array_merge([
        'name'              => $name,
        'full_name'         => $name,
        'phone'             => $phone,
        'email'             => $email !== '' ? $email : null,
        'source'            => 'event.goldenpartner.com.tr',
        'form_name'         => 'İzmir Etkinliği Randevu Formu',
        'campaign'          => $event,
        'program'           => $program,
        'interest'          => $program,
        'preferred_day'     => $day,
        'note'              => $note,
        'message'           => $note,
        'kvkk_consent'      => true,
        'marketing_consent' => $ticari,
        'page_url'          => $page,
        'referrer'          => $ref,
        'tags'              => array_values(array_filter(array_merge((array)($cfg['crm_tags'] ?? []), [$program]))),
        'submitted_at'      => date('c'),
    ], $utm);
    $ch = curl_init((string)$cfg['crm_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    $crm = $code >= 200 && $code < 300;
    if (!$crm) logx($logf, "CRM hatası ($code) " . ($cerr ?: mb_substr((string)$res, 0, 300)));
}

// 4) OpenAI Ads Conversions API (sunucu tarafı; pikselle aynı event_id → tekilleştirme)
$capiKey = trim((string)($cfg['openai_capi_key'] ?? ''));
$pixelId = trim((string)($cfg['openai_pixel_id'] ?? ''));
if ($capiKey !== '' && $pixelId !== '' && function_exists('curl_init')) {
    $evId = preg_replace('/[^A-Za-z0-9_.:-]/', '', $s($in['event_id'] ?? '', 80)) ?: ('apt-' . bin2hex(random_bytes(8)));
    $user = ['ip_address' => $ip];
    $ua = $s($_SERVER['HTTP_USER_AGENT'] ?? '', 500);
    if ($ua !== '') $user['user_agent'] = $ua;
    $pd = ltrim(preg_replace('/\D/', '', $phone) ?? '', '0');                 // +905321234567 → 905321234567
    if (strlen($pd) >= 8 && strlen($pd) <= 15) $user['phone_numbers_sha256'] = [hash('sha256', $pd)];
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $user['emails_sha256'] = [hash('sha256', mb_strtolower(trim($email)))];
    $obref = $s($_COOKIE['__obref'] ?? '', 1000);
    if ($obref !== '') $user['obref'] = $obref;
    $oppref = $s($_COOKIE['__oppref'] ?? '', 1000);
    if ($oppref === '') { parse_str((string)parse_url($page, PHP_URL_QUERY), $qs); $oppref = $s($qs['oppref'] ?? '', 1000); }
    $ev = [
        'id'            => $evId,
        'type'          => 'appointment_scheduled',
        'timestamp_ms'  => (int)round(microtime(true) * 1000),
        'source_url'    => $page !== '' ? $page : 'https://event.goldenpartner.com.tr/',
        'action_source' => 'web',
        'data'          => ['type' => 'customer_action'],
        'user'          => $user,
    ];
    if ($oppref !== '') $ev['oppref'] = $oppref;
    $url = (string)($cfg['openai_capi_url'] ?? 'https://bzr.openai.com/v1/events') . '?pid=' . rawurlencode($pixelId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $capiKey],
        CURLOPT_POSTFIELDS => json_encode(['validate_only' => false, 'events' => [$ev]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6,
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($code < 200 || $code >= 300) logx($logf, "OpenAI CAPI hatası ($code) " . ($cerr ?: mb_substr((string)$res, 0, 300)));
}

if (!$saved && !$mailed && !$crm) out(500, ['ok' => false, 'error' => 'Talebiniz kaydedilemedi.']);
out(200, ['ok' => true]);
