<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\XeroConnection;
use App\Services\XeroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Xero change notifications. Xero signs each payload with the webhook
 * signing key using HMAC-SHA256 and sends it base64-encoded in the
 * `x-xero-signature` header. This route is referenced from routes/api.php as a
 * public (no Sanctum auth) endpoint; verification is by signature only.
 */
class XeroWebhookController extends Controller
{
    public function __construct(private readonly XeroService $xero) {}

    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        if (! $this->verifySignature($rawBody, (string) $request->header('x-xero-signature'))) {
            Log::warning('Xero webhook signature verification failed');

            return response()->json(['success' => false], 401);
        }

        $tenantIds = collect($request->input('events', []))
            ->pluck('tenantId')
            ->filter(fn (mixed $tenantId): bool => is_string($tenantId) && $tenantId !== '')
            ->unique()
            ->values();

        $connections = XeroConnection::query()
            ->where('status', 'active')
            ->whereIn('tenant_id', $tenantIds->all())
            ->get();

        foreach ($connections as $connection) {
            $this->xero->sync($connection);
        }

        Log::info('Xero webhook received', [
            'tenants' => $tenantIds->all(),
            'connections_synced' => $connections->count(),
        ]);

        return response()->json(['success' => true, 'connections_synced' => $connections->count()]);
    }

    private function verifySignature(string $rawBody, string $signature): bool
    {
        $key = config('services.xero.webhook_key');

        if (empty($key) || $signature === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, (string) $key, true));

        return hash_equals($expected, $signature);
    }
}