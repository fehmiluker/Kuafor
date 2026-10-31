<?php

namespace App\Services\Reviews;

use App\Enums\AppointmentStatus;
use App\Enums\ReviewStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Appointment;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\ReviewReport;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Verified reviews only: one review per COMPLETED appointment, written by the
 * verified account owning that booking or via the appointment's review-scoped
 * guest token. Businesses reply once (editable) and can report, never delete.
 */
final class ReviewService
{
    public const EDIT_WINDOW_DAYS = 7;

    public function __construct(private readonly EntitlementService $entitlements) {}

    /** Whether $user may review $appointment (account path). */
    public function userOwnsAppointment(User $user, Appointment $a): bool
    {
        if (! $user->phone_verified_at) {
            return false;
        }
        $customer = TenantCustomer::withoutGlobalScopes()->withTrashed()->find($a->customer_id);

        return $customer && (($customer->user_id === $user->id) || ($customer->phone_e164 === $user->phone_e164));
    }

    public function create(Appointment $a, int $rating, ?int $employeeRating, ?string $body): Review
    {
        if ($a->status !== AppointmentStatus::Completed) {
            throw new DomainRuleException('Yalnızca tamamlanan randevular değerlendirilebilir.', 'not_completed');
        }
        $tenant = Tenant::findOrFail($a->tenant_id);
        if (! $this->entitlements->hasFeature($tenant, 'reviews')) {
            throw new DomainRuleException('Bu işletme değerlendirme kabul etmiyor.', 'feature_unavailable');
        }
        if ($rating < 1 || $rating > 5 || ($employeeRating !== null && ($employeeRating < 1 || $employeeRating > 5))) {
            throw new DomainRuleException('Puan 1 ile 5 arasında olmalıdır.', 'invalid_rating');
        }
        // Spam guard: max 5 reviews per customer phone per day across the platform.
        $customer = TenantCustomer::withoutGlobalScopes()->withTrashed()->findOrFail($a->customer_id);
        $key = 'review:'.sha1($customer->phone_e164);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new DomainRuleException('Çok fazla değerlendirme gönderdiniz, lütfen daha sonra tekrar deneyin.', 'rate_limited');
        }
        try {
            $review = DB::transaction(function () use ($a, $rating, $employeeRating, $body) {
                $review = new Review;
                $review->forceFill([
                    'tenant_id' => $a->tenant_id, 'branch_id' => $a->branch_id, 'appointment_id' => $a->id,
                    'customer_id' => $a->customer_id, 'employee_id' => $employeeRating !== null ? $a->employee_id : null,
                    'rating' => $rating, 'employee_rating' => $employeeRating, 'body' => $body !== null ? trim(strip_tags($body)) : null,
                    'status' => ReviewStatus::Published, 'verified_at' => now(),
                ])->save();

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainRuleException('Bu randevu için zaten değerlendirme yaptınız.', 'already_reviewed');
        }
        RateLimiter::hit($key, 86400);
        $this->recalculate($a->tenant_id);

        return $review;
    }

    public function update(Review $review, int $rating, ?string $body): Review
    {
        if ($review->created_at->lt(now()->subDays(self::EDIT_WINDOW_DAYS)) || $review->status !== ReviewStatus::Published) {
            throw new DomainRuleException('Bu değerlendirme artık düzenlenemez.', 'edit_window_closed');
        }
        if ($rating < 1 || $rating > 5) {
            throw new DomainRuleException('Puan 1 ile 5 arasında olmalıdır.', 'invalid_rating');
        }
        $review->forceFill(['rating' => $rating, 'body' => $body !== null ? trim(strip_tags($body)) : null])->save();
        $this->recalculate($review->tenant_id);

        return $review;
    }

    /** One reply per review; later calls edit it. */
    public function reply(Review $review, User $author, string $body): ReviewReply
    {
        $reply = ReviewReply::withoutGlobalScopes()->firstOrNew(['review_id' => $review->id], ['tenant_id' => $review->tenant_id]);
        $reply->forceFill(['tenant_id' => $review->tenant_id, 'author_user_id' => $author->id, 'body' => trim(strip_tags($body))])->save();

        return $reply;
    }

    public function report(Review $review, ?User $reporter, string $reporterType, string $reason, bool $appeal = false): ReviewReport
    {
        try {
            return ReviewReport::create([
                'review_id' => $review->id, 'reporter_user_id' => $reporter?->id, 'reporter_type' => $reporterType,
                'reason' => trim(strip_tags($reason)), 'status' => 'open', 'is_appeal' => $appeal,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DomainRuleException('Bu değerlendirmeyi zaten bildirdiniz.', 'already_reported');
        }
    }

    /** Average/count from VISIBLE (published) verified reviews only. */
    public function recalculate(int $tenantId): void
    {
        $row = DB::table('reviews')->where('tenant_id', $tenantId)->where('status', ReviewStatus::Published->value)->whereNotNull('verified_at')
            ->selectRaw('COUNT(*) c, AVG(rating) a')->first();
        DB::table('tenants')->where('id', $tenantId)->update([
            'rating_count' => (int) $row->c,
            'rating_avg' => $row->c > 0 ? round((float) $row->a, 2) : null,
        ]);
    }
}
