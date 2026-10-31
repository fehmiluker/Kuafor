<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Requests\Tenant\ServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Service;
use App\Services\Catalog\TenantCatalogService;
use Illuminate\Http\JsonResponse;

class ServiceController extends TenantApiController
{
    public function __construct(private readonly TenantCatalogService $catalog) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Service::class);

        return ApiResponse::ok(ServiceResource::collection(Service::with(['category', 'branchServices'])->orderBy('name')->paginate(100)));
    }

    public function store(ServiceRequest $request): JsonResponse
    {
        $this->authorize('create', Service::class);

        return ApiResponse::created(new ServiceResource($this->catalog->saveService(null, $request->validated())->load(['category', 'branchServices'])));
    }

    public function show(Service $service): JsonResponse
    {
        $this->authorize('view', $service);

        return ApiResponse::ok(new ServiceResource($service->load(['category', 'branchServices'])));
    }

    public function update(ServiceRequest $request, Service $service): JsonResponse
    {
        $this->authorize('update', $service);

        return ApiResponse::ok(new ServiceResource($this->catalog->saveService($service, $request->validated())->load(['category', 'branchServices'])));
    }

    public function destroy(Service $service): JsonResponse
    {
        $this->authorize('delete', $service);
        $this->catalog->deleteService($service);

        return ApiResponse::ok(['deleted' => true]);
    }
}
