<?php

/**
 * 
 * نوشته شده توسط : https://t.me/devmramir
 * کانال تلگرامی ما : https://t.me/alacode
 * وبسایت ما : https://alateam.ir
 */

declare(strict_types=1);

/**
 * کلاس اتصال به درگاه پرداخت الکترونیک سپهر (نسخه توکن - مستند 3.0.9)
 * نوشته شده برای PHP 8.4+ (نیازمند افزونه‌های curl و mbstring)
 *
 * ویژگی‌های PHP مدرن استفاده‌شده:
 *  - Backed Enum برای نوع تراکنش، اپراتور، نوع شارژ و ...
 *  - Readonly class / readonly property برای DTOها
 *  - Property Hooks (PHP 8.4) برای propertyهای محاسباتی
 *  - Typed class constants (PHP 8.3)
 *  - array_find / array_any / array_all (PHP 8.4)
 *  - JSON_THROW_ON_ERROR و never return type
 *
 * نکته: IP سرور شما باید قبلا به سپهر معرفی شده باشد و دامنه callbackURL
 * باید با دامنه سایت پذیرنده یکی باشد.
 */

/* =========================================================================
 *  Enumها
 * ========================================================================= */

/** نوع تراکنش (مسیر صفحه پرداخت) */
enum SepehrType: string
{
    case Pay        = 'Pay';
    case Bill       = 'Bill';
    case BatchBill  = 'BatchBill';
    case Charge     = 'Charge';
    case MPay       = 'Mpay';
    case MBill      = 'MBill';
    case MBatchBill = 'MBatchBill';
    case MCharge    = 'MCharge';
}

/** اپراتور (برای payload شارژ) */
enum Operator: int
{
    case Mtn     = 0; // ایرانسل
    case Mci     = 1; // همراه اول
    case Rightel = 2; // رایتل

    /** نام اپراتور در سرویس GetDataPlanList */
    public function apiName(): string
    {
        return match ($this) {
            self::Mtn     => 'Mtn',
            self::Mci     => 'Mci',
            self::Rightel => 'Rightel',
        };
    }
}

enum ChargeType: int
{
    case Normal     = 0;
    case MtnWow     = 1;
    case RightelWow = 2;
}

enum RequestType: int
{
    case Topup    = 0;
    case Voucher  = 1;
    case DataPlan = 2;
}

/** نوع بسته اینترنتی */
enum DataPlanType: int
{
    case PermanentAll = 0; // دائمی سه اپراتور
    case CreditAll    = 1; // اعتباری سه اپراتور
    case TdLteMtn     = 2; // TDLTE ایرانسل
    case RightelData  = 3; // دیتای رایتل
}

/** وضعیت پاسخ بازگشتی از درگاه بر اساس respcode */
enum CallbackStatus: string
{
    case Success  = 'success';
    case Canceled = 'canceled';
    case Timeout  = 'timeout';
    case Failed   = 'failed';

    public static function fromRespCode(?int $code): self
    {
        return match ($code) {
            0       => self::Success,
            -1      => self::Canceled,
            -2      => self::Timeout,
            default => self::Failed,
        };
    }
}

/** نتیجه نهایی فرایند تایید خرید */
enum VerifyStatus: string
{
    case Verified         = 'verified';
    case Canceled         = 'canceled';
    case Timeout          = 'timeout';
    case Failed           = 'failed';
    case Invalid          = 'invalid';
    case DuplicateReceipt = 'duplicate_receipt';
    case AdviceFailed     = 'advice_failed';
    case AmountMismatch   = 'amount_mismatch';
}

/* =========================================================================
 *  Exception و DTOها
 * ========================================================================= */

class SepehrException extends RuntimeException
{
}

/** پارامترهای بازگشتی از سپهر (POST/GET) */
final readonly class SepehrCallback
{
    public function __construct(
        public ?int $respCode,
        public ?string $respMsg,
        public ?string $amount,
        public ?string $invoiceId,
        public ?string $payload,
        public ?string $terminalId,
        public ?string $traceNumber,
        public ?string $rrn,
        public ?string $datePaid,
        public ?string $digitalReceipt,
        public ?string $issuerBank,
        public ?string $billId,
        public ?string $payId,
        public ?string $cardNumber,
        public ?string $pinCharge,
        public ?string $refCharge,
        public ?string $serialCharge,
        public ?string $hash,
    ) {
    }

    /** خواندن از $_POST و $_GET (یا آرایه دلخواه) */
    public static function fromArray(?array $input = null): self
    {
        $in  = array_change_key_case($input ?? [...$_GET, ...$_POST], CASE_LOWER);
        $str = static fn (string $k): ?string => isset($in[$k]) && is_scalar($in[$k]) ? trim((string) $in[$k]) : null;

        return new self(
            respCode:       isset($in['respcode']) && is_numeric($in['respcode']) ? (int) $in['respcode'] : null,
            respMsg:        $str('respmsg'),
            amount:         $str('amount'),
            invoiceId:      $str('invoiceid'),
            payload:        $str('payload'),
            terminalId:     $str('terminalid'),
            traceNumber:    $str('tracenumber'),
            rrn:            $str('rrn'),
            datePaid:       $str('datepaid'),
            digitalReceipt: $str('digitalreceipt'),
            issuerBank:     $str('issuerbank'),
            billId:         $str('billid'),
            payId:          $str('payid'),
            cardNumber:     $str('cardnumber'),
            pinCharge:      $str('pincharge'),
            refCharge:      $str('refcharge'),
            serialCharge:   $str('serialcharge'),
            hash:           $str('hash'),
        );
    }

    public function status(): CallbackStatus
    {
        return CallbackStatus::fromRespCode($this->respCode);
    }
}

/** پاسخ سرویس Advice / Rollback */
final class SepehrAdviceResult
{
    public function __construct(
        public readonly string $status,   // Ok / Duplicate / NOk
        public readonly string $returnId, // مبلغ (Ok/Duplicate) یا کد خطا (NOk)
        public readonly string $message,
    ) {
    }

    // Property Hooks (PHP 8.4) - propertyهای مجازی
    public bool $ok {
        get => strcasecmp($this->status, 'ok') === 0;
    }

    public bool $duplicate {
        get => strcasecmp($this->status, 'duplicate') === 0;
    }

    public ?string $error {
        get => strcasecmp($this->status, 'nok') === 0
            ? Sepehr::errorMessage((int) $this->returnId)
            : null;
    }

    public int $amount {
        get => ($this->ok || $this->duplicate) ? (int) $this->returnId : 0;
    }
}

/** نتیجه فرایند verifyPurchase */
final readonly class SepehrVerifyResult
{
    public function __construct(
        public VerifyStatus $status,
        public string $message,
        public SepehrCallback $callback,
        public ?int $verifiedAmount = null,
    ) {
    }

    public function success(): bool
    {
        return $this->status === VerifyStatus::Verified;
    }
}

/* =========================================================================
 *  کلاس اصلی
 * ========================================================================= */

final class Sepehr
{
    public const string BASE_URL = 'https://sepehr.shaparak.ir';

    /** کدهای خطای Token / Advice / Rollback */
    public const array ERRORS = [
        -1 => 'تراکنش پیدا نشد.',
        -2 => 'عدم تطابق IP هنگام دریافت توکن / تراکنش قبلا Reverse شده است.',
        -3 => 'خطای عمومی (Exception).',
        -4 => 'امکان انجام درخواست برای این تراکنش وجود ندارد (عدم تطابق callback URL).',
        -5 => 'آدرس IP نامعتبر است (در لیست IPهای معرفی‌شده نیست).',
        -6 => 'سرویس برگشت تراکنش برای پذیرنده فعال نیست.',
    ];

    /**
     * @param string      $terminalId      شماره ترمینال ۸ رقمی
     * @param string|null $defaultCallback آدرس بازگشت پیش‌فرض
     * @param int         $timeout         تایم‌اوت درخواست‌ها (ثانیه)
     * @param bool        $verifySsl       بررسی SSL (در پروداکشن true بماند)
     */
    public function __construct(
        private readonly string $terminalId,
        private readonly ?string $defaultCallback = null,
        private readonly int $timeout = 30,
        private readonly bool $verifySsl = true,
    ) {
        if (!preg_match('/^\d{8}$/', $terminalId)) {
            throw new SepehrException('شماره ترمینال باید ۸ رقم باشد.');
        }
    }

    public static function errorMessage(int $code): string
    {
        return self::ERRORS[$code] ?? "کد خطای ناشناخته: {$code}";
    }

    /* =====================================================================
     *  ۱) دریافت توکن
     * ===================================================================== */

    /**
     * متد پایه دریافت توکن برای همه سرویس‌ها
     *
     * @param int               $amount      مبلغ به ریال (حداقل 1000)
     * @param string            $invoiceId   شماره فاکتور یکتا (حداکثر 100 کاراکتر)
     * @param string|null       $callbackUrl آدرس بازگشت (حداکثر 500)
     * @param array|string|null $payload     اطلاعات تکمیلی (آرایه => JSON)
     * @param array<string,mixed> $extra     فیلدهای اضافه (BillID, PayID, CellNumber, email)
     */
    public function getToken(
        int $amount,
        string $invoiceId,
        ?string $callbackUrl = null,
        array|string|null $payload = null,
        array $extra = [],
    ): string {
        $callbackUrl ??= $this->defaultCallback;

        if ($amount < 1000) {
            throw new SepehrException('حداقل مبلغ 1000 ریال است.');
        }
        if ($invoiceId === '' || mb_strlen($invoiceId) > 100) {
            throw new SepehrException('شماره فاکتور الزامی و حداکثر 100 کاراکتر است.');
        }
        if ($callbackUrl === null || !preg_match('#^https?://#i', $callbackUrl) || mb_strlen($callbackUrl) > 500) {
            throw new SepehrException('callbackURL باید آدرس کامل http/https و حداکثر 500 کاراکتر باشد.');
        }

        $body = [
            'TerminalID'  => $this->terminalId,
            'Amount'      => (string) $amount,
            'InvoiceID'   => $invoiceId,
            'callbackURL' => $callbackUrl,
            'payload'     => $this->encodePayload($payload),
            ...$extra,
        ];

        $res  = array_change_key_case($this->request('POST', '/Rest/V1/PeymentApi/GetToken', $body), CASE_LOWER);
        $code = (int) ($res['status'] ?? -3);

        if ($code !== 0 || empty($res['accesstoken'])) {
            throw new SepehrException('خطا در دریافت توکن: ' . self::errorMessage($code), $code);
        }
        return (string) $res['accesstoken'];
    }

    /** توکن خرید ساده (با امکان ایمیل و تسهیم از طریق payload) */
    public function purchaseToken(
        int $amount,
        string $invoiceId,
        ?string $callbackUrl = null,
        array|string|null $payload = null,
        ?string $email = null,
    ): string {
        return $this->getToken($amount, $invoiceId, $callbackUrl, $payload, $email ? ['email' => $email] : []);
    }

    /** توکن خرید با شماره موبایل (نمایش کارت‌های ثبت‌شده در سرویس پیوند) */
    public function purchaseTokenByMobile(
        int $amount,
        string $invoiceId,
        string $cellNumber,
        ?string $callbackUrl = null,
        array|string|null $payload = null,
    ): string {
        return $this->getToken($amount, $invoiceId, $callbackUrl, $payload, [
            'CellNumber' => $this->checkMobile($cellNumber),
        ]);
    }

    /** توکن پرداخت قبض (۱۳ یا ۱۸ رقمی) */
    public function billToken(
        int $amount,
        string $invoiceId,
        string $billId,
        string $payId,
        ?string $callbackUrl = null,
        array|string|null $payload = null,
    ): string {
        $this->checkBill($billId, $payId);
        return $this->getToken($amount, $invoiceId, $callbackUrl, $payload, [
            'BillID' => $billId,
            'PayID'  => $payId,
        ]);
    }

    /** توکن پرداخت قبض با شماره موبایل */
    public function billTokenByMobile(
        int $amount,
        string $invoiceId,
        string $billId,
        string $payId,
        string $cellNumber,
        ?string $callbackUrl = null,
        array|string|null $payload = null,
    ): string {
        $this->checkBill($billId, $payId);
        return $this->getToken($amount, $invoiceId, $callbackUrl, $payload, [
            'BillID'     => $billId,
            'PayID'      => $payId,
            'CellNumber' => $this->checkMobile($cellNumber),
        ]);
    }

    /**
     * توکن پرداخت قبض گروهی (حداکثر ۱۰ قبض، فقط رمز استاتیک، سقف ۱,۰۰۰,۰۰۰ ریال)
     *
     * @param list<array{BillID:string,PayID:string}> $bills
     */
    public function batchBillToken(
        int $amount,
        string $invoiceId,
        array $bills,
        ?string $callbackUrl = null,
        ?string $cellNumber = null,
    ): string {
        if ($amount > 1_000_000) {
            throw new SepehrException('سقف مبلغ پرداخت قبض گروهی 1,000,000 ریال است.');
        }
        $extra = $cellNumber !== null ? ['CellNumber' => $this->checkMobile($cellNumber)] : [];
        return $this->getToken($amount, $invoiceId, $callbackUrl, $this->buildBatchBillPayload($bills), $extra);
    }

    /** توکن شارژ / بسته اینترنتی (payload را با buildChargePayload بسازید) */
    public function chargeToken(
        int $amount,
        string $invoiceId,
        array $chargePayload,
        ?string $callbackUrl = null,
        ?string $cellNumber = null,
    ): string {
        $extra = $cellNumber !== null ? ['CellNumber' => $this->checkMobile($cellNumber)] : [];
        return $this->getToken($amount, $invoiceId, $callbackUrl, $chargePayload, $extra);
    }

    /* =====================================================================
     *  ۲) ساخت payloadهای خاص
     * ===================================================================== */

    /**
     * تسهیم با مقدار متغیر بر اساس اندیس حساب (حداکثر ۹ حساب)
     *
     * @param list<array{Account:string,Amount:int|string}> $items
     */
    public function buildSplitByAccount(array $items, string $id): array
    {
        return $this->buildSplit($items, 'Account', 'SList', $id);
    }

    /**
     * تسهیم بر اساس شبا (در ارسال هم‌زمان شبا و اندیس، شبا ملاک است)
     *
     * @param list<array{Iban:string,Amount:int|string}> $items
     */
    public function buildSplitByIban(array $items, string $id): array
    {
        return $this->buildSplit($items, 'Iban', 'Slist', $id);
    }

    private function buildSplit(array $items, string $key, string $listName, string $id): array
    {
        if ($items === [] || count($items) > 9) {
            throw new SepehrException('تعداد حساب‌های تسهیم باید بین 1 تا 9 باشد.');
        }
        if (array_any($items, static fn (array $it): bool => !isset($it[$key], $it['Amount']))) {
            throw new SepehrException("هر آیتم تسهیم باید {$key} و Amount داشته باشد.");
        }

        return [
            $listName => array_map(
                static fn (array $it): array => [$key => (string) $it[$key], 'Amount' => (string) $it['Amount']],
                array_values($items),
            ),
            'Id' => $id,
        ];
    }

    /**
     * payload قبض گروهی
     *
     * @param list<array{BillID:string,PayID:string}> $bills
     */
    public function buildBatchBillPayload(array $bills): array
    {
        if ($bills === [] || count($bills) > 10) {
            throw new SepehrException('تعداد قبض گروهی باید بین 1 تا 10 باشد.');
        }
        $list = [];
        foreach ($bills as $b) {
            $bill = (string) ($b['BillID'] ?? '');
            $pay  = (string) ($b['PayID'] ?? '');
            $this->checkBill($bill, $pay);
            $list[] = ['BillID' => $bill, 'PayID' => $pay];
        }
        return ['BList' => $list];
    }

    /** payload شارژ و بسته اینترنتی */
    public function buildChargePayload(
        string $mobile,
        Operator $operator,
        ChargeType $chargeType = ChargeType::Normal,
        RequestType $requestType = RequestType::Topup,
        int $dataPlanId = 0,
        string $additionalData = '',
    ): array {
        return [
            'mobile'         => $this->checkMobile($mobile),
            'operator'       => $operator->value,
            'chargeType'     => $chargeType->value,
            'requestType'    => $requestType->value,
            'dataPlanId'     => $dataPlanId,
            'AdditionalData' => $additionalData,
        ];
    }

    /* =====================================================================
     *  ۳) هدایت مشتری به صفحه پرداخت
     * ===================================================================== */

    /** لینک GET پرداخت (فقط برای خرید ساده طبق مستند) */
    public function paymentUrl(string $token): string
    {
        return self::BASE_URL . '/Payment/Pay?' . http_build_query([
            'token'      => $token,
            'terminalId' => $this->terminalId,
        ]);
    }

    /**
     * ساخت فرم HTML خودکار برای هدایت (POST) به درگاه
     *
     * @param bool        $returnByGet  true => نتیجه با GET برگردد (getMethod=1)
     * @param string|null $nationalCode کد ملی (تطابق کارت) یا شناسه پرداخت (خرید شناسه‌دار)
     */
    public function redirectForm(
        string $token,
        SepehrType $type = SepehrType::Pay,
        bool $returnByGet = false,
        ?string $nationalCode = null,
    ): string {
        if ($nationalCode !== null && mb_strlen($nationalCode) > 50) {
            throw new SepehrException('nationalCode حداکثر 50 کاراکتر است.');
        }

        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $fields = ['TerminalID' => $this->terminalId, 'token' => $token];
        if ($returnByGet) {
            $fields['getMethod'] = '1';
        }
        if ($nationalCode !== null) {
            $fields['nationalCode'] = $nationalCode;
        }

        $inputs = '';
        foreach ($fields as $name => $value) {
            $inputs .= '<input type="hidden" name="' . $e($name) . '" value="' . $e($value) . '">';
        }

        return '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<title>انتقال به درگاه</title></head>'
            . '<body onload="document.getElementById(\'sepehr\').submit()">'
            . '<form id="sepehr" method="post" action="' . $e(self::BASE_URL . '/' . $type->value) . '">'
            . $inputs
            . '<noscript><button type="submit">ادامه پرداخت</button></noscript>'
            . '</form></body></html>';
    }

    /** چاپ فرم و پایان اسکریپت */
    public function redirect(
        string $token,
        SepehrType $type = SepehrType::Pay,
        bool $returnByGet = false,
        ?string $nationalCode = null,
    ): never {
        echo $this->redirectForm($token, $type, $returnByGet, $nationalCode);
        exit;
    }

    /* =====================================================================
     *  ۴) پردازش پاسخ بازگشتی (Callback)
     * ===================================================================== */

    public function parseCallback(?array $input = null): SepehrCallback
    {
        return SepehrCallback::fromArray($input);
    }

    /**
     * فرایند کامل تایید خرید (طبق نکات امنیتی مستند):
     *  1) بررسی respcode و ترمینال
     *  2) بررسی تکراری نبودن رسید دیجیتال
     *  3) فراخوانی Advice
     *  4) مقایسه مبلغ بازگشتی با مبلغ فاکتور سمت شما
     *  5) (اختیاری) Rollback در صورت مغایرت مبلغ
     *
     * @param int                         $expectedAmount     مبلغ فاکتور در دیتابیس شما (ریال)
     * @param (callable(string):bool)|null $isReceiptUsed     true => این رسید قبلا ثبت شده
     * @param bool                        $rollbackOnMismatch نیازمند فعال بودن سرویس Rollback
     */
    public function verifyPurchase(
        SepehrCallback $cb,
        int $expectedAmount,
        ?callable $isReceiptUsed = null,
        bool $rollbackOnMismatch = false,
    ): SepehrVerifyResult {
        $fail = static fn (VerifyStatus $st, string $msg): SepehrVerifyResult => new SepehrVerifyResult($st, $msg, $cb);

        switch ($cb->status()) {
            case CallbackStatus::Canceled:
                return $fail(VerifyStatus::Canceled, 'کاربر از پرداخت انصراف داد.');
            case CallbackStatus::Timeout:
                return $fail(VerifyStatus::Timeout, 'زمان انجام تراکنش به پایان رسید.');
            case CallbackStatus::Failed:
                return $fail(VerifyStatus::Failed, $cb->respMsg ?: 'تراکنش ناموفق بود.');
            case CallbackStatus::Success:
                break;
        }

        if ($cb->digitalReceipt === null || $cb->digitalReceipt === '') {
            return $fail(VerifyStatus::Invalid, 'رسید دیجیتال دریافت نشد.');
        }
        if ($cb->terminalId !== $this->terminalId) {
            return $fail(VerifyStatus::Invalid, 'شماره ترمینال با ترمینال شما مطابقت ندارد.');
        }
        if ($isReceiptUsed !== null && $isReceiptUsed($cb->digitalReceipt)) {
            return $fail(VerifyStatus::DuplicateReceipt, 'این رسید دیجیتال قبلا استفاده شده است.');
        }

        $advice = $this->advice($cb->digitalReceipt);

        if (!$advice->ok && !$advice->duplicate) {
            return $fail(VerifyStatus::AdviceFailed, 'تایید تراکنش ناموفق بود: ' . ($advice->error ?? $advice->message));
        }
        // Duplicate یعنی قبلا Advice شده؛ بدون چک دیتابیس نباید دوباره خدمات داده شود
        if ($advice->duplicate && $isReceiptUsed === null) {
            return $fail(VerifyStatus::DuplicateReceipt, 'تایید تکراری؛ تراکنش قبلا تایید شده است.');
        }

        if ($advice->amount !== $expectedAmount) {
            if ($rollbackOnMismatch) {
                try {
                    $this->rollback($cb->digitalReceipt);
                } catch (SepehrException) {
                    // خطای Rollback نباید نتیجه اصلی را تغییر دهد
                }
            }
            return $fail(VerifyStatus::AmountMismatch, 'مبلغ تراکنش با مبلغ فاکتور مطابقت ندارد؛ خدمات ارائه نشود.');
        }

        return new SepehrVerifyResult(VerifyStatus::Verified, 'تراکنش با موفقیت تایید شد.', $cb, $advice->amount);
    }

    /* =====================================================================
     *  ۵) Advice / Rollback / استعلام
     * ===================================================================== */

    /**
     * تایید تراکنش (Advice) - باید ظرف ۳۰ دقیقه انجام شود.
     * برای استعلام مجدد وضعیت هم قابل استفاده است.
     * (تراکنش‌های قبض و شارژ AutoAdvice هستند)
     */
    public function advice(string $digitalReceipt): SepehrAdviceResult
    {
        return $this->adviceLike('/Rest/V1/PeymentApi/Advice', $digitalReceipt);
    }

    /** برگشت تراکنش (پیش‌فرض غیرفعال؛ باید از واحد عملیات سپهر درخواست شود) */
    public function rollback(string $digitalReceipt): SepehrAdviceResult
    {
        return $this->adviceLike('/Rest/V1/PeymentApi/Rollback', $digitalReceipt);
    }

    private function adviceLike(string $path, string $digitalReceipt): SepehrAdviceResult
    {
        if ($digitalReceipt === '') {
            throw new SepehrException('رسید دیجیتال خالی است.');
        }

        $res = array_change_key_case($this->request('POST', $path, [
            'digitalreceipt' => $digitalReceipt,
            'Tid'            => $this->terminalId,
        ]), CASE_LOWER);

        $status = (string) ($res['status'] ?? '');
        if ($status === '0') {
            $status = 'Ok';
        }

        return new SepehrAdviceResult(
            status:   $status,
            returnId: (string) ($res['returnid'] ?? ''),
            message:  (string) ($res['message'] ?? ''),
        );
    }

    /**
     * استعلام شارژ بر اساس RRN
     *
     * @return array{requestStatus:bool,amount:mixed,insertDate:mixed,rrn:mixed,raw:array}
     */
    public function inquiryCharge(string $rrn): array
    {
        $res = array_change_key_case($this->request('POST', '/Rest/V1/PeymentApi/Inquiry', ['Rrn' => $rrn]), CASE_LOWER);

        return [
            'requestStatus' => (bool) ($res['requeststatus'] ?? false),
            'amount'        => $res['amount'] ?? null,
            'insertDate'    => $res['insertdate'] ?? null,
            'rrn'           => $res['rrnnumber'] ?? null,
            'raw'           => $res,
        ];
    }

    /* =====================================================================
     *  ۶) بسته‌های اینترنتی
     * ===================================================================== */

    /**
     * دریافت لیست بسته‌های اینترنتی
     *
     * @return array{additionalData:string,plans:list<array>}
     */
    public function getDataPlanList(DataPlanType $type, Operator $operator, string $cellNumber): array
    {
        $res = array_change_key_case($this->request('POST', '/Rest/api/Charge/GetDataPlanList', [
            'DataPlanType' => $type->value,
            'Op'           => $operator->apiName(),
            'CellNumber'   => $this->checkMobile($cellNumber),
        ]), CASE_LOWER);

        $status = (int) ($res['status'] ?? 5);
        if ($status !== 0) {
            $fallback = match ($status) {
                4       => 'پارامتر ارسالی نامعتبر',
                5       => 'خطای سیستمی',
                default => 'نامشخص',
            };
            throw new SepehrException('دریافت بسته‌ها ناموفق: ' . ($res['message'] ?? $fallback), $status);
        }

        return [
            // برای رایتل باید AdditionalData هنگام خرید به صفحه پرداخت ارسال شود
            'additionalData' => (string) ($res['additionaldata'] ?? ''),
            'plans'          => $res['dataplans'] ?? [],
        ];
    }

    /** پیدا کردن یک بسته بر اساس ProfileId از لیست بسته‌ها */
    public function findDataPlan(array $plans, int $profileId): ?array
    {
        return array_find(
            $plans,
            static fn (array $p): bool => (int) ($p['ProfileId'] ?? $p['profileId'] ?? -1) === $profileId,
        );
    }

    /* =====================================================================
     *  ۷) چک سلامت سرویس
     * ===================================================================== */

    /** @return array{token:bool,ui:bool,all:bool} */
    public function checkService(): array
    {
        $token = $this->ping('/Rest/V1/PeymentApi/CheckService');
        $ui    = $this->ping('/Payment/CheckService');

        return ['token' => $token, 'ui' => $ui, 'all' => $token && $ui];
    }

    public function isServiceUp(): bool
    {
        return $this->checkService()['all'];
    }

    private function ping(string $path): bool
    {
        try {
            return stripos($this->requestRaw('GET', $path), 'Service is up') !== false;
        } catch (SepehrException) {
            return false;
        }
    }

    /* =====================================================================
     *  ابزارهای داخلی
     * ===================================================================== */

    private function encodePayload(array|string|null $payload): string
    {
        if ($payload === null || $payload === '' || $payload === []) {
            return '';
        }
        $str = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : $payload;

        if (mb_strlen($str) > 500) {
            throw new SepehrException('طول payload بیش از حد مجاز است (خرید: 300، سایر: 500 کاراکتر).');
        }
        return $str;
    }

    private function checkMobile(string $mobile): string
    {
        $mobile = preg_replace('/\D/', '', $mobile) ?? '';
        if (!preg_match('/^09\d{9}$/', $mobile)) {
            throw new SepehrException('شماره موبایل باید ۱۱ رقم و با 09 شروع شود.');
        }
        return $mobile;
    }

    private function checkBill(string $billId, string $payId): void
    {
        if (!preg_match('/^(?:\d{13}|\d{18})$/', $billId)) {
            throw new SepehrException('شناسه قبض باید ۱۳ یا ۱۸ رقم باشد.');
        }
        if (!preg_match('/^\d{1,13}$/', $payId)) {
            throw new SepehrException('شناسه پرداخت باید حداکثر ۱۳ رقم باشد.');
        }
    }

    /** @return array<string,mixed> */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $response = $this->requestRaw($method, $path, $body);

        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SepehrException('پاسخ JSON نامعتبر از سپهر: ' . mb_substr($response, 0, 200), 0, $e);
        }
        if (!is_array($data)) {
            throw new SepehrException('ساختار پاسخ سپهر نامعتبر است.');
        }
        return $data;
    }

    private function requestRaw(string $method, string $path, ?array $body = null): string
    {
        $ch = curl_init(self::BASE_URL . $path);
        if ($ch === false) {
            throw new SepehrException('امکان راه‌اندازی cURL وجود ندارد.');
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode(
                $body ?? [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        }
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $http     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($errno !== 0 || $response === false) {
            throw new SepehrException("خطای ارتباط با سپهر: {$error}", $errno);
        }
        if ($http < 200 || $http >= 300) {
            throw new SepehrException("پاسخ نامعتبر از سپهر (HTTP {$http}): " . mb_substr((string) $response, 0, 200), $http);
        }
        return (string) $response;
    }
}