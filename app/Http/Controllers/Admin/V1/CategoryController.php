<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\API\ApiResponse;
use App\Http\Requests\Admin\V1\CategoryRequest;
use App\Http\Resources\Admin\V1\CategoryResource;

class CategoryController extends Controller
{
    public function __construct(private ApiResponse $apiResponse) {}

    public function index()
    {
        $categories = Category::paginate(20);
        // return CategoryResource::collection($categories);
        // return $this->apiResponse->ok(CategoryResource::collection($categories));
        return $this->apiResponse->paginated($categories, CategoryResource::class);
    }

    public function store(CategoryRequest $request)
    {
        $validated = $request->validated();
        $validated['order'] = Category::computeOrder($validated);

        $category = Category::create($validated);
        return $this->apiResponse->created(new CategoryResource($category->refresh()));
    }

    public function show(Category $category)
    {
        return $this->apiResponse->ok(new CategoryResource($category));
    }

    public function update(CategoryRequest $request, Category $category)
    {
        $category->update($request->validated());
        return $this->apiResponse->ok(new CategoryResource($category->refresh()));
    }

    public function destroy(Category $category)
    {
        $category->delete();
        return $this->apiResponse->noContent();
    }
}
