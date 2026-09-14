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
        // Content type and size are validated by ImportUploadService so
        // invalid files map to 415/413 instead of validation errors.
        return [
            'file' => [
                'required',
                'file',
            ],
        ];
    }
}
