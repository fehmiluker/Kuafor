<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\ReviewResource;
use App\Http\Resources\SalonResource;
use App\Http\Resources\ServiceResource;
use App\Http\Responses\ApiResponse;
use App\Models\City;
use App\Models\District;
use App\Models\Review;
use App\Models\ServiceCategory;
use App\Services\Booking\AvailabilityService;
use App\Services\Catalog\PublicCatalog;
use App\Services\Search\SalonSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(private readonly PublicCatalog $catalog) {}

    public function cities(): JsonResponse
    {
        return ApiResponse::ok(City::orderBy('name')->get(['id', 'name', 'slug', 'plate_code']));
    }

    public function districts(Request $request): JsonResponse
    {
        $data = $request->validate(['city_id' => ['required', 'integer', 'exists:cities,id']]);

        return ApiResponse::ok(District::where('city_id', $data['city_id'])->orderBy('name')->get(['id', 'city_id', 'name', 'slug']));
    }

    public function categories(): JsonResponse
    {
        return ApiResponse::ok(ServiceCategory::whereNull('tenant_id')->where('status', 'active')->orderBy('sort_order')->get(['id', 'name', 'slug']));
    }

    public function salons(Request $request, SalonSearchService $search): JsonResponse
    {
        $f = $request->validate([
            'city' => ['nullable', 'string', 'max:80'], 'district' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:100'], 'service' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'], 'time' => ['nullable', 'date_format:H:i'],
            'min_price' => ['nullable', 'numeric', 'min:0'], 'max_price' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', 'in:'.implode(',', SalonSearchService::SORTS)],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        return ApiResponse::ok(SalonResource::collection($search->search($f, (int) ($f['per_page'] ?? 12))));
    }

    public function salon(string $slug): JsonResponse
    {
        $tenant = $this->catalog->bookableTenant($slug) ?? abort(404);
        $tenant->setRelation('branches', $this->catalog->branches($tenant));

        return ApiResponse::ok(new SalonResource($tenant));
    }

    public function branches(string $slug): JsonResponse
    {
        $tenant = $this->catalog->bookableTenant($slug) ?? abort(404);

        return ApiResponse::ok(BranchResource::collection($this->catalog->branches($tenant)));
    }

    public function services(int $branch): JsonResponse
    {
        $b = $this->catalog->bookableBranch($branch) ?? abort(404);

        return ApiResponse::ok(ServiceResource::collection($this->catalog->services($b)));
    }

    public function employees(Request $request, int $branch): JsonResponse
    {
        $b = $this->catalog->bookableBranch($branch) ?? abort(404);
        $data = $request->validate(['service_id' => ['nullable', 'integer']]);

        return ApiResponse::ok(EmployeeResource::collection($this->catalog->employees($b, isset($data['service_id']) ? [(int) $data['service_id']] : [])));
    }

    public function availability(Request $request, AvailabilityService $availability): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'service_ids' => ['required', 'array', 'min:1', 'max:'.config('booking.max_services_per_appointment')],
            'service_ids.*' => ['integer'],
            'employee_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $branch = $this->catalog->bookableBranch((int) $data['branch_id']) ?? abort(404);
        $slots = $availability->slotsForDay($branch, $data['service_ids'], $data['employee_id'] ?? null, $data['date']);

        return ApiResponse::ok(array_map(fn ($s) => [
            'starts_at' => $s['start']->toIso8601ZuluString(),
            'ends_at' => $s['end']->toIso8601ZuluString(),
            'time' => $s['start']->setTimezone($branch->timezone)->format('H:i'),
            'employee_ids' => $s['employee_ids'],
        ], $slots), ['date' => $data['date'], 'timezone' => $branch->timezone]);
    }

    public function reviews(string $slug): JsonResponse
    {
        $tenant = $this->catalog->bookableTenant($slug) ?? abort(404);
        $reviews = Review::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', ReviewStatus::Published->value)
            ->whereNotNull('verified_at')
            ->with(['customer' => fn ($q) => $q->withoutGlobalScopes(), 'reply' => fn ($q) => $q->withoutGlobalScopes(), 'employee' => fn ($q) => $q->withoutGlobalScopes()])
            ->latest()->paginate(10);

        return ApiResponse::ok(ReviewResource::collection($reviews), ['average' => $tenant->rating_avg !== null ? (float) $tenant->rating_avg : null, 'count' => $tenant->rating_count]);
    }
}
