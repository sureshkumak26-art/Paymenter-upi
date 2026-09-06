<?php

namespace Paymenter\Extensions\Gateways\ZapPay;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Throwable;

#[ExtensionMeta(
    name: 'ZapPay UPI',
    description: 'Accept INR UPI payments through ZapPay ZapAPI with server-side verification.',
    version: '1.0.0',
    author: 'Suresh Kumar',
    url: 'https://github.com/sureshkumak26-art/Paymenter-upi',
    icon: 'https://zappay.shop/favicon.ico'
)]
class ZapPay extends Gateway
{
    public function boot()
    {
        View::addNamespace('gateways.zappay', __DIR__ . '/resources/views');
        require __DIR__ . '/routes.php';
    }

    public function getConfig($values = [])
    {
        return [
            ['name' => 'api_key', 'label' => 'ZapAPI Key', 'type' => 'text', 'encrypted' => true, 'required' => true, 'description' => 'Developer Portal → Zap API. Keep this secret.'],
            ['name' => 'api_base', 'label' => 'ZapAPI Base URL', 'type' => 'text', 'default' => 'https://zappay-beta.vercel.app', 'required' => true],
            ['name' => 'title_prefix', 'label' => 'Payment Title Prefix', 'type' => 'text', 'default' => 'Paymenter Invoice', 'required' => false],
            ['name' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'text', 'encrypted' => true, 'required' => false],
            ['name' => 'timeout', 'label' => 'API Timeout', 'type' => 'number', 'default' => 15, 'required' => true],
        ];
    }

    public function canUseGateway($total, $currency, $type, $items = [])
    {
        return strtoupper((string) $currency) === 'INR' && is_numeric($total) && (float) $total >= 1 && (float) $total <= 5000;
    }

    public function pay(Invoice $invoice, $total)
    {
        if (strtoupper((string) $invoice->currency_code) !== 'INR') {
            throw new \RuntimeException('ZapPay supports INR invoices only.');
        }

        $amount = $this->amount($total);
        if ($amount < 1 || $amount > 5000) {
            throw new \RuntimeException('ZapPay amount must be between ₹1 and ₹5,000.');
        }

        $payload = [
            'amount' => $amount,
            'title' => Str::limit(trim(($this->config('title_prefix') ?: 'Paymenter Invoice') . ' #' . $invoice->id), 100, ''),
            'redirect_url' => route('extensions.gateways.zappay.return', ['invoice' => $invoice->id]),
        ];

        $mobile = $this->customerMobile($invoice);
        if ($mobile) {
            $payload['customer_mobile'] = $mobile;
        }

        $response = $this->client()->post('/api/developer/create-order', $payload);
        $data = $response->json();

        if (!$response->successful() || ($data['success'] ?? false) !== true) {
            throw new \RuntimeException($data['message'] ?? 'ZapPay order creation failed.');
        }

        $orderId = (string) ($data['data']['order_id'] ?? '');
        $paymentUrl = (string) ($data['data']['payment_url'] ?? '');
        if ($orderId === '' || $paymentUrl === '') {
            throw new \RuntimeException('ZapPay returned an invalid order response.');
        }

        Cache::put($this->cacheKey($orderId), $invoice->id, now()->addDay());

        return view('gateways.zappay::pay', [
            'invoice' => $invoice,
            'total' => $amount,
            'orderId' => $orderId,
            'paymentUrl' => $paymentUrl,
            'checkUrl' => route('extensions.gateways.zappay.check', ['invoice' => $invoice->id, 'order' => $orderId]),
        ]);
    }

    public function check(Request $request)
    {
        $invoice = Invoice::findOrFail((int) $request->query('invoice'));
        $orderId = trim((string) $request->query('order'));

        if ($orderId === '' || (int) Cache::get($this->cacheKey($orderId)) !== (int) $invoice->id) {
            return response()->json(['status' => 'error', 'message' => 'Invalid payment reference.'], 400);
        }

        return $this->verifyAndPay($invoice, $orderId);
    }

    public function return(Request $request)
    {
        $invoice = Invoice::findOrFail((int) $request->query('invoice'));
        $orderId = trim((string) $request->query('zp_order'));

        if ($orderId !== '' && (int) Cache::get($this->cacheKey($orderId)) === (int) $invoice->id) {
            $result = $this->verifyAndPay($invoice, $orderId);
            if (($result->getData(true)['status'] ?? null) === 'success') {
                return redirect()->route('invoices.show', $invoice);
            }
        }

        return redirect()->route('invoices.show', $invoice);
    }

    public function webhook(Request $request)
    {
        if (!$this->validWebhook($request)) {
            return response()->json(['error' => 'Invalid webhook secret'], 401);
        }

        $orderId = trim((string) $request->input('order_id'));
        if ($orderId === '') {
            return response()->json(['error' => 'Missing order_id'], 400);
        }

        $invoiceId = Cache::get($this->cacheKey($orderId));
        if (!$invoiceId) {
            return response()->json(['status' => 'ignored', 'reason' => 'Unknown order'], 200);
        }

        $invoice = Invoice::find($invoiceId);
        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        return $this->verifyAndPay($invoice, $orderId);
    }

    private function verifyAndPay(Invoice $invoice, string $orderId)
    {
        try {
            $response = $this->client()->get('/api/developer/order-status/' . rawurlencode($orderId));
            $data = $response->json();

            if (!$response->successful() || ($data['success'] ?? false) !== true) {
                return response()->json(['status' => 'error', 'message' => $data['message'] ?? 'Unable to verify payment.'], 502);
            }

            $payment = $data['data'] ?? [];
            $status = (string) ($payment['status'] ?? 'pending');
            if ($status !== 'success') {
                return response()->json(['status' => $status, 'order_id' => $orderId], 200);
            }

            $providerAmount = $this->amount($payment['amount'] ?? 0);
            $invoiceAmount = $this->amount($invoice->remaining);
            if ($providerAmount <= 0 || abs($providerAmount - $invoiceAmount) > 0.009) {
                return response()->json(['status' => 'error', 'message' => 'Payment amount mismatch.'], 400);
            }

            $transactionId = (string) ($payment['utr'] ?? $payment['txn_id'] ?? $payment['order_id'] ?? $orderId);
            if ($transactionId === '') {
                $transactionId = $orderId;
            }

            ExtensionHelper::addPayment($invoice->id, 'ZapPay UPI', $providerAmount, null, $transactionId);
            Cache::forget($this->cacheKey($orderId));

            return response()->json(['status' => 'success', 'order_id' => $orderId], 200);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Payment verification failed.'], 502);
        }
    }

    private function client()
    {
        return Http::baseUrl(rtrim((string) ($this->config('api_base') ?: 'https://zappay-beta.vercel.app'), '/'))
            ->acceptJson()->asJson()
            ->withHeaders(['X-ZapAPI-Key' => trim((string) $this->config('api_key'))])
            ->timeout(max(5, (int) ($this->config('timeout') ?: 15)));
    }

    private function validWebhook(Request $request): bool
    {
        $secret = trim((string) ($this->config('webhook_secret') ?: ''));
        if ($secret === '') return true;
        $provided = (string) ($request->header('X-ZapPay-Webhook-Secret') ?: $request->header('X-Webhook-Secret') ?: $request->input('secret', ''));
        return hash_equals($secret, $provided);
    }

    private function cacheKey(string $orderId): string
    {
        return 'paymenter:zappay:order:' . hash('sha256', $orderId);
    }

    private function amount($value): float
    {
        if (is_string($value)) $value = preg_replace('/[^0-9.]/', '', $value);
        return round((float) $value, 2);
    }

    private function customerMobile(Invoice $invoice): ?string
    {
        $user = $invoice->user;
        if (!$user) return null;
        $phone = $user->phone ?? null;
        if (!$phone && method_exists($user, 'properties')) $phone = $user->properties()->where('key', 'phone')->first()?->value;
        if (!$phone) return null;
        $phone = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($phone) === 12 && str_starts_with($phone, '91')) $phone = substr($phone, 2);
        return preg_match('/^[6-9]\d{9}$/', $phone) ? $phone : null;
    }
}
