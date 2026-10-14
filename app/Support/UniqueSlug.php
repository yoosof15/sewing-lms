<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class UniqueSlug
{
    /** @param class-string<Model> $model */
    public static function make(string $model, string $value, string $prefix): string
    {
        $base = Str::slug($value);
        $base = $base !== '' ? $base : $prefix.'-'.Str::lower((string) Str::ulid());
        $slug = $base;
        $suffix = 2;

        while ($model::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
