<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Requests\Tenant\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Services\Catalog\TenantCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends TenantApiController
{
    public function __construct(private readonly TenantCatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);
        $ids = $this->branchIds($request);
        $q = Employee::query()->with(['branches', 'services'])
            ->when($ids !== null, fn ($q) => $q->whereHas('branches', fn ($b) => $b->whereIn('branches.id', $ids ?: [0])));

        return ApiResponse::ok(EmployeeResource::collection($q->orderBy('display_name')->paginate(100)));
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);
        $this->assertBranchesAllowed($request, $request->input('branch_ids', []));

        return ApiResponse::created(new EmployeeResource($this->catalog->saveEmployee(null, $request->validated())->load(['branches', 'services'])));
    }

    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        return ApiResponse::ok(new EmployeeResource($employee->load(['branches', 'services'])));
    }

    public function update(EmployeeRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);
        $this->assertBranchesAllowed($request, $request->input('branch_ids', []));

        return ApiResponse::ok(new EmployeeResource($this->catalog->saveEmployee($employee, $request->validated())->load(['branches', 'services'])));
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->authorize('delete', $employee);
        $this->catalog->deleteEmployee($employee);

        return ApiResponse::ok(['deleted' => true]);
    }

    /** Branch managers cannot assign staff to branches they do not manage. */
    private function assertBranchesAllowed(Request $request, array $branchIds): void
    {
        foreach ($branchIds as $id) {
            abort_unless($this->scope()->canAccessBranch($request->user(), $this->tenant(), (int) $id), 403);
        }
    }
}
