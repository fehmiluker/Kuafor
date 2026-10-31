<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WebhookIngestor;
use App\Services\WhatsApp\WebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MetaWhatsAppWebhookController extends Controller
{
    /** GET: Meta subscription challenge. */
    public function verify(Request $request, WebhookVerifier $verifier): Response
    {
        $challenge = $verifier->verifyChallenge($request->query('hub_mode'), $request->query('hub_verify_token'), $request->query('hub_challenge'));

        return $challenge !== null ? response($challenge, 200)->header('Content-Type', 'text/plain') : response('Forbidden', 403);
    }

    /** POST: verify HMAC on the RAW body, store/dedupe, queue, answer 200 fast. */
    public function receive(Request $request, WebhookVerifier $verifier, WebhookIngestor $ingestor): Response
    {
        $raw = $request->getContent();
        if (! $verifier->verifySignature($raw, $request->header('X-Hub-Signature-256'))) {
            Log::warning('whatsapp.webhook_bad_signature', ['ip_hash' => hash('sha256', (string) $request->ip())]);

            return response('Invalid signature', 401);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload) || ($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response('Ignored', 200);
        }
        $ingestor->ingest($payload);

        return response('EVENT_RECEIVED', 200);
    }
}
