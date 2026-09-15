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
            ->paginate($this->perPage($request));

        return response()->json($imports);
    }

    public function process(Request $request, string $id, ImportProcessingService $service): JsonResponse
    {
        $import = $this->findOwnedImport($request, $id);

        $result = $service->process($import);

        return response()->json([
            'data' => $result->toArray(),
            'message' => 'Import processed.',
        ]);
    }

    public function records(Request $request, string $id): JsonResponse
    {
        $import = $this->findOwnedImport($request, $id);

        $records = $import->records()
            ->orderBy('row_index')
            ->paginate($this->perPage($request));

        return response()->json($records);
    }

    public function report(Request $request, string $id, ImportReportService $service): JsonResponse
    {
        $import = $this->findOwnedImport($request, $id);

        return response()->json([
            'data' => $service->report($import),
            'message' => 'Import report.',
        ]);
    }

    /**
     * Resolves the import scoped to the authenticated user: imports of
     * other users answer 404, so the endpoints do not leak that they
     * exist. Route model binding is deliberately unused, it would
     * fetch by id alone.
     */
    private function findOwnedImport(Request $request, string $id): Import
    {
        return $request->user()->imports()->findOrFail($id);
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 50)));
    }
}
