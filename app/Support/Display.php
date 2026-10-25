<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class Display
{
    public static function time(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $moment = $value instanceof CarbonInterface ? $value : Carbon::parse($value);

        return $moment->timezone((string) config('swimshady.display_timezone'))->format('Y-m-d H:i:s');
    }

    public static function temp(?float $fahrenheit): ?string
    {
        if ($fahrenheit === null) {
            return null;
        }

        $rounded = round($fahrenheit, 1);
        $text = fmod($rounded, 1.0) === 0.0
            ? (string) (int) $rounded
            : number_format($rounded, 1, '.', '');

        return $text.' °F';
    }

    public static function iso(?CarbonInterface $moment): ?string
    {
        return $moment?->utc()->toISOString();
    }
}
