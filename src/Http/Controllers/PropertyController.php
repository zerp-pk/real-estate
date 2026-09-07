<?php

namespace Zerp\RealEstate\Http\Controllers;

use App\Models\User;
use App\Services\MediaAttachmentService;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Zerp\RealEstate\Http\Requests\StorePropertyRequest;
use Zerp\RealEstate\Http\Requests\UpdatePropertyRequest;
use Zerp\RealEstate\Models\Amenity;
use Zerp\RealEstate\Models\Property;
use Zerp\RealEstate\Models\PropertyImage;
use Zerp\RealEstate\Models\PropertyType;

class PropertyController extends Controller
{
    public function index()
    {
        if (!Auth::user()->can('manage-properties')) {
            return back()->with('error', __('Permission denied'));
        }

        $properties = Property::with('type:id,name')
            ->select('id', 'reference_no', 'title', 'property_type_id', 'purpose', 'status', 'price', 'currency', 'city', 'bedrooms', 'bathrooms', 'is_active', 'created_at')
            ->where(function ($q) {
                if (Auth::user()->can('manage-any-properties')) {
                    $q->where('created_by', creatorId());
                } elseif (Auth::user()->can('manage-own-properties')) {
                    $q->where('creator_id', Auth::id());
                } else {
                    $q->whereRaw('1 = 0');
                }
            })
            ->when(request('search'), fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%' . request('search') . '%')->orWhere('reference_no', 'like', '%' . request('search') . '%')))
            ->when(request('property_type_id'), fn ($q) => $q->where('property_type_id', request('property_type_id')))
            ->when(request('purpose'), fn ($q) => $q->where('purpose', request('purpose')))
            ->when(request('status'), fn ($q) => $q->where('status', request('status')))
            ->when(request('city'), fn ($q) => $q->where('city', 'like', '%' . request('city') . '%'))
            ->when(request('min_price'), fn ($q) => $q->where('price', '>=', request('min_price')))
            ->when(request('max_price'), fn ($q) => $q->where('price', '<=', request('max_price')))
            ->when(request('sort'), fn($q) => $q->sortSafe(request('sort'), request('direction'), 'created_at', 'desc'), fn ($q) => $q->latest())
            ->paginate(request('per_page', 12))
            ->withQueryString();

        return Inertia::render('RealEstate/Properties/Index', [
            'properties' => $properties,
            'types' => PropertyType::where('created_by', creatorId())->select('id', 'name')->get(),
            'filters' => request()->only(['search', 'property_type_id', 'purpose', 'status', 'city', 'min_price', 'max_price']),
        ]);
    }

    public function create()
    {
        if (!Auth::user()->can('create-properties')) {
            return back()->with('error', __('Permission denied'));
        }

        return Inertia::render('RealEstate/Properties/Create', $this->formOptions());
    }

    public function store(StorePropertyRequest $request)
    {
        if (!Auth::user()->can('create-properties')) {
            return redirect()->route('real-estate.properties.index')->with('error', __('Permission denied'));
        }

        $data = $request->validated();

        $property = new Property();
        $property->fill($data);
        $property->reference_no = Property::nextReferenceNo(creatorId());
        $property->is_active = $request->boolean('is_active', true);
        $property->is_featured = $request->boolean('is_featured', false);
        $property->creator_id = Auth::id();
        $property->created_by = creatorId();
        $property->save();

        $property->amenities()->sync($data['amenities'] ?? []);
        $this->syncImages($property, $data['images'] ?? []);

        return redirect()->route('real-estate.properties.index')->with('success', __('Property created successfully.'));
    }

    public function show(Property $property)
    {
        if (!Auth::user()->can('manage-properties') || $property->created_by != creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $property->load(['type', 'agent:id,name', 'amenities', 'images.media', 'viewings.agent:id,name']);

        return Inertia::render('RealEstate/Properties/Show', [
            'property' => $property,
        ]);
    }

    public function edit(Property $property)
    {
        if (!Auth::user()->can('edit-properties') || $property->created_by != creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $property->load(['amenities:id', 'images.media']);

        return Inertia::render('RealEstate/Properties/Edit', array_merge($this->formOptions(), [
            'property' => $property,
        ]));
    }

    public function update(UpdatePropertyRequest $request, Property $property)
    {
        if (!Auth::user()->can('edit-properties') || $property->created_by != creatorId()) {
            return redirect()->route('real-estate.properties.index')->with('error', __('Permission denied'));
        }

        $data = $request->validated();
        $property->fill($data);
        $property->is_active = $request->boolean('is_active', true);
        $property->is_featured = $request->boolean('is_featured', false);
        $property->save();

        $property->amenities()->sync($data['amenities'] ?? []);
        $this->syncImages($property, $data['images'] ?? []);

        return redirect()->route('real-estate.properties.index')->with('success', __('Property updated successfully.'));
    }

    public function destroy(Property $property)
    {
        if (!Auth::user()->can('delete-properties') || $property->created_by != creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $property->delete();

        return back()->with('success', __('Property deleted successfully.'));
    }

    private function formOptions(): array
    {
        return [
            'types' => PropertyType::where('created_by', creatorId())->select('id', 'name')->get(),
            'amenities' => Amenity::where('created_by', creatorId())->select('id', 'name')->get(),
            'agents' => User::where('created_by', creatorId())->select('id', 'name')->get(),
        ];
    }

    /**
     * Authoritative sync: the given list of media-library file paths becomes the
     * property's full image set. Reuses the app-wide MediaAttachmentService so
     * files land in the same media library as every other module.
     */
    private function syncImages(Property $property, array $imagePaths): void
    {
        $property->images()->delete();

        if (empty($imagePaths)) {
            return;
        }

        $directoryId = MediaAttachmentService::ensureDirectory('Property Images', creatorId(), Auth::id());

        foreach (array_values($imagePaths) as $index => $filePath) {
            $fileName = basename($filePath);
            $media = MediaAttachmentService::resolveOrBackfill(
                $fileName,
                Property::class,
                $property->id,
                'property_images',
                Auth::id(),
                creatorId(),
                $directoryId
            );

            PropertyImage::create([
                'property_id' => $property->id,
                'file_name' => $fileName,
                'file_path' => $fileName,
                'media_id' => $media?->id,
                'sort_order' => $index,
            ]);
        }
    }
}
