<?php
// =====================================================================
// upload.php — Recibe los archivos de diseño de cada comanda y los guarda
// en este servidor Hostinger. Verifica el ID token de Firebase Auth contra
// la API REST de Google (sin necesitar el SDK de administrador ni librerías
// externas), para asegurarse de que solo empleados con sesión iniciada
// puedan subir archivos.
//
// Se usa tal cual (subida en un solo POST) para archivos pequeños. Los
// archivos grandes se suben por pedazos vía upload-chunk.php +
// upload-finalize.php (ver esos archivos), que dividen la carga en varias
// conexiones en paralelo para aprovechar mejor el ancho de banda.
//
// SUBE ESTA CARPETA COMPLETA (hostinger-uploads/) a tu Hostinger, por
// ejemplo dentro de public_html/hostinger-uploads/. La subcarpeta
// "uploads/" (donde quedan guardados los archivos) se crea sola.
// =====================================================================

require __DIR__ . '/common.php';
applyCors($ALLOWED_ORIGINS);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond(405, ['error' => 'Método no permitido']);
}

$idToken = $_POST['idToken'] ?? '';
$comandaId = $_POST['comandaId'] ?? '';

if (!$idToken || !$comandaId) {
  respond(400, ['error' => 'Faltan datos (idToken o comandaId)']);
}

// Solo se permiten IDs de comanda con el formato que genera Firestore
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $comandaId)) {
  respond(400, ['error' => 'ID de comanda inválido']);
}

verifyFirebaseIdToken($idToken, $FIREBASE_API_KEY);

// --- Validar el archivo recibido ---
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
  $uploadErr = $_FILES['file']['error'] ?? 'sin archivo';
  respond(400, ['error' => 'No se recibió el archivo correctamente (código: ' . $uploadErr . ')']);
}

if ($_FILES['file']['size'] > $MAX_FILE_SIZE) {
  respond(400, ['error' => 'El archivo supera el tamaño máximo permitido (' . ($MAX_FILE_SIZE / 1024 / 1024) . 'MB)']);
}

$originalName = basename($_FILES['file']['name']);
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if (!in_array($extension, $ALLOWED_EXTENSIONS, true)) {
  respond(400, ['error' => 'Tipo de archivo no permitido: .' . $extension]);
}

// Nombre seguro para guardar en disco (sin espacios ni caracteres raros).
// uniqid() (con entropía extra) en vez de time(): varios archivos de una misma
// comanda pueden subirse en paralelo dentro del mismo segundo.
$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
$safeName = uniqid('', true) . '_' . $safeName;

$uploadRootDir = __DIR__ . '/uploads';
$targetDir = $uploadRootDir . '/' . $comandaId;

if (!is_dir($targetDir)) {
  if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
    respond(500, ['error' => 'No se pudo crear la carpeta de destino en el servidor']);
  }
}

$targetPath = $targetDir . '/' . $safeName;

if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
  respond(500, ['error' => 'No se pudo guardar el archivo en el servidor']);
}

// --- Construir la URL pública del archivo ---
// Se sirve a través de download.php (no como ruta estática directa) porque
// el CDN de Hostinger intenta "optimizar" imágenes grandes y falla con
// error 422 en fotos de alta resolución (comunes en diseños de impresión).
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$publicUrl = "{$protocol}://{$host}{$scriptDir}/download.php?comandaId=" . urlencode($comandaId) . "&file=" . urlencode($safeName) . "&name=" . urlencode($originalName);

// Dispara la limpieza de archivos de más de 30 días como mucho 1 vez al día,
// para que el servidor no se llene de basura aunque no haya cron configurado.
// No retrasa la respuesta al usuario de forma perceptible (es un barrido rápido).
$cleanupMarker = __DIR__ . '/.last_cleanup';
$lastCleanup = is_file($cleanupMarker) ? (int) file_get_contents($cleanupMarker) : 0;
if ((time() - $lastCleanup) > 86400) {
  @file_put_contents($cleanupMarker, (string) time());
  require __DIR__ . '/cleanup.php';
}

respond(200, [
  'url' => $publicUrl,
  'name' => $originalName,
  'size' => $_FILES['file']['size'],
]);
