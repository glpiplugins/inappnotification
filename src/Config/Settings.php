<?php

namespace GlpiPlugin\Inappnotification\Config;

use Config;

class Settings
{
    public const CONTEXT = 'plugin:inappnotification';

    public static function defaults(): array
    {
        return [
            'capture_savedsearch' => 1,
            'capture_ticket' => 1,
            'capture_project' => 1,
            'capture_reminder' => 1,
            'capture_sla' => 1,
            'capture_crontask' => 1,
            'capture_other' => 1,
            'ticket_open_only' => 0,
            'include_itemtypes' => '',
            'exclude_itemtypes' => '',
        ];
    }

    public static function ensureDefaults(): void
    {
        $current = Config::getConfigurationValues(self::CONTEXT);
        $defaults = self::defaults();
        $missing = [];

        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $current)) {
                $missing[$key] = $value;
            }
        }

        if ($missing !== []) {
            Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    public static function get(): array
    {
        self::ensureDefaults();
        $raw = Config::getConfigurationValues(self::CONTEXT);
        return self::normalize($raw);
    }

    public static function saveFromRequest(array $input): void
    {
        $defaults = self::defaults();
        $payload = [];

        foreach ($defaults as $key => $default_value) {
            if (is_int($default_value)) {
                $payload[$key] = (int) ($input[$key] ?? 0) > 0 ? 1 : 0;
                continue;
            }

            $payload[$key] = trim((string) ($input[$key] ?? ''));
        }

        Config::setConfigurationValues(self::CONTEXT, $payload);
    }

    public static function isSourceEnabled(array $settings, string $source_group): bool
    {
        $map = [
            'savedsearch' => 'capture_savedsearch',
            'ticket' => 'capture_ticket',
            'project' => 'capture_project',
            'reminder' => 'capture_reminder',
            'sla' => 'capture_sla',
            'crontask' => 'capture_crontask',
            'other' => 'capture_other',
        ];

        $key = $map[$source_group] ?? 'capture_other';
        return (int) ($settings[$key] ?? 0) === 1;
    }

    public static function getCsvValues(array $settings, string $key): array
    {
        $raw = trim((string) ($settings[$key] ?? ''));
        if ($raw === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));
        return array_values(array_filter($parts, static fn(string $value): bool => $value !== ''));
    }

    private static function normalize(array $raw): array
    {
        $defaults = self::defaults();
        $normalized = $defaults;

        foreach ($defaults as $key => $default_value) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }

            if (is_int($default_value)) {
                $normalized[$key] = (int) $raw[$key] > 0 ? 1 : 0;
            } else {
                $normalized[$key] = trim((string) $raw[$key]);
            }
        }

        return $normalized;
    }
}
