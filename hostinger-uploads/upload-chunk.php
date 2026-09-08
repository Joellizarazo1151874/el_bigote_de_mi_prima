<?php
// =====================================================================
// upload-chunk.php — Recibe UN pedazo de un archivo grande (ver
// upload-finalize.php, que ensambla todos los pedazos al final). El
// navegador sube varios pedazos en paralelo (varias conexiones a la vez),
// lo que en la práctica sube el rendimiento efectivo en este hosting
// compartido, donde cada conexión individual tiene un límite de velocidad.
// =====================================================================

require __DIR__ . '/common.php';
applyCors($ALLOWED_ORIGINS);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond(405, ['error' => 'Método no permitido']);
}

$idToken = $_POST['idToken'] ?? '';
$comandaId = $_POST['comandaId'] ?? '';
$uploadId = $_POST['uploadId'] ?? '';
$chunkIndex = $_POST['chunkIndex'] ?? '';
$totalChunks = $_POST['totalChunks'] ?? '';

if (!$idToken || !$comandaId || !$uploadId || $chunkIndex === '' || !$totalChunks) {
  respond(400, ['error' => 'Faltan datos del pedazo (chunk)']);
}
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $comandaId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $uploadId)) {
  respond(400, ['error' => 'ID inválido']);
}
if (!ctype_digit((string) $chunkIndex) || !ctype_digit((string) $totalChunks)) {
  respond(400, ['error' => 'Índice de pedazo inválido']);
}

$chunkIndex = (int) $chunkIndex;
$totalChunks = (int) $totalChunks;
if ($totalChunks < 1 || $totalChunks > 2000 || $chunkIndex < 0 || $chunkIndex >= $totalChunks) {
  respond(400, ['error' => 'Rango de pedazo inválido']);
}

verifyFirebaseIdToken($idToken, $FIREBASE_API_KEY);

if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
  $uploadErr = $_FILES['chunk']['error'] ?? 'sin archivo';
  respond(400, ['error' => 'No se recibió el pedazo correctamente (código: ' . $uploadErr . ')']);
}

$chunkDir = __DIR__ . '/uploads/_chunks/' . $comandaId . '/' . $uploadId;
if (!is_dir($chunkDir)) {
  if (!mkdir($chunkDir, 0755, true) && !is_dir($chunkDir)) {
    respond(500, ['error' => 'No se pudo crear la carpeta temporal del pedazo']);
  }
}

$chunkPath = $chunkDir . '/' . $chunkIndex . '.part';
if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkPath)) {
  respond(500, ['error' => 'No se pudo guardar el pedazo en el servidor']);
}

respond(200, ['ok' => true, 'chunkIndex' => $chunkIndex]);
