<?php
require_once __DIR__ . '/../promotion_helpers.php';

if (!function_exists('pp_promotion_settings')) {
    function pp_promotion_settings(): array {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $defaults = [
            'level1_enabled' => true,
            'level2_enabled' => true,
            'level3_enabled' => false,
            'crowd_enabled' => true,
            'level1_count' => 5,
            'level2_per_level1' => 10,
            'level3_per_level2' => 5,
            'level1_min_len' => 2800,
            'level1_max_len' => 3400,
            'level2_min_len' => 1400,
            'level2_max_len' => 2100,
            'level3_min_len' => 900,
            'level3_max_len' => 1400,
            'crowd_per_article' => 100,
            'network_repeat_limit' => 2,
            'price_per_link' => max(0, (float)str_replace(',', '.', (string)get_setting('promotion_price_per_link', '0'))),
        ];
        $map = [
            'promotion_level1_enabled' => 'level1_enabled',
            'promotion_level2_enabled' => 'level2_enabled',
            'promotion_level3_enabled' => 'level3_enabled',
            'promotion_crowd_enabled' => 'crowd_enabled',
        ];
        foreach ($map as $settingKey => $localKey) {
            $raw = get_setting($settingKey, $defaults[$localKey] ? '1' : '0');
            $cacheBool = !in_array(strtolower((string)$raw), ['0', 'false', 'no', 'off', ''], true);
            $defaults[$localKey] = $cacheBool;
        }
        $level1CountSetting = (int)get_setting('promotion_level1_count', (string)$defaults['level1_count']);
        if ($level1CountSetting > 0) {
            $defaults['level1_count'] = max(1, min(500, $level1CountSetting));
        }
        $level2PerSetting = (int)get_setting('promotion_level2_per_level1', (string)$defaults['level2_per_level1']);
        if ($level2PerSetting > 0) {
            $defaults['level2_per_level1'] = max(1, min(500, $level2PerSetting));
        }
        $level3PerSetting = (int)get_setting('promotion_level3_per_level2', (string)$defaults['level3_per_level2']);
        if ($level3PerSetting > 0) {
            $defaults['level3_per_level2'] = max(1, min(500, $level3PerSetting));
        }
        $crowdPerSetting = (int)get_setting('promotion_crowd_per_article', (string)$defaults['crowd_per_article']);
        if ($crowdPerSetting >= 0) {
            $defaults['crowd_per_article'] = max(0, min(10000, $crowdPerSetting));
        }
        $cache = $defaults;
        return $cache;
    }
}

if (!function_exists('pp_promotion_is_level_enabled')) {
    function pp_promotion_is_level_enabled(int $level): bool {
        $settings = pp_promotion_settings();
        if ($level === 1) { return !empty($settings['level1_enabled']); }
        if ($level === 2) { return !empty($settings['level1_enabled']) && !empty($settings['level2_enabled']); }
        if ($level === 3) { return !empty($settings['level1_enabled']) && !empty($settings['level2_enabled']) && !empty($settings['level3_enabled']); }
        return false;
    }
}

if (!function_exists('pp_promotion_is_crowd_enabled')) {
    function pp_promotion_is_crowd_enabled(): bool {
        $settings = pp_promotion_settings();
        return !empty($settings['crowd_enabled']);
    }
}

if (!function_exists('pp_promotion_get_level_requirements')) {
    function pp_promotion_get_level_requirements(): array {
        $settings = pp_promotion_settings();
        return [
            1 => ['count' => max(1, (int)$settings['level1_count']), 'min_len' => (int)$settings['level1_min_len'], 'max_len' => (int)$settings['level1_max_len']],
            2 => ['per_parent' => max(1, (int)$settings['level2_per_level1']), 'min_len' => (int)$settings['level2_min_len'], 'max_len' => (int)$settings['level2_max_len']],
            3 => ['per_parent' => max(1, (int)$settings['level3_per_level2']), 'min_len' => (int)$settings['level3_min_len'], 'max_len' => (int)$settings['level3_max_len']],
        ];
    }
}
