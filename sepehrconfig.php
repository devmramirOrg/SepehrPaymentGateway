<?php

declare(strict_types=1);

/**
 * تنظیمات و توابع مشترک sepehrpay.php و sepehrback.php
 * این سه فایل + Sepehr.php را کنار هم در یک پوشه قرار دهید.
 */

require_once __DIR__ . '/Sepehr.php';

/** شماره ترمینال ۸ رقمی (98000000 فقط نمونه مستند است؛ ترمینال واقعی خود را بگذارید) */
const SEPEHR_TERMINAL_ID = '98000000';

/**
 * آدرس کامل sepehrback.php روی دامنه شما (باید با دامنه سایت یکی باشد).
 * اگر null بماند از روی آدرس فعلی ساخته می‌شود؛ در پروداکشن حتما مقدار ثابت بدهید.
 * مثال: 'https://yourdomain.ir/pay/sepehrback.php'
 */
const SEPEHR_CALLBACK_URL = null;

/** پوشه ذخیره فاکتورها (ترجیحا بیرون از public_html) */
const SEPEHR_DATA_DIR = __DIR__ . '/sepehr_data';

/** حداقل مبلغ مجاز به ریال */
const SEPEHR_MIN_AMOUNT = 1000;

/**
 * فقط اگر سرور چند IP دارد: IP ثبت‌شده در سپهر را اینجا بدهید تا درخواست‌ها از همان IP خارج شوند.
 * مثال: '185.10.20.30'
 */
const SEPEHR_BIND_IP = null;

/**
 * فقط اگر IP ثبت‌شده در سپهر مال یک سرور واسط/پراکسی است.
 * مثال: 'http://185.10.20.30:3128' یا 'socks5://185.10.20.30:1080'
 */
const SEPEHR_PROXY = null;

function sepehr(): Sepehr
{
    return new Sepehr(
        terminalId: SEPEHR_TERMINAL_ID,
        defaultCallback: sepehr_callback_url(),
        bindIp: SEPEHR_BIND_IP,
        proxy: SEPEHR_PROXY,
    );
}

function sepehr_callback_url(): string
{
    if (SEPEHR_CALLBACK_URL !== null) {
        return SEPEHR_CALLBACK_URL;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $host  = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
        throw new SepehrException('Host نامعتبر است؛ SEPEHR_CALLBACK_URL را در تنظیمات مقداردهی کنید.');
    }
    $dir = rtrim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])), '/');
    return ($https ? 'https://' : 'http://') . $host . $dir . '/sepehrback.php';
}

/* ---------------------------- ذخیره‌سازی ساده فایلی ---------------------------- */

function sepehr_data_dir(): string
{
    if (!is_dir(SEPEHR_DATA_DIR)) {
        if (!mkdir(SEPEHR_DATA_DIR, 0700, true) && !is_dir(SEPEHR_DATA_DIR)) {
            throw new SepehrException('ساخت پوشه ذخیره‌سازی ممکن نیست.');
        }
        // محافظت در صورت قرار گرفتن داخل وب‌روت
        file_put_contents(SEPEHR_DATA_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
        file_put_contents(SEPEHR_DATA_DIR . '/index.html', '');
    }
    return SEPEHR_DATA_DIR;
}

function sepehr_invoice_file(string $invoiceId): string
{
    return sepehr_data_dir() . '/inv_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $invoiceId) . '.json';
}

function sepehr_save(string $invoiceId, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents(sepehr_invoice_file($invoiceId), $json, LOCK_EX) === false) {
        throw new SepehrException('ذخیره فاکتور ممکن نیست؛ دسترسی نوشتن پوشه را بررسی کنید.');
    }
}

function sepehr_load(string $invoiceId): ?array
{
    $file = sepehr_invoice_file($invoiceId);
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function sepehr_receipt_file(string $receipt): string
{
    return sepehr_data_dir() . '/rcpt_' . hash('sha256', $receipt) . '.txt';
}

function sepehr_receipt_used(string $receipt): bool
{
    return is_file(sepehr_receipt_file($receipt));
}

/** ثبت اتمیک رسید دیجیتال؛ false یعنی قبلا ثبت شده */
function sepehr_receipt_claim(string $receipt, string $invoiceId): bool
{
    $fh = @fopen(sepehr_receipt_file($receipt), 'x');
    if ($fh === false) {
        return false;
    }
    fwrite($fh, $invoiceId);
    fclose($fh);
    return true;
}

/* ---------------------------------- خروجی HTML ---------------------------------- */

function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @param 'ok'|'err'|'warn' $kind */
function sepehr_page(string $title, string $kind, string $bodyHtml): never
{
    $color = ['ok' => '#16a34a', 'err' => '#dc2626', 'warn' => '#d97706'][$kind] ?? '#334155';
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . '</title><style>'
        . 'body{font-family:Tahoma,Vazirmatn,sans-serif;background:#f1f5f9;margin:0;padding:24px;color:#0f172a}'
        . '.card{max-width:520px;margin:40px auto;background:#fff;border-radius:14px;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,.08)}'
        . 'h1{font-size:20px;margin:0 0 14px;color:' . $color . '}'
        . 'table{width:100%;border-collapse:collapse;margin-top:14px}'
        . 'td{padding:9px 6px;border-bottom:1px solid #e2e8f0;font-size:14px}td:first-child{color:#64748b;width:42%}'
        . 'a.btn,button{display:inline-block;margin-top:18px;padding:10px 18px;border-radius:8px;background:#0f172a;color:#fff;text-decoration:none;border:0;font:inherit;cursor:pointer}'
        . 'input{padding:9px;border:1px solid #cbd5e1;border-radius:8px;font:inherit;width:100%;box-sizing:border-box}'
        . '</style></head><body><div class="card"><h1>' . h($title) . '</h1>' . $bodyHtml . '</div></body></html>';
    exit;
}