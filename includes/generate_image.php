<?php
/**
 * Генерация изображения для статьи через Stable Diffusion (Hugging Face Space)
 * Использует только /infer, пропорции 16:9, промт на английском
 * Вход: prompt (англ.)
 * Выход: URL изображения или ошибка
 */
const SPACE_URL = 'https://stabilityai-stable-diffusion.hf.space';
const API = 'infer';

function generate_image(string $prompt): array {
    $scale = 9; // стандартное значение
    $negative = ''; // можно добавить негативный промт при необходимости
    $payload = json_encode(['data' => [$prompt, $negative, $scale]], JSON_UNESCAPED_UNICODE);
    $url = rtrim(SPACE_URL, '/') . '/call/' . API;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || !$resp || $code >= 400) {
        return [
            'error' => 'Ошибка запроса к Space.',
            'details' => ['http_code' => $code, 'curl_error' => $err, 'response' => $resp],
        ];
    }

    $json = json_decode($resp, true);
    if (!isset($json['event_id'])) {
        return ['error' => 'Не получили event_id от Space.', 'raw' => $resp];
    }
    $event_id = $json['event_id'];

    // Получаем результат генерации через SSE (эмулируем обычным GET)
    $result_url = rtrim(SPACE_URL, '/') . '/call/' . API . '/' . $event_id;
    $ch = curl_init($result_url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $result = curl_exec($ch);
    $err2 = curl_error($ch);
    $code2 = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err2 || !$result || $code2 >= 400) {
        return [
            'error' => 'Ошибка получения результата.',
            'details' => ['http_code' => $code2, 'curl_error' => $err2, 'response' => $result],
        ];
    }

    // Парсим URL изображения из ответа
    $data = json_decode($result, true);
    $img_url = null;
    if (is_array($data)) {
        if (isset($data[0]['url'])) {
            $img_url = $data[0]['url'];
        } elseif (isset($data[0]['image']['url'])) {
            $img_url = $data[0]['image']['url'];
        } elseif (isset($data[0]['image']['path'])) {
            $img_url = SPACE_URL . '/file=' . $data[0]['image']['path'];
        } elseif (isset($data[0]['path'])) {
            $img_url = SPACE_URL . '/file=' . $data[0]['path'];
        }
    }
    if (!$img_url) {
        return ['error' => 'Не удалось получить URL изображения.', 'raw' => $result];
    }
    return ['url' => $img_url];
}

// Пример использования:
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $prompt = $argv[1];
    $res = generate_image($prompt);
    print_r($res);
}

// Для интеграции через require/include:
// $result = generate_image($prompt);
// if (isset($result['url'])) { ... }
