<?php

declare(strict_types=1);

/**
 * شروع پرداخت: مبلغ (ریال) را با GET بدهید.
 * مثال: https://yourdomain.ir/sepehrpay.php?amount=50000
 */

require __DIR__ . '/sepehrconfig.php';

$raw = $_GET['amount'] ?? '';

if (!is_string($raw) || $raw === '') {
    sepehr_page('پرداخت آنلاین', 'warn', '
        <p>مبلغ را به ریال وارد کنید:</p>
        <form method="get">
            <input type="text" name="amount" inputmode="numeric" placeholder="مثلا 50000" autofocus>
            <button type="submit">پرداخت</button>
        </form>');
}

if (!ctype_digit($raw) || strlen($raw) > 12) {
    sepehr_page('مبلغ نامعتبر', 'err', '<p>مبلغ باید فقط عدد (ریال) باشد.</p><a class="btn" href="sepehrpay.php">بازگشت</a>');
}

$amount = (int) $raw;
if ($amount < SEPEHR_MIN_AMOUNT) {
    sepehr_page('مبلغ نامعتبر', 'err', '<p>حداقل مبلغ ' . number_format(SEPEHR_MIN_AMOUNT) . ' ریال است.</p><a class="btn" href="sepehrpay.php">بازگشت</a>');
}

try {
    $sepehr    = sepehr();
    $invoiceId = date('YmdHis') . '-' . bin2hex(random_bytes(4)); // یکتا

    $token = $sepehr->purchaseToken($amount, $invoiceId, sepehr_callback_url());

    // مبلغ را سمت سرور نگه می‌داریم تا در بازگشت با مبلغ واقعی مقایسه شود
    sepehr_save($invoiceId, [
        'invoice_id' => $invoiceId,
        'amount'     => $amount,
        'status'     => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $sepehr->redirect($token, SepehrType::Pay);
} catch (SepehrException $e) {
    sepehr_page('خطا در اتصال به درگاه', 'err', '<p>' . h($e->getMessage()) . '</p><a class="btn" href="sepehrpay.php">تلاش مجدد</a>');
}