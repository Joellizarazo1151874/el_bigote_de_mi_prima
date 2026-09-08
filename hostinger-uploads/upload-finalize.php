<?php
// =====================================================================
// upload-finalize.php — Se llama después de que TODOS los pedazos de un
// archivo grande ya se subieron (ver upload-chunk.php). Verifica que estén
// todos, los concatena en orden para formar el archivo final, valida que el
// tamaño ensamblado coincida con lo esperado, y borra los pedazos temporales.
// =====================================================================

require __DIR__ . '/common.php';
applyCors($ALLOWED_ORIGINS);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond(405, ['error' => 'Método no permitido']);
}

$idToken = $_POST['idToken'] ?? '';
$comandaId = $_POST['comandaId'] ?? '';
$uploadId = $_POST['uploadId'] ?? '';
$totalChunks = $_POST['totalChunks'] ?? '';
$originalName = $_POST['originalName'] ?? '';
$expectedSize = $_POST['totalSize'] ?? '';

if (!$idToken || !$comandaId || !$uploadId || !$totalChunks || !$originalName || $expectedSize === '') {
  respond(400, ['error' => 'Faltan datos para finalizar la subida']);
}
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $comandaId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $uploadId)) {
  respond(400, ['error' => 'ID inválido']);
}
if (!ctype_digit((string) $totalChunks) || !ctype_digit((string) $expectedSize)) {
  respond(400, ['error' => 'Datos numéricos inválidos']);
}

$totalChunks = (int) $totalChunks;
$expectedSize = (int) $expectedSize;

if ($expectedSize > $MAX_FILE_SIZE) {
  respond(400, ['error' => 'El archivo supera el tamaño máximo permitido (' . ($MAX_FILE_SIZE / 1024 / 1024) . 'MB)']);
}

$originalNameSafe = basename($originalName);
$extension = strtolower(pathinfo($originalNameSafe, PATHINFO_EXTENSION));
if (!in_array($extension, $ALLOWED_EXTENSIONS, true)) {
  respond(400, ['error' => 'Tipo de archivo no permitido: .' . $extension]);
}

verifyFirebaseIdToken($idToken, $FIREBASE_API_KEY);

$chunkDir = __DIR__ . '/uploads/_chunks/' . $comandaId . '/' . $uploadId;
if (!is_dir($chunkDir)) {
  respond(400, ['error' => 'No se encontraron los pedazos de este archivo (¿ya expiró la subida?). Intenta de nuevo.']);
}

// Verificar que estén TODOS los pedazos antes de ensamblar
for ($i = 0; $i < $totalChunks; $i++) {
  if (!is_file($chunkDir . '/' . $i . '.part')) {
    respond(400, ['error' => "Falta el pedazo $i de $totalChunks. Intenta subir el archivo de nuevo."]);
  }
}

$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalNameSafe);
$safeName = uniqid('', true) . '_' . $safeName;

$uploadRootDir = __DIR__ . '/uploads';
$targetDir = $uploadRootDir . '/' . $comandaId;
if (!is_dir($targetDir)) {
  if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
    respond(500, ['error' => 'No se pudo crear la carpeta de destino en el servidor']);
  }
}

$targetPath = $targetDir . '/' . $safeName;
$out = fopen($targetPath, 'wb');
if (!$out) {
  respond(500, ['error' => 'No se pudo crear el archivo final en el servidor']);
}

$totalWritten = 0;
for ($i = 0; $i < $totalChunks; $i++) {
  $partPath = $chunkDir . '/' . $i . '.part';
  $in = fopen($partPath, 'rb');
  if (!$in) {
    fclose($out);
    @unlink($targetPath);
    respond(500, ['error' => "No se pudo leer el pedazo $i al ensamblar el archivo"]);
  }
  while (!feof($in)) {
    $buffer = fread($in, 1024 * 1024);
    if ($buffer === false) break;
    fwrite($out, $buffer);
    $totalWritten += strlen($buffer);
  }
  fclose($in);
}
fclose($out);

// Limpiar los pedazos temporales, ya no se necesitan
foreach (glob($chunkDir . '/*.part') as $part) {
  @unlink($part);
}
@rmdir($chunkDir);
$comandaChunkDir = dirname($chunkDir);
if (is_dir($comandaChunkDir)) {
  $remaining = array_diff(scandir($comandaChunkDir), ['.', '..']);
  if (empty($remaining)) @rmdir($comandaChunkDir);
}

if ($totalWritten !== $expectedSize) {
  @unlink($targetPath);
  respond(500, ['error' => "El archivo ensamblado no coincide en tamaño (esperado $expectedSize, obtenido $totalWritten). Intenta de nuevo."]);
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$publicUrl = "{$protocol}://{$host}{$scriptDir}/download.php?comandaId=" . urlencode($comandaId) . "&file=" . urlencode($safeName) . "&name=" . urlencode($originalNameSafe);

$cleanupMarker = __DIR__ . '/.last_cleanup';
$lastCleanup = is_file($cleanupMarker) ? (int) file_get_contents($cleanupMarker) : 0;
if ((time() - $lastCleanup) > 86400) {
  @file_put_contents($cleanupMarker, (string) time());
  require __DIR__ . '/cleanup.php';
}

respond(200, [
  'url' => $publicUrl,
  'name' => $originalNameSafe,
  'size' => $totalWritten,
]);
