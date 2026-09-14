<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImportRequest;
use App\Services\ImportUploadService;
use Illuminate\Http\JsonResponse;

class ImportController extends Controller
{
    public function store(StoreImportRequest $request, ImportUploadService $service): JsonResponse
    {
        $result = $service->store($request->file('file'));

        $import = $request->user()->imports()->create([
            'path' => $result->path,
            'original_name' => $result->originalName,
            'size_bytes' => $result->sizeBytes,
            'header' => $result->header,
        ]);

        return response()->json([
            'data' => $import,
            'message' => 'Import created.',
        ], 201);
    }
}
