<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Resources\ReviewResource;
use App\Http\Responses\ApiResponse;
use App\Models\Review;
use App\Services\Reviews\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends TenantApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Review::class);
        $q = $this->scope()->scopeBranches(Review::query()->with(['customer', 'reply', 'employee']), $request->user(), $this->tenant())->latest();

        return ApiResponse::ok(ReviewResource::collection($q->paginate(25)));
    }

    public function reply(Request $request, Review $review, ReviewService $reviews): JsonResponse
    {
        $this->authorize('reply', $review);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']]);
        $reviews->reply($review, $request->user(), $data['body']);

        return ApiResponse::ok(new ReviewResource($review->load(['customer', 'reply', 'employee'])));
    }

    public function report(Request $request, Review $review, ReviewService $reviews): JsonResponse
    {
        $this->authorize('report', $review);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $report = $reviews->report($review, $request->user(), 'tenant', $data['reason']);

        return ApiResponse::created(['report_id' => $report->id, 'status' => $report->status]);
    }
}
