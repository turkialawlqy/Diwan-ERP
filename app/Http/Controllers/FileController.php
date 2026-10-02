<?php

namespace App\Http\Controllers;

use App\Services\TenantManager;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves tenant files from the private disk. Files are never exposed on the
 * public web root: each one lives under "companies/{company_id}/..." and is
 * only streamed back to users of that same company.
 */
class FileController extends Controller
{
    /** Extensions accepted for general document uploads (no html/svg/scripts). */
    public const DOCUMENT_MIMES = 'pdf,jpg,jpeg,png,webp,gif,doc,docx,xls,xlsx,csv,txt,zip';

    public function show(string $token): StreamedResponse
    {
        try {
            $path = Crypt::decryptString($token);
        } catch (DecryptException) {
            abort(404);
        }

        $tenantId = app(TenantManager::class)->getTenantId();

        if (! $tenantId || ! str_starts_with($path, "companies/{$tenantId}/") || str_contains($path, '..')) {
            abort(404);
        }

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        // Always download as an attachment so nothing is rendered in our origin.
        return Storage::disk('local')->download($path, basename($path), [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    /**
     * Store an uploaded file privately for the current tenant and return the
     * relative URL that serves it.
     */
    public static function storeForTenant(UploadedFile $file, string $folder): array
    {
        $tenantId = app(TenantManager::class)->getTenantId();
        abort_unless($tenantId, 403);

        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $path = $file->storeAs("companies/{$tenantId}/{$folder}", Str::uuid().'.'.$extension, 'local');

        return [
            'path' => $path,
            'url' => route('files.show', ['token' => Crypt::encryptString($path)], false),
        ];
    }
}
