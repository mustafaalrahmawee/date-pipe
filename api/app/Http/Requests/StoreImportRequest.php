<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
    /**
     * Authentication is enforced by the auth:sanctum route middleware;
     * ownership (user_id) is set server-side via the user relation in
     * ImportController, never from request input.
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
