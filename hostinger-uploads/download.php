<?php
// =====================================================================
// download.php — Sirve los archivos adjuntos de las comandas.
//
// No se sirven como ruta estática directa (/uploads/{id}/{archivo}) porque
// el CDN de Hostinger intenta "optimizar" automáticamente los archivos de
// imagen, y eso falla (error 422) con fotos grandes de alta resolución,
// muy comunes en diseños de impresión (banners, pendones, etc.). Al pasar
// por este script PHP, el CDN ya no intenta procesar la imagen.
// =====================================================================

$comandaId = $_GET['comandaId'] ?? '';
$file = $_GET['file'] ?? '';
$niceName = $_GET['name'] ?? '';

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $comandaId) || !preg_match('/^[a-zA-Z0-9._-]+$/', $file)) {
  http_response_code(400);
  exit('Parámetros inválidos');
}

$uploadRootDir = realpath(__DIR__ . '/uploads');
$targetPath = realpath($uploadRootDir . '/' . $comandaId . '/' . $file);

// Evita que alguien pida un archivo fuera de la carpeta uploads/ (path traversal)
if (!$targetPath || strpos($targetPath, $uploadRootDir) !== 0 || !is_file($targetPath)) {
  http_response_code(404);
  exit('Archivo no encontrado');
}

$mime = function_exists('mime_content_type') ? (mime_content_type($targetPath) ?: 'application/octet-stream') : 'application/octet-stream';
$downloadName = $niceName !== '' ? str_replace(['"', "\r", "\n"], '', $niceName) : basename($targetPath);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($targetPath));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Cache-Control: public, max-age=31536000');
header('X-Content-Type-Options: nosniff');

readfile($targetPath);
