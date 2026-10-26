<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Platform-wide legal pages (terms of service, privacy policy). Until the
 * platform admin edits them, a default template from resources/legal is used.
 */
class PlatformPage extends Model
{
    public const PAGES = ['terms' => 'الشروط والأحكام', 'privacy' => 'سياسة الخصوصية وحماية البيانات'];

    protected $fillable = ['slug', 'title', 'body', 'updated_by'];

    public static function content(string $slug): array
    {
        abort_unless(isset(self::PAGES[$slug]), 404);
        $page = static::where('slug', $slug)->first();

        return [
            'slug' => $slug,
            'title' => $page?->title ?? self::PAGES[$slug],
            'body' => $page?->body ?? (string) @file_get_contents(resource_path("legal/{$slug}.ar.md")),
            'updated_at' => $page?->updated_at,
        ];
    }

    /** Markdown to HTML with raw HTML stripped (safe to print). */
    public static function html(string $body): string
    {
        return Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }
}
