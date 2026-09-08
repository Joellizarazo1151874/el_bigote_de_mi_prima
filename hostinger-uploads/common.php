<?php
// =====================================================================
// common.php — Configuración y helpers compartidos por upload.php,
// upload-chunk.php y upload-finalize.php (verificación de sesión de
// Firebase, CORS, y la función de respuesta JSON).
// =====================================================================

// ⚠️ Debe coincidir con el "apiKey" de firebaseConfig (index.html) ⚠️
$FIREBASE_API_KEY = 'AIzaSyCBmH4SyMPKV0f7GOI3XuJ6LUpocHtRRtM';

// ⚠️ Dominios desde donde se permite subir archivos ⚠️
$ALLOWED_ORIGINS = [
  'https://bigote-de-mi-prima.web.app',
  'https://bigote-de-mi-prima.firebaseapp.com',
];

// Tamaño máximo por archivo (debe coincidir con MAX_FILE_SIZE_BYTES en index.html)
$MAX_FILE_SIZE = 100 * 1024 * 1024; // 100MB

// Extensiones permitidas (archivos de diseño / impresión habituales)
$ALLOWED_EXTENSIONS = ['pdf', 'ai', 'eps', 'cdr', 'psd', 'tif', 'tiff', 'jpg', 'jpeg', 'png', 'svg', 'zip'];

function applyCors($allowedOrigins) {
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
  }

  // El navegador puede mandar un preflight OPTIONS antes del POST real
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
  }

  header('Content-Type: application/json; charset=utf-8');
}

function respond($code, $data) {
  http_response_code($code);
  echo json_encode($data);
  exit;
}

// Verifica el ID token contra la API REST de Firebase Auth (sin SDK).
// Si no es válido, responde el error y termina la ejecución.
function verifyFirebaseIdToken($idToken, $apiKey) {
  $verifyUrl = "https://identitytoolkit.googleapis.com/v1/accounts:lookup?key={$apiKey}";
  $ch = curl_init($verifyUrl);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['idToken' => $idToken]));
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
  curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);
  curl_close($ch);

  if ($curlError) {
    respond(502, ['error' => 'No se pudo verificar la sesión (error de red): ' . $curlError]);
  }
  if ($httpCode !== 200) {
    respond(401, ['error' => 'Tu sesión no es válida o expiró. Vuelve a iniciar sesión e intenta de nuevo.']);
  }
  return true;
}
