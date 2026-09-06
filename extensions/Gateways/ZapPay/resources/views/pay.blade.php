<div class="space-y-4">
    <div class="rounded-xl border border-white/10 bg-black/20 p-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-sm opacity-70">ZapPay UPI</p>
                <p class="text-2xl font-semibold">₹{{ number_format($total, 2) }}</p>
            </div>
            <span id="zappay-status" class="rounded-full bg-yellow-500/10 px-3 py-1 text-xs text-yellow-400">Waiting</span>
        </div>
        <p class="mt-3 text-sm opacity-70">Order: <span class="font-mono">{{ $orderId }}</span></p>
    </div>

    <a href="{{ $paymentUrl }}" class="block w-full rounded-xl bg-secondary-500 px-4 py-3 text-center font-semibold text-white hover:opacity-90">
        Pay ₹{{ number_format($total, 2) }} with UPI
    </a>

    <p class="text-center text-xs opacity-60">After paying, keep this page open. Paymenter will verify the payment directly with ZapPay.</p>
</div>

<script>
(() => {
    const status = document.getElementById('zappay-status');
    const checkUrl = @json($checkUrl);
    let stopped = false;

    async function check() {
        if (stopped) return;
        try {
            const response = await fetch(checkUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = await response.json();

            if (data.status === 'success') {
                stopped = true;
                status.textContent = 'Verified';
                status.className = 'rounded-full bg-green-500/10 px-3 py-1 text-xs text-green-400';
                window.location.reload();
                return;
            }
            if (data.status === 'failed') {
                stopped = true;
                status.textContent = 'Failed';
                status.className = 'rounded-full bg-red-500/10 px-3 py-1 text-xs text-red-400';
                return;
            }
        } catch (_) {}
        setTimeout(check, 3000);
    }

    setTimeout(check, 3000);
})();
</script>
