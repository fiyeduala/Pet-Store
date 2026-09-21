<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Payments\PaymentService;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Inbound payment notification.
     *
     * Always answers 200 once the event is recorded, so the provider does
     * not retry forever over an event we have deliberately ignored. What
     * actually happened is recorded on the payment_events row.
     */
    public function payment(Request $request, string $gateway): JsonResponse
    {
        $event = $this->payments->handleWebhook($gateway, $request);

        return response()->json([
            'received' => true,
            'status' => $event->status,
        ]);
    }

    /**
     * Inbound supplier notification.
     *
     * CJ webhook support has not been verified for this account, so this
     * endpoint records what arrives and does not act on it until the
     * supplier's `webhooks` capability has been confirmed. Polling
     * (RefreshTracking) is the supported path meanwhile.
     */
    public function supplier(Request $request, string $supplier): JsonResponse
    {
        $model = Supplier::query()->where('code', $supplier)->first();

        if ($model === null) {
            return response()->json(['received' => false], 404);
        }

        $model->syncLogs()->create([
            'type' => 'webhook',
            'status' => $model->supports('webhooks') ? 'success' : 'partial',
            'mode' => $model->mode,
            'context' => ['headers' => ['content-type' => $request->header('content-type')]],
            'error' => $model->supports('webhooks')
                ? null
                : 'Webhook received but supplier webhook support is not verified for this account; the payload was recorded and not acted on.',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        Log::channel('supplier')->info('Supplier webhook received.', ['supplier' => $supplier]);

        return response()->json(['received' => true]);
    }
}
