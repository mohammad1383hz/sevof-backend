<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebsiteEntityResource;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebsiteEntityController extends Controller
{
    public function show(Request $request, Site $site): JsonResponse
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');

        $entity = $site->entity;

        if (! $entity) {
            return response()->json(['data' => null], 404);
        }

        return response()->json(['data' => new WebsiteEntityResource($entity)]);
    }
}