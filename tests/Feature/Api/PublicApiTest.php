<?php

namespace Tests\Feature\Api;

use App\DTOs\Appointments\Actor;
use App\Models\Appointment;
use App\Models\GuestAccessToken;
use App\Services\Booking\AppointmentService;
use App\Services\Sms\SmsProviderInterface;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    use BuildsSalon;

    private function payload(array $over = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id, 'service_ids' => [$this->cut->id], 'employee_id' => $this->ayse->id,
            'date' => '2026-11-02', 'time' => '10:00', 'full_name' => 'Zeynep Kaya', 'phone' => '0555 111 22 33',
            'kvkk_acknowledged' => true, 'whatsapp_opt_in' => true,
        ], $over);
    }

    public function test_catalog_endpoints_use_envelope(): void
    {
        $this->buildSalon();
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonStructure(['data', 'meta', 'errors']);
        $this->getJson('/api/v1/salons/'.$this->tenant->slug)->assertOk()->assertJsonPath('data.slug', $this->tenant->slug);
        $this->getJson('/api/v1/salons/'.$this->tenant->slug.'/branches')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/branches/{$this->branch->id}/services")->assertOk()->assertJsonPath('data.0.price.amount', '300.00');
        $this->getJson("/api/v1/branches/{$this->branch->id}/employees?service_id={$this->color->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/salons/nope')->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    }

    public function test_availability_endpoint(): void
    {
        $this->buildSalon();
        $r = $this->getJson("/api/v1/availability?branch_id={$this->branch->id}&service_ids[]={$this->cut->id}&date=2026-11-02")->assertOk();
        $this->assertSame('09:00', $r->json('data.0.time'));
        $this->assertSame('Europe/Istanbul', $r->json('meta.timezone'));
    }

    public function test_guest_booking_without_payment_and_manage_via_token(): void
    {
        $this->buildSalon('automatic');
        $r = $this->postJson('/api/v1/appointments', $this->payload())->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('meta.online_payment_required', false);
        $ref = $r->json('data.reference');
        $token = $r->json('meta.guest_token');
        $this->assertSame('+905551112233', Appointment::withoutGlobalScopes()->first()->customer()->withoutGlobalScopes()->first()->phone_e164);
        $this->assertDatabaseHas('communication_consents', ['purpose' => 'transactional', 'channel' => 'whatsapp', 'status' => 'granted']);
        $this->assertDatabaseMissing('guest_access_tokens', ['token_hash' => $token]); // only the hash is stored

        $this->getJson("/api/v1/guest/appointments/{$ref}", ['X-Guest-Token' => $token])->assertOk()->assertJsonPath('data.reference', $ref);
        $this->getJson("/api/v1/guest/appointments/{$ref}", ['X-Guest-Token' => str_repeat('x', 48)])->assertNotFound();
        $this->getJson("/api/v1/guest/appointments/{$ref}")->assertNotFound();
        $this->postJson("/api/v1/guest/appointments/{$ref}/cancel", ['reason' => 'Gelemiyorum'], ['X-Guest-Token' => $token])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_guest_token_expiry_and_reference_binding(): void
    {
        $this->buildSalon('automatic');
        $r = $this->postJson('/api/v1/appointments', $this->payload());
        $other = $this->postJson('/api/v1/appointments', $this->payload(['time' => '12:00', 'phone' => '05559998877']));
        // A valid token for appointment A does not open appointment B.
        $this->getJson('/api/v1/guest/appointments/'.$other->json('data.reference'), ['X-Guest-Token' => $r->json('meta.guest_token')])->assertNotFound();
        GuestAccessToken::withoutGlobalScopes()->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/guest/appointments/'.$r->json('data.reference'), ['X-Guest-Token' => $r->json('meta.guest_token')])->assertNotFound();
    }

    public function test_validation_kvkk_honeypot_and_phone(): void
    {
        $this->buildSalon();
        $this->postJson('/api/v1/appointments', $this->payload(['kvkk_acknowledged' => false]))->assertStatus(422)->assertJsonPath('errors.0.field', 'kvkk_acknowledged');
        $this->postJson('/api/v1/appointments', $this->payload(['website' => 'http://spam']))->assertStatus(422);
        $this->postJson('/api/v1/appointments', $this->payload(['phone' => '123']))->assertStatus(422);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_slot_conflict_returns_409(): void
    {
        $this->buildSalon('automatic');
        $this->postJson('/api/v1/appointments', $this->payload())->assertCreated();
        $this->postJson('/api/v1/appointments', $this->payload(['phone' => '05559998877']))->assertStatus(409)->assertJsonPath('errors.0.code', 'slot_unavailable');
    }

    public function test_idempotency_key_header_replays_response(): void
    {
        $this->buildSalon('automatic');
        $first = $this->postJson('/api/v1/appointments', $this->payload(), ['Idempotency-Key' => 'booking-key-001'])->assertCreated();
        $second = $this->postJson('/api/v1/appointments', $this->payload(), ['Idempotency-Key' => 'booking-key-001'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->postJson('/api/v1/appointments', $this->payload(['time' => '15:00']), ['Idempotency-Key' => 'booking-key-001'])->assertStatus(422)->assertJsonPath('errors.0.code', 'idempotency_mismatch');
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
    }

    public function test_proposal_accept_and_reject_routes(): void
    {
        $this->buildSalon('manual');
        $a = $this->book($this->at('10:00'), $this->ayse->id)['appointment'];
        $r = app(AppointmentService::class)->propose($a, $this->at('15:00'), null, Actor::system('test'));
        $this->postJson("/api/v1/guest/proposals/{$r['proposal']->id}/accept", [], ['X-Proposal-Token' => 'wrong'.str_repeat('x', 40)])->assertNotFound();
        $this->postJson("/api/v1/guest/proposals/{$r['proposal']->id}/accept", [], ['X-Proposal-Token' => $r['token']])->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->postJson("/api/v1/guest/proposals/{$r['proposal']->id}/reject", [], ['X-Proposal-Token' => $r['token']])->assertStatus(409);
    }

    public function test_phone_verification_required_setting(): void
    {
        $this->buildSalon('automatic');
        $this->builder->settings(['require_phone_verification' => true]);
        $this->postJson('/api/v1/appointments', $this->payload())->assertStatus(422)->assertJsonPath('errors.0.code', 'phone_verification_required');
        $this->postJson('/api/v1/booking/verify-phone', ['phone' => '05551112233'])->assertOk();
        $sms = app(SmsProviderInterface::class)->sent;
        preg_match('/(\d{6})/', end($sms)['message'], $m);
        $this->postJson('/api/v1/appointments', $this->payload(['verification_code' => $m[1]]))->assertCreated();
    }
}
