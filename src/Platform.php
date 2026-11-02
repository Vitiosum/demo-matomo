<?php

declare(strict_types=1);

namespace Museum;

/** The platform variables shown in the "Running on Clever Cloud" panel. No other variable is ever displayed. */
final class Platform
{
    public const SHOWN = [
        'CC_APP_NAME' => 'Application',
        'APP_ID' => 'App ID',
        'INSTANCE_NUMBER' => 'Instance',
        'INSTANCE_TYPE' => 'Instance type',
        'CC_PRETTY_INSTANCE_NAME' => 'Instance name',
        'CC_COMMIT_ID' => 'Commit',
        'CC_DEPLOYMENT_ID' => 'Deployment',
    ];

    /** @return array<string, ?string> label => value (null when not on Clever Cloud) */
    public static function values(): array
    {
        $out = [];
        foreach (self::SHOWN as $name => $label) {
            $value = getenv($name);
            if ($value !== false && $name === 'CC_COMMIT_ID') {
                $value = substr($value, 0, 7);
            }
            $out[$label] = $value === false || $value === '' ? null : $value;
        }
        return $out;
    }

    public static function onClever(): bool
    {
        return getenv('APP_ID') !== false;
    }
}
