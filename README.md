# El Bigote de Mi Prima — Sistema de Comandas Multi-Taller

Sistema web multiusuario para gestionar las órdenes de producción ("comandas") de un
taller de impresión digital y corte láser/acrílico. Reemplaza el mockup original (que
guardaba todo en `localStorage` del navegador) por una app real con login por
usuario/contraseña, base de datos en tiempo real, adjuntos de diseño descargables por
el taller, y un histórico filtrable/exportable para supervisión (MASTER).

## Roles

- **MASTER**: ve todo (Tablero, Cola de Taller, Histórico, Usuarios). Único que puede
  eliminar comandas, crear/deshabilitar usuarios y ver el reporte financiero (Costo /
  Abono / Saldo) en el Histórico.
- **DISEÑO**: crea comandas nuevas (cliente, medidas, materiales, archivo adjunto,
  taller destino).
- **TALLER** (Impresión, Láser, o Ambos): ve su cola de trabajos pendientes, acepta y
  finaliza cada comanda, y registra el consumo de material.

## Stack técnico

Todo corre **sin paso de build** — un solo `index.html` con React 18 + Babel
Standalone + Tailwind CSS, cargados por CDN. La lógica vive en un
`<script type="text/babel">` (JSX transpilado en el navegador).

- **Firebase Authentication** — login por usuario/contraseña (el "usuario" que
  escribe cada empleado se mapea internamente a un correo sintético
  `usuario@bigote.local`, nunca ve ni escribe un correo real).
- **Cloud Firestore** — base de datos en tiempo real (`onSnapshot`) para usuarios y
  comandas.
- **Firebase Hosting** — sirve el `index.html` estático.
- **Un servidor PHP en Hostinger** (carpeta `hostinger-uploads/`) para los archivos de
  diseño adjuntos — **no** se usa Firebase Storage (requiere plan de pago). Los
  archivos grandes se suben por partes en paralelo (ver más abajo).

## Estructura del proyecto

```
index.html                     App completa (React/Babel/Tailwind + Firebase)
firebase.json                  Config de Firebase Hosting + Firestore
firestore.rules                Reglas de seguridad de la base de datos
firestore.indexes.json         Índices compuestos que requieren las consultas
logo.jpg / favicon.png         Marca del taller
bigoteprueba.html              Mockup de referencia del cliente (no se despliega)

hostinger-uploads/
  common.php                  Config + helpers compartidos (verificación de sesión)
  upload.php                  Subida de archivos pequeños (un solo POST)
  upload-chunk.php             Recibe un pedazo de un archivo grande
  upload-finalize.php          Ensambla los pedazos y devuelve la URL final
  download.php                Sirve los archivos (evita el CDN de Hostinger, que
                               falla con imágenes grandes)
  cleanup.php                  Borra archivos de +30 días y pedazos abandonados
  .htaccess / .user.ini        Límites de subida de PHP, bloqueo de ejecución de
                               scripts dentro de uploads/
```

## Cómo levantarlo desde cero

### 1. Firebase

1. Crea un proyecto en [console.firebase.google.com](https://console.firebase.google.com).
2. Activa **Authentication** → método Email/Contraseña.
3. Crea **Firestore Database** (modo producción).
4. Registra una "Web App" dentro del proyecto y copia el objeto `firebaseConfig`
   resultante en [index.html](index.html) (busca `const firebaseConfig = {`).
5. Crea a mano, directo en la consola de Firebase (Authentication + Firestore), el
   primer usuario MASTER — con `role: "MASTER"` en su documento de `usuarios/{uid}`.
6. Instala el CLI y despliega:
   ```bash
   npm install -g firebase-tools
   firebase login
   firebase deploy --only hosting
   firebase deploy --only firestore:rules
   firebase deploy --only firestore:indexes
   ```

### 2. Servidor de archivos (Hostinger, o cualquier hosting con PHP)

1. Sube toda la carpeta `hostinger-uploads/` a tu hosting (por ejemplo, dentro de
   `public_html/hostinger-uploads/`).
2. En `hostinger-uploads/common.php`, pega el mismo `apiKey` que usaste en
   `firebaseConfig`, y en `$ALLOWED_ORIGINS` pon el dominio de tu Firebase Hosting.
3. En `index.html`, actualiza `HOSTINGER_UPLOAD_URL`, `HOSTINGER_CHUNK_URL` y
   `HOSTINGER_FINALIZE_URL` con la URL real de tu servidor.
4. La subcarpeta `uploads/` (donde quedan guardados los archivos) se crea sola.

### 3. Uso diario

- No hace falta build ni servidor local: `index.html` se puede abrir directo o
  servirse con cualquier servidor estático para probar en local (`firebase
  emulators:start` para probar Firestore/Auth sin tocar producción).
- Cada cambio se despliega con `firebase deploy --only hosting`.

## Notas de seguridad

- El control de acceso real vive en **Firestore Security Rules**
  ([firestore.rules](firestore.rules)): un operario de un taller no puede leer ni
  escribir comandas de otro taller, ni con la consola del navegador.
- La `apiKey` de Firebase que aparece en `index.html` **no es un secreto** — es un
  identificador público por diseño de Firebase (la protección real es Firestore
  Rules + Authentication). Aun así, se recomienda restringirla en Google Cloud
  Console a las APIs y dominios que realmente usa la app.
- `hostinger-uploads/*.php` verifica el token de sesión de Firebase (contra la API
  de Google) antes de aceptar cualquier subida, y bloquea la ejecución de cualquier
  script dentro de `uploads/`.
- Nada de contraseñas, tokens ni credenciales de servidor vive en el código — el
  login de cada empleado se valida contra Firebase Auth, nunca contra un valor
  guardado en el repo.
