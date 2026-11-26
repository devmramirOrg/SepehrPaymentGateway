<?php

declare(strict_types=1);

/**
 * صفحه بازگشت از درگاه سپهر: اطلاعات را می‌گیرد، تایید (Advice) می‌کند و نتیجه را نشان می‌دهد.
 */

require __DIR__ . '/sepehrconfig.php';

/** جدول جزئیات تراکنش */
function details_table(array $rows): string
{
    $html = '<table>';
    foreach ($rows as $label => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $html .= '<tr><td>' . h((string) $label) . '</td><td dir="ltr" style="text-align:right">' . h((string) $value) . '</td></tr>';
    }
    return $html . '</table>';
}

function success_page(array $rec): never
{
    sepehr_page('پرداخت با موفقیت انجام شد', 'ok',
        '<p>پرداخت شما تایید شد. لطفا شماره پیگیری را نگه دارید.</p>'
        . details_table([
            'مبلغ (ریال)'      => number_format((int) $rec['amount']),
            'شماره فاکتور'     => $rec['invoice_id'] ?? null,
            'شماره پیگیری'     => $rec['trace_number'] ?? null,
            'شماره مرجع (RRN)' => $rec['rrn'] ?? null,
            'شماره کارت'       => $rec['card_number'] ?? null,
            'بانک صادرکننده'   => $rec['issuer_bank'] ?? null,
            'زمان پرداخت'      => $rec['date_paid'] ?? null,
        ])
        . '<a class="btn" href="sepehrpay.php">پرداخت جدید</a>');
}

try {
    $sepehr = sepehr();
    $cb     = $sepehr->parseCallback();

    if ($cb->respCode === null || $cb->invoiceId === null || $cb->invoiceId === '') {
        sepehr_page('درخواست نامعتبر', 'err', '<p>اطلاعاتی از درگاه دریافت نشد.</p><a class="btn" href="sepehrpay.php">بازگشت</a>');
    }

    $record = sepehr_load($cb->invoiceId);
    if ($record === null) {
        sepehr_page('فاکتور یافت نشد', 'err', '<p>فاکتور مربوط به این پرداخت پیدا نشد.</p>'
            . details_table(['شماره فاکتور' => $cb->invoiceId, 'شماره پیگیری' => $cb->traceNumber]));
    }

    // رفرش صفحه بعد از پرداخت موفق: دوباره Advice نمی‌زنیم
    if (($record['status'] ?? '') === 'paid') {
        success_page($record);
    }

    $result = $sepehr->verifyPurchase(
        $cb,
        (int) $record['amount'],
        static fn (string $receipt): bool => sepehr_receipt_used($receipt),
    );

    if ($result->success()) {
        $receipt = (string) $cb->digitalReceipt;

        if (!sepehr_receipt_claim($receipt, $cb->invoiceId)) {
            // درخواست هم‌زمان دیگری همین تراکنش را ثبت کرده است
            $record = sepehr_load($cb->invoiceId) ?? $record;
            if (($record['status'] ?? '') === 'paid') {
                success_page($record);
            }
            sepehr_page('تراکنش تکراری', 'warn', '<p>این تراکنش قبلا پردازش شده است.</p>');
        }

        $record = [
            ...$record,
            'status'         => 'paid',
            'trace_number'   => $cb->traceNumber,
            'rrn'            => $cb->rrn,
            'card_number'    => $cb->cardNumber,
            'issuer_bank'    => $cb->issuerBank,
            'date_paid'      => $cb->datePaid,
            'digital_receipt' => $receipt,
            'paid_at'        => date('Y-m-d H:i:s'),
        ];
        sepehr_save($cb->invoiceId, $record);

        // اینجا سفارش را در سیستم خود نهایی کنید (فعال‌سازی سرویس، ارسال کالا و ...)

        success_page($record);
    }

    // ----- حالت‌های ناموفق -----
    $record['status']      = $result->status->value;
    $record['fail_reason'] = $result->message;
    sepehr_save($cb->invoiceId, $record);

    $title = match ($result->status) {
        VerifyStatus::Canceled => 'پرداخت لغو شد',
        VerifyStatus::Timeout  => 'زمان پرداخت به پایان رسید',
        default                => 'پرداخت ناموفق بود',
    };

    $note = match ($result->status) {
        VerifyStatus::Canceled, VerifyStatus::Timeout => '',
        default => '<p style="color:#64748b;font-size:13px">در صورت کسر وجه از حساب شما، مبلغ طی حداکثر ۷۲ ساعت به حساب‌تان بازگردانده می‌شود.</p>',
    };

    sepehr_page(
        $title,
        $result->status === VerifyStatus::Canceled ? 'warn' : 'err',
        '<p>' . h($result->message) . '</p>'
        . details_table([
            'شماره فاکتور' => $cb->invoiceId,
            'کد پاسخ'      => $cb->respCode,
            'شماره پیگیری' => $cb->traceNumber,
        ])
        . $note
        . '<a class="btn" href="sepehrpay.php">تلاش مجدد</a>',
    );
} catch (SepehrException $e) {
    sepehr_page('خطا در تایید پرداخت', 'err',
        '<p>ارتباط با درگاه برای تایید پرداخت برقرار نشد. اگر وجه از حساب شما کسر شده، تراکنش به‌صورت خودکار برگشت می‌خورد.</p>'
        . '<p style="color:#64748b;font-size:13px">' . h($e->getMessage()) . '</p>');
}