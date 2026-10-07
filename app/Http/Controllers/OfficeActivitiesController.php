<?php

namespace App\Http\Controllers;

use App\Models\OfficeActivities;
use App\Models\OfficeActivitiesImages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfficeActivitiesController extends Controller
{
    public function index(Request $request)
    {
        try {
            $activities = OfficeActivities::query()
                ->select('id', 'title', 'description', 'activity_type', 'activity_date', 'image')
                ->orderBy('activity_date', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => $activities->isEmpty()
                    ? 'No office activities are available right now.'
                    : 'Office activities loaded successfully.',
                'data' => $activities,
            ], 200);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Office activities are temporarily unavailable. Please try again later.',
                'data' => [],
            ], 500);
        }
    }

    /**
     * Store a new office activity.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'activity_type' => 'required|string|max:100',
            'activity_date' => 'required|date',
            'image' => 'required|string',
            'gallery_images' => 'nullable|array',
            'gallery_images.*' => 'string',
        ]);

        DB::beginTransaction();

        try {
            $activity = OfficeActivities::create([
                'title' => $validated['title'],
                'description' => $validated['description'],
                'activity_type' => $validated['activity_type'],
                'activity_date' => $validated['activity_date'],
                'image' => $validated['image'],
            ]);

            if (!empty($validated['gallery_images'])) {
                foreach ($validated['gallery_images'] as $image) {
                    OfficeActivitiesImages::create([
                        'office_activity_id' => $activity->id,
                        'image' => $image,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Office activity created successfully.',
                'data' => $activity->load('images'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create office activity.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display one activity with all images.
     */
    public function show($id)
    {
        $activity = OfficeActivities::with('images')->find($id);

        if (!$activity) {
            return response()->json([
                'success' => false,
                'message' => 'Office activity not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $activity,
        ]);
    }

    /**
     * Update an office activity.
     */
    public function update(Request $request, $id)
    {
        $activity = OfficeActivities::find($id);

        if (!$activity) {
            return response()->json([
                'success' => false,
                'message' => 'Office activity not found.',
            ], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'activity_type' => 'required|string|max:100',
            'activity_date' => 'required|date',
            'image' => 'nullable|string',

            'gallery_images' => 'nullable|array',
            'gallery_images.*' => 'string',

            'delete_image_ids' => 'nullable|array',
            'delete_image_ids.*' => 'integer',
        ]);

        DB::beginTransaction();

        try {

            /*
             * Update main activity information
             */
            $activity->title = $validated['title'];
            $activity->description = $validated['description'];
            $activity->activity_type = $validated['activity_type'];
            $activity->activity_date = $validated['activity_date'];

            /*
             * Update cover image only if a new one was provided.
             */
            if (!empty($validated['image'])) {
                $activity->image = $validated['image'];
            }

            $activity->save();

            /*
             * Delete selected gallery images.
             */
            if (!empty($validated['delete_image_ids'])) {

                OfficeActivitiesImages::where(
                    'office_activity_id',
                    $activity->id
                )
                ->whereIn(
                    'id',
                    $validated['delete_image_ids']
                )
                ->delete();
            }

            /*
             * Add new gallery images.
             */
            if (!empty($validated['gallery_images'])) {

                foreach ($validated['gallery_images'] as $image) {

                    OfficeActivitiesImages::create([
                        'office_activity_id' => $activity->id,
                        'image' => $image,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Office activity updated successfully.',
                'data' => $activity->fresh()->load('images'),
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update office activity.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an office activity.
     */
    public function destroy($id)
    {
        $activity = OfficeActivities::find($id);

        if (!$activity) {
            return response()->json([
                'success' => false,
                'message' => 'Office activity not found.',
            ], 404);
        }

        try {

            /*
             * Because the migration uses
             * onDelete('cascade'), the gallery images
             * will also be deleted.
             */
            $activity->delete();

            return response()->json([
                'success' => true,
                'message' => 'Office activity deleted successfully.',
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete office activity.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a single gallery image.
     */
    public function destroyImage($id)
    {
        $image = OfficeActivitiesImages::find($id);

        if (!$image) {
            return response()->json([
                'success' => false,
                'message' => 'Activity image not found.',
            ], 404);
        }

        $image->delete();

        return response()->json([
            'success' => true,
            'message' => 'Activity image deleted successfully.',
        ]);
    }
}
