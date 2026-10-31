<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Requests\Tenant\BranchRequest;
use App\Http\Resources\BranchResource;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Services\Catalog\TenantCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends TenantApiController
{
    public function __construct(private readonly TenantCatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Branch::class);
        $q = $this->scope()->scopeBranches(Branch::query()->with(['city', 'district', 'workingHours']), $request->user(), $this->tenant(), 'id');

        return ApiResponse::ok(BranchResource::collection($q->orderBy('name')->paginate(50)));
    }

    public function store(BranchRequest $request): JsonResponse
    {
        $this->authorize('create', Branch::class);

        return ApiResponse::created(new BranchResource($this->catalog->saveBranch(null, $request->validated())->load(['city', 'district', 'workingHours'])));
    }

    public function show(Branch $branch): JsonResponse
    {
        $this->authorize('view', $branch);

        return ApiResponse::ok(new BranchResource($branch->load(['city', 'district', 'workingHours'])));
    }

    public function update(BranchRequest $request, Branch $branch): JsonResponse
    {
        $this->authorize('update', $branch);

        return ApiResponse::ok(new BranchResource($this->catalog->saveBranch($branch, $request->validated())->load(['city', 'district', 'workingHours'])));
    }

    public function destroy(Branch $branch): JsonResponse
    {
        $this->authorize('delete', $branch);
        $this->catalog->deleteBranch($branch);

        return ApiResponse::ok(['deleted' => true]);
    }
}
