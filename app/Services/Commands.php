<?php

namespace App\Services;

use InvalidArgumentException;

class Commands
{
    public const SIMPLE = ['system_on', 'system_off', 'pump_on', 'pump_off', 'cancel_cooldown'];

    /**
     * @return array{0: string, 1: ?string}
     */
    public static function normalize(string $action, ?string $value): array
    {
        if ($action === 'set_target') {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if ($parsed === false || $parsed < 70 || $parsed > 104) {
                throw new InvalidArgumentException('target temp must be 70–104');
            }

            return ['set_target', (string) $parsed];
        }

        if (! in_array($action, self::SIMPLE, true)) {
            throw new InvalidArgumentException('unknown action');
        }

        return [$action, null];
    }
}
