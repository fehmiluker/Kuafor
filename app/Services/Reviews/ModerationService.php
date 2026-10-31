<?php

namespace App\Services\Reviews;

use App\Enums\ReviewStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Review;
use App\Models\ReviewModerationLog;
use App\Models\ReviewReport;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Platform moderation: reasoned hide/publish decisions with a full audit trail. */
final class ModerationService
{
    public function __construct(private readonly ReviewService $reviews, private readonly AuditLogger $audit) {}

    public function moderate(Review $review, User $moderator, ReviewStatus $status, string $reason, ?ReviewReport $report = null): Review
    {
        if (trim($reason) === '') {
            throw new DomainRuleException('Moderasyon kararı için gerekçe zorunludur.', 'reason_required');
        }
        DB::transaction(function () use ($review, $moderator, $status, $reason, $report) {
            $old = $review->status;
            $review->forceFill(['status' => $status])->save();
            ReviewModerationLog::create([
                'review_id' => $review->id, 'actor_id' => $moderator->id, 'old_status' => $old->value,
                'new_status' => $status->value, 'reason' => $reason,
            ]);
            $openReports = ReviewReport::where('review_id', $review->id)->where('status', 'open')
                ->when($report, fn ($q) => $q->whereKey($report->id));
            $openReports->update([
                'status' => $status === ReviewStatus::Hidden ? 'upheld' : 'dismissed',
                'resolved_by' => $moderator->id, 'resolved_at' => now(), 'decision_reason' => $reason,
            ]);
            $this->audit->log('review.moderated', $review, ['status' => $old->value], ['status' => $status->value], $reason, tenantId: $review->tenant_id);
        });
        $this->reviews->recalculate($review->tenant_id);

        return $review->refresh();
    }
}
