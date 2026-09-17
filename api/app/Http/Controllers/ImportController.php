<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImportRequest;
use App\Models\Import;
use App\Services\ImportProcessingService;
use App\Services\ImportReportService;
use App\Services\ImportUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function index(Request $request): JsonResponse
    {
        $imports = $request->user()
            ->imports()
            ->latest()
            ->paginate(max(1, min(100, $request->integer('per_page', 50))));

        return response()->json($imports);
    }

    public function process(Request $request, string $id, ImportProcessingService $service): JsonResponse
    {
        $import = $request->user()->imports()->findOrFail($id);

        $result = $service->process($import);

        return response()->json([
            'data' => $result->toArray(),
            'message' => 'Import processed.',
        ]);
    }

    public function records(Request $request, string $id): JsonResponse
    {
        $import = $request->user()->imports()->findOrFail($id);

        $records = $import->records()
            ->orderBy('row_index')
            ->paginate(max(1, min(100, $request->integer('per_page', 50))));

        return response()->json($records);
    }

    public function report(Request $request, string $id, ImportReportService $service): JsonResponse
    {
        $import = $request->user()->imports()->findOrFail($id);

        return response()->json([
            'data' => $service->report($import),
            'message' => 'Import report.',
        ]);
    }
}
