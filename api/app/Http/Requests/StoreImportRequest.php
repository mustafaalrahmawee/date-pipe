<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
    /**
     * Authentication is enforced by the auth:sanctum route middleware.
     * Any authenticated user may import until imports become owned
     * records (round 2).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        // Laravel's max rule measures files in kilobytes; the config
        // keeps bytes because the upload service compares bytes too.
        $maxKb = intdiv((int) config('imports.max_upload_bytes'), 1024);

        return [
            'file' => [
                'required',
                'file',
                'max:'.$maxKb,
                'mimes:csv,txt',
            ],
        ];
    }
}
