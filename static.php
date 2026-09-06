<?php
// 静态文件代理：绕过 InfinityFree JS Challenge
// 脚本通过 GM_xmlhttpRequest 请求此端点，返回静态资源

$path = $_GET['path'] ?? '';
$path = preg_replace('#[^a-zA-Z0-9/._-]#', '', $path);

$base = __DIR__ . '/static';
$file = $base . '/' . $path;

if (!is_file($file) || strpos(realpath($file), realpath($base)) !== 0) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mimeMap = [
    'woff2' => 'font/woff2',
    'woff'  => 'font/woff',
    'ttf'   => 'font/ttf',
    'otf'   => 'font/otf',
    'png'   => 'image/png',
    'jpg'   => 'image/jpeg',
    'jpeg'  => 'image/jpeg',
    'gif'   => 'image/gif',
    'svg'   => 'image/svg+xml',
    'ico'   => 'image/x-icon',
    'css'   => 'text/css',
    'js'    => 'application/javascript',
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($file));
readfile($file);
