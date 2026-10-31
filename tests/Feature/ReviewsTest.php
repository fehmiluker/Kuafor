<?php

namespace Tests\Feature;

use App\DTOs\Appointments\Actor;
use App\Enums\ReviewStatus;
use App\Exceptions\DomainRuleException;
use App\Jobs\SendReviewInvite;
use App\Mail\AppointmentNotificationMail;
use App\Models\Review;
use App\Models\User;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\GuestTokenService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Reviews\ModerationService;
use App\Services\Reviews\ReviewService;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ActsInTenant;
use Tests\Support\BuildsSalon;
use Tests\TestCase;

class ReviewsTest extends TestCase
{
    use ActsInTenant, BuildsSalon;

    private function completed(string $time = '10:00', string $phone = '+905551112233')
    {
        $a = $this->book($this->at($time), $this->ayse->id, phone: $phone)['appointment'];
        $this->travelTo($this->at($time)->addMinutes(45));

        return app(AppointmentService::class)->complete($a, Actor::system('t'));
    }

    public function test_only_completed_appointments_can_be_reviewed_once(): void
    {
        $this->buildSalon('automatic');
        $pending = $this->book($this->at('15:00'), $this->ayse->id, phone: '+905550001234')['appointment'];
        try {
            app(ReviewService::class)->create($pending, 5, null, 'Sahte');
            $this->fail('reviewed a non-completed appointment');
        } catch (DomainRuleException $e) {
            $this->assertSame('not_completed', $e->errorCode);
        }
        $a = $this->completed();
        app(ReviewService::class)->create($a, 4, 5, 'Güzel');
        $this->expectExceptionMessage('zaten değerlendirme');
        app(ReviewService::class)->create($a, 1, null, 'Spam');
    }

    public function test_guest_review_via_token_link(): void
    {
        $this->buildSalon('automatic');
        $a = $this->completed();
        $token = app(GuestTokenService::class)->issue($a, [GuestTokenService::SCOPE_REVIEW]);
        $this->get(route('guest.review', ['reference' => $a->public_reference, 'token' => $token]))->assertOk()->assertSee('değerlendirin');
        $this->post(route('guest.review.store', $a->public_reference), ['token' => $token, 'rating' => 5, 'body' => 'Mükemmel'])->assertRedirect();
        $this->assertSame(5.0, (float) $this->tenant->fresh()->rating_avg);
        // Token without review scope is refused.
        $viewOnly = app(GuestTokenService::class)->issue($a, [GuestTokenService::SCOPE_VIEW]);
        $this->get(route('guest.review', ['reference' => $a->public_reference, 'token' => $viewOnly]))->assertNotFound();
    }

    public function test_account_review_requires_verified_ownership(): void
    {
        $this->buildSalon('automatic');
        $a = $this->completed();
        $stranger = User::factory()->create(['phone_e164' => '+905559990000']);
        $this->api($stranger)->postJson("/api/v1/appointments/{$a->id}/reviews", ['rating' => 1])->assertNotFound();
        $unverifiedOwner = User::factory()->create(['phone_e164' => '+905551112233', 'phone_verified_at' => null]);
        $this->api($unverifiedOwner)->postJson("/api/v1/appointments/{$a->id}/reviews", ['rating' => 5])->assertNotFound();
        $unverifiedOwner->forceFill(['phone_verified_at' => now()])->save(); // after OTP verification
        $this->app['auth']->forgetGuards();
        $this->api($unverifiedOwner->fresh())->postJson("/api/v1/appointments/{$a->id}/reviews", ['rating' => 5, 'body' => 'Teşekkürler'])->assertCreated()->assertJsonPath('data.verified', true);
    }

    public function test_average_counts_only_published_and_moderation_is_logged(): void
    {
        $this->buildSalon('automatic');
        $r1 = app(ReviewService::class)->create($this->completed('10:00', '+905550000001'), 5, null, 'İyi');
        app(ReviewService::class)->create($this->completed('12:00', '+905550000002'), 1, null, 'Hakaret içeren yorum');
        $this->assertSame(3.0, (float) $this->tenant->fresh()->rating_avg);
        $bad = Review::withoutGlobalScopes()->where('rating', 1)->first();
        $report = app(ReviewService::class)->report($bad, $this->owner($this->tenant), 'tenant', 'Hakaret içeriyor, kurallara aykırı');
        app(ModerationService::class)->moderate($bad, $this->superAdmin(), ReviewStatus::Hidden, 'Hakaret — kural 3.2', $report);
        $this->assertSame(5.0, (float) $this->tenant->fresh()->rating_avg);
        $this->assertSame(1, $this->tenant->fresh()->rating_count);
        $this->assertDatabaseHas('review_moderation_logs', ['review_id' => $bad->id, 'new_status' => 'hidden', 'reason' => 'Hakaret — kural 3.2']);
        $this->assertDatabaseHas('review_reports', ['id' => $report->id, 'status' => 'upheld']);
        $this->getJson('/api/v1/salons/'.$this->tenant->slug.'/reviews')->assertJsonCount(1, 'data')->assertJsonPath('data.0.author', 'Zeynep K.');
        $this->assertNotNull($r1);
    }

    public function test_tenant_can_reply_once_and_never_delete(): void
    {
        $this->buildSalon('automatic');
        $review = app(ReviewService::class)->create($this->completed(), 3, null, 'Ortalama');
        $owner = $this->owner($this->tenant);
        $this->api($owner, $this->tenant)->postJson("/api/v1/tenant/reviews/{$review->id}/reply", ['body' => 'Teşekkürler'])->assertOk();
        $this->api($owner, $this->tenant)->postJson("/api/v1/tenant/reviews/{$review->id}/reply", ['body' => 'Güncellenmiş yanıt'])->assertOk()->assertJsonPath('data.reply.body', 'Güncellenmiş yanıt');
        $this->assertDatabaseCount('review_replies', 1);
        $this->assertFalse($owner->can('delete', $review));
        $this->assertContains($this->api($owner, $this->tenant)->deleteJson("/api/v1/tenant/reviews/{$review->id}")->status(), [404, 405], 'no delete endpoint exists');
        $this->api($owner, $this->tenant)->postJson("/api/v1/tenant/reviews/{$review->id}/report", ['reason' => 'kısa'])->assertStatus(422);
    }

    public function test_review_invite_is_sent_after_completion(): void
    {
        Mail::fake();
        $this->buildSalon('automatic');
        $a = $this->book($this->at('10:00'), $this->ayse->id, extra: ['customerEmail' => 'z@example.com'])['appointment'];
        $this->travelTo($this->at('10:45'));
        app(AppointmentService::class)->complete($a, Actor::system('t'));
        (new SendReviewInvite($a->id))->handle(app(NotificationDispatcher::class));
        (new SendReviewInvite($a->id))->handle(app(NotificationDispatcher::class));
        Mail::assertQueued(AppointmentNotificationMail::class, fn ($m) => str_contains($m->body, 'değerlendir'));
        $this->assertSame(1, \DB::table('notification_deliveries')->where('dedupe_key', 'email:mail:review_invite:'.$a->id)->count());
    }
}
