<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Support\API\ApiResponse;
use App\Http\Requests\Admin\V1\BrandRequest;
use App\Http\Resources\Admin\V1\BrandResource;
class BrandController extends Controller
{

    public function __construct(private ApiResponse $apiResponse) {}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $brands = Brand::paginate(20);
        return $this->apiResponse->paginated($brands, BrandResource::class);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(BrandRequest $request)
    {
        $validated = $request->validated();
        $validated['order'] = Brand::computeOrder($validated);

        $brand = Brand::create($validated);
        return $this->apiResponse->created(new BrandResource($brand->refresh()));
    }

    /**
     * Display the specified resource.
     */
    public function show(Brand $brand)
    {
        return $this->apiResponse->ok(new BrandResource($brand));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BrandRequest $request, Brand $brand)
    {
        $brand->update($request->validated());
        return $this->apiResponse->ok(new BrandResource($brand->refresh()));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Brand $brand)
    {
        $brand->delete();
        return $this->apiResponse->noContent();
    }
}
