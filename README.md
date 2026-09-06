# Paymenter ZapPay UPI Gateway

Direct ZapPay/ZapAPI gateway extension for Paymenter.

## Included

- INR-only gateway
- Server-side ZapAPI order creation
- ZapPay payment URL checkout
- Server-side `order-status` verification
- Amount validation before crediting an invoice
- Optional ZapPay webhook endpoint
- Transaction ID/UTR support
- Automatic browser polling that never treats a redirect as proof of payment

## Install

Copy `extensions/Gateways/ZapPay` into your Paymenter installation:

```bash
mkdir -p /var/www/paymenter/extensions/Gateways
cp -a extensions/Gateways/ZapPay /var/www/paymenter/extensions/Gateways/
cd /var/www/paymenter
php artisan optimize:clear
```

Then enable **ZapPay UPI** under the Paymenter gateway settings.

## Settings

- **ZapAPI Key:** your ZapPay Developer Portal key.
- **ZapAPI Base URL:** use the current base URL shown in your ZapPay Developer Portal. The default in this repository is `https://zappay-beta.vercel.app`.
- **Payment Title Prefix:** displayed as the ZapPay payment remark.
- **Webhook Secret:** optional local verification secret.
- **API Timeout:** normally 15 seconds.

## Webhook

Configure this URL in ZapPay:

`https://YOUR-PAYMENTER-DOMAIN/extensions/gateways/zappay/webhook`

ZapPay webhooks are only a notification trigger. The extension always calls `GET /api/developer/order-status/:orderId` from the Paymenter server before adding the payment.

## Security

The extension deliberately does not trust `zp_result=success`, browser redirects, popup close events, or client-side callbacks. A payment is credited only when ZapPay's server-side status response contains `status === success` and the provider amount matches the invoice amount.

The amount is normalized to a plain number before `create-order`, because ZapAPI requires a numeric amount and documents a ₹1–₹5,000 limit.

Never commit a real ZapAPI key to GitHub. Store it in Paymenter's encrypted gateway settings.
