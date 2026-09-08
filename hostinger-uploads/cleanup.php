<?php
// =====================================================================
// cleanup.php — Borra los archivos adjuntos de comandas con más de 30
// días de antigüedad, para que el servidor no se llene de archivos que
// ya nadie necesita. No toca nada en Firestore/la app, solo el disco.
//
// Se puede ejecutar de dos formas (ambas activas):
//  1) Por cron job (recomendado): "php cleanup.php" una vez al día.
//  2) Automáticamente: upload.php dispara esta misma limpieza como
//     mucho una vez cada 24h, cuando llega un archivo nuevo real.
// =====================================================================

const MAX_AGE_SECONDS = 30 * 24 * 60 * 60; // 30 días
const UPLOAD_ROOT = __DIR__ . '/uploads';
const CHUNKS_ROOT = __DIR__ . '/uploads/_chunks';
const STALE_CHUNK_AGE_SECONDS = 24 * 60 * 60; // 24 horas: una subida por pedazos abandonada
const LOG_FILE = __DIR__ . '/cleanup.log';

function runCleanup() {
  $now = time();
  $deletedFiles = 0;
  $deletedDirs = 0;

  if (!is_dir(UPLOAD_ROOT)) {
    return ['files' => 0, 'dirs' => 0];
  }

  $comandaDirs = glob(UPLOAD_ROOT . '/*', GLOB_ONLYDIR);
  foreach ($comandaDirs as $dir) {
    // La carpeta de pedazos temporales (_chunks) tiene su propia limpieza,
    // con una antigüedad máxima distinta (ver runChunkCleanup).
    if (realpath($dir) === realpath(CHUNKS_ROOT)) continue;

    $files = glob($dir . '/*');
    foreach ($files as $file) {
      if (is_file($file) && (($now - filemtime($file)) > MAX_AGE_SECONDS)) {
        if (@unlink($file)) $deletedFiles++;
      }
    }

    // Si la carpeta de la comanda quedó vacía, se borra también
    $remaining = array_diff(scandir($dir), ['.', '..']);
    if (empty($remaining)) {
      if (@rmdir($dir)) $deletedDirs++;
    }
  }

  return ['files' => $deletedFiles, 'dirs' => $deletedDirs];
}

// Borra carpetas de pedazos (uploads/_chunks/{comandaId}/{uploadId}/) que
// quedaron abandonadas (el usuario cerró la pestaña, se le cortó la conexión
// y nunca se llamó a upload-finalize.php). 24h es de sobra para cualquier
// subida real, por lenta que sea.
function runChunkCleanup() {
  $now = time();
  $deletedDirs = 0;

  if (!is_dir(CHUNKS_ROOT)) {
    return ['dirs' => 0];
  }

  $comandaDirs = glob(CHUNKS_ROOT . '/*', GLOB_ONLYDIR);
  foreach ($comandaDirs as $comandaDir) {
    $uploadDirs = glob($comandaDir . '/*', GLOB_ONLYDIR);
    foreach ($uploadDirs as $uploadDir) {
      $parts = glob($uploadDir . '/*.part');
      $newestMtime = 0;
      foreach ($parts as $part) {
        $newestMtime = max($newestMtime, filemtime($part));
      }
      // Carpeta vacía o con pedazos viejos: se considera abandonada
      if (empty($parts) || ($now - $newestMtime) > STALE_CHUNK_AGE_SECONDS) {
        foreach ($parts as $part) @unlink($part);
        if (@rmdir($uploadDir)) $deletedDirs++;
      }
    }

    $remaining = array_diff(scandir($comandaDir), ['.', '..']);
    if (empty($remaining)) @rmdir($comandaDir);
  }

  return ['dirs' => $deletedDirs];
}

$result = runCleanup();
$chunkResult = runChunkCleanup();

$logLine = date('Y-m-d H:i:s') . " - Limpieza automática: {$result['files']} archivo(s) y {$result['dirs']} carpeta(s) vacías eliminadas (más de 30 días). Pedazos abandonados: {$chunkResult['dirs']} carpeta(s).\n";
@file_put_contents(LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);

// Solo "habla" (imprime/manda headers) cuando este archivo es el que se pidió
// directamente (cron con "php cleanup.php", o visitado por navegador). Si otro
// script lo incluye con require (como hace upload.php), se queda callado para
// no mezclar esta salida con la respuesta real de ese otro script.
$isDirectRequest = isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__;
if (php_sapi_name() === 'cli' || $isDirectRequest) {
  if (php_sapi_name() === 'cli') {
    echo $logLine;
  } else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $result + ['staleChunkDirs' => $chunkResult['dirs']]);
  }
}
