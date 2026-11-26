<?php

declare(strict_types=1);

/**
 * ابزار عیب‌یابی خطای «عدم تطابق IP» (کد 2-)
 *  - IP خروجی واقعی سرور (همانی که سپهر می‌بیند) را نشان می‌دهد
 *  - سلامت سرویس سپهر و گرفتن توکن تستی را امتحان می‌کند
 *
 * ⚠ بعد از استفاده این فایل را از سرور حذف کنید.
 */

require __DIR__ . '/sepehrconfig.php';

/** گرفتن IP خروجی سرور از چند سرویس، با اجبار IPv4 و همان تنظیمات اتصال به سپهر */
function outgoing_ip(string $url): ?string
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ];
    if (SEPEHR_BIND_IP) {
        $opts[CURLOPT_INTERFACE] = SEPEHR_BIND_IP;
    }
    if (SEPEHR_PROXY) {
        $opts[CURLOPT_PROXY] = SEPEHR_PROXY;
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);

    if ($res === false) {
        return null;
    }
    $ip = trim((string) $res);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

$ips = [];
foreach (['https://api.ipify.org', 'https://ifconfig.me/ip', 'https://icanhazip.com', 'https://ipv4.icanhazip.com'] as $svc) {
    $ips[$svc] = outgoing_ip($svc);
}
$found = array_values(array_unique(array_filter($ips)));

// تست سپهر
$health = ['token' => false, 'ui' => false, 'all' => false];
$tokenResult = '';
$tokenOk = false;

try {
    $sepehr = sepehr();
    $health = $sepehr->checkService();

    try {
        $t = $sepehr->purchaseToken(SEPEHR_MIN_AMOUNT, 'TEST-' . bin2hex(random_bytes(4)), sepehr_callback_url());
        $tokenOk = true;
        $tokenResult = 'توکن با موفقیت دریافت شد (' . substr($t, 0, 8) . '...). IP شما در سپهر ثبت است.';
    } catch (SepehrException $e) {
        $tokenResult = $e->getMessage() . ' (کد ' . $e->getCode() . ')';
    }
} catch (Throwable $e) {
    $tokenResult = $e->getMessage();
}

$yes = static fn (bool $b): string => $b ? '✅' : '❌';

$rows  = '<table>';
$rows .= '<tr><td>IP خروجی سرور (دیده‌شده توسط اینترنت)</td><td dir="ltr" style="text-align:right"><b>'
    . ($found ? h(implode(' , ', $found)) : 'نامشخص (دسترسی به سرویس‌های IP مسدود است)') . '</b></td></tr>';
if (count($found) > 1) {
    $rows .= '<tr><td colspan="2" style="color:#d97706">چند IP مختلف دیده شد؛ سرور شما از چند IP خارج می‌شود. IP مورد نظر را در SEPEHR_BIND_IP بگذارید و همان را به سپهر بدهید.</td></tr>';
}
$rows .= '<tr><td>IP داخلی سرور (SERVER_ADDR)</td><td dir="ltr" style="text-align:right">' . h((string) ($_SERVER['SERVER_ADDR'] ?? '-')) . '</td></tr>';
$rows .= '<tr><td>ترمینال</td><td dir="ltr" style="text-align:right">' . h(SEPEHR_TERMINAL_ID) . '</td></tr>';
$rows .= '<tr><td>Callback URL</td><td dir="ltr" style="text-align:right;word-break:break-all">' . h(sepehr_callback_url()) . '</td></tr>';
$rows .= '<tr><td>سرویس توکن سپهر</td><td>' . $yes($health['token']) . '</td></tr>';
$rows .= '<tr><td>صفحه پرداخت سپهر</td><td>' . $yes($health['ui']) . '</td></tr>';
$rows .= '<tr><td>دریافت توکن تستی</td><td>' . $yes($tokenOk) . ' ' . h($tokenResult) . '</td></tr>';
$rows .= '</table>';

$help = $tokenOk ? '' : '
<p style="margin-top:18px"><b>راه‌حل:</b></p>
<ol style="line-height:2;font-size:14px">
  <li>IP بالا را (به‌صورت IPv4) از طریق پرتال پذیرندگان یا واحد پذیرندگان اینترنتی سپهر به‌عنوان IP مجاز ثبت کنید. تا ثبت نشود، با هیچ کدی درست نمی‌شود.</li>
  <li>اگر IP نشان‌داده‌شده با IP هاست شما فرق دارد (هاست اشتراکی/چند IP)، IP ثبت‌شده را در <code>SEPEHR_BIND_IP</code> بگذارید یا از هاستینگ IP اختصاصی بخواهید.</li>
  <li>اگر سرور شما خارج از ایران است یا IP آن در شاپرک پذیرفته نمی‌شود، از یک سرور واسط ایرانی با IP ثابت استفاده کنید و آن را در <code>SEPEHR_PROXY</code> بگذارید.</li>
  <li>کد 4- یعنی دامنهٔ callback با دامنهٔ ثبت‌شده در قرارداد یکی نیست؛ <code>SEPEHR_CALLBACK_URL</code> را ثابت و با دامنهٔ ثبت‌شده یکسان کنید.</li>
  <li>اطمینان دهید ترمینال <code>' . h(SEPEHR_TERMINAL_ID) . '</code> همان ترمینال واقعی شماست (98000000 نمونهٔ مستند است).</li>
</ol>';

sepehr_page('عیب‌یابی اتصال به سپهر', $tokenOk ? 'ok' : 'err', $rows . $help);