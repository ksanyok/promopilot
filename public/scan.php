<?php
// Скрипт для сканирования строк локализации

function scan_files($dir, &$strings) {
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            scan_files($path, $strings);
        } elseif (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            $content = file_get_contents($path);
            // Найти строки в двойных кавычках
            preg_match_all('/"([^"]*)"/', $content, $matches);
            foreach ($matches[1] as $str) {
                if (!empty($str) && !is_numeric($str) && !preg_match('/^https?:\/\//', $str)) {
                    $strings[$str] = $str;
                }
            }
            // Найти строки в одинарных кавычках
            preg_match_all("/'([^']*)'/", $content, $matches);
            foreach ($matches[1] as $str) {
                if (!empty($str) && !is_numeric($str) && !preg_match('/^https?:\/\//', $str)) {
                    $strings[$str] = $str;
                }
            }
        }
    }
}

$strings = [];
scan_files('.', $strings);

// Вывести массив для копирования в lang файл
echo "<?php\n\$lang = [\n";
foreach ($strings as $key => $value) {
    echo "    '$key' => '$value',\n";
}
echo "];\n?>";
?>