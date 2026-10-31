<?php

namespace App\Services\WhatsApp;

use App\DTOs\WhatsApp\HealthResult;
use App\DTOs\WhatsApp\SendResult;
use App\Models\WhatsAppConnection;

/**
 * Official WhatsApp Business Platform (Cloud API) operations. Every call is
 * made with the branch's OWN connection (phone_number_id + token), so messages
 * always come from that branch's number.
 */
interface WhatsAppClientInterface
{
    /** Pre-approved template (required outside the 24h customer-service window). */
    public function sendTemplate(WhatsAppConnection $connection, string $toE164, string $templateName, string $language, array $components = []): SendResult;

    /** Interactive button/list message (only inside the 24h window). */
    public function sendInteractive(WhatsAppConnection $connection, string $toE164, array $interactive): SendResult;

    /** Free-form text (only inside the 24h window). */
    public function sendText(WhatsAppConnection $connection, string $toE164, string $body): SendResult;

    public function health(WhatsAppConnection $connection): HealthResult;

    /** Subscribe our app to the WABA's webhooks. */
    public function subscribeWebhooks(WhatsAppConnection $connection): bool;
}
