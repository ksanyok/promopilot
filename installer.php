<?php
// Installer script for PromoPilot
// Downloads files from GitHub repository

$repo_url = 'https://github.com/ksanyok/promopilot/archive/refs/heads/main.zip';
$zip_file = 'promopilot-main.zip';

// Download the zip
file_put_contents($zip_file, file_get_contents($repo_url));

// Extract the zip
$zip = new ZipArchive;
if ($zip->open($zip_file) === TRUE) {
    $zip->extractTo('.');
    $zip->close();
    echo 'Files downloaded and extracted successfully.';
} else {
    echo 'Failed to extract zip.';
}

// Clean up
unlink($zip_file);
?>
