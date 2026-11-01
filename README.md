# Créditos Finales

Diario de los juegos que te vas terminando cada año, para compartir con los colegas. Al añadir uno sale su carátula, te da la enhorabuena y suena su banda sonora mientras rellenas la ficha tarjeta a tarjeta: horas (con los tiempos de HowLongToBeat), nota, tema favorito, comentario… Un `index.html` y un `api.php`, sin base de datos.

## Instalar en XAMPP

1. Copia los ficheros a `htdocs/creditos/` (o la carpeta que quieras).
2. Copia `config.example.php` como `config.php` y pon tus claves.
3. Abre `http://localhost/creditos/` y crea tu cuenta. Si algo no va, abre `api.php?action=diag`.

PHP 8 con OpenSSL. cURL es opcional (si está desactivado se usa `file_get_contents`).

## Claves (opcionales, gratis) en `config.php`

- **rawg_key** → https://rawg.io/apidocs. Autocompletado y carátulas de *cualquier* juego, incluidos los de N64/Switch. Sin ella se usa la tienda de Steam (solo juegos de PC).
- **youtube_key** → Google Cloud Console → crear proyecto → habilitar *YouTube Data API v3* → Credenciales → Clave de API. Con ella, al elegir el juego empieza a sonar su OST y en la tarjeta del tema favorito puedes buscar y escuchar pistas. Sin ella, pegas tú el enlace.

Si las búsquedas fallan en Windows, revisa que en `php.ini` esté `curl.cainfo` apuntando a `C:\xampp\php\extras\ssl\cacert.pem`.

## Usuarios

- Cada persona tiene su cuenta y su fichero `data/u_<usuario>.json`. Las cuentas nuevas empiezan vacías.
- Estando dentro, todos ven a todos: columna «La pandilla» a la izquierda. Pinchando un juego de otro se ve su ficha (nota, horas, tema, comentario).
- El interruptor «Lista pública» de tu cuenta sólo sirve para enseñarla a gente SIN cuenta con el enlace `?u=usuario`.
- Pon un `invite_code` en `config.php` si quieres que nadie más pueda registrarse.
- En la ficha de un juego de otro colega, a la derecha, puedes **comentarle** y **recomendarle el tema** de la banda sonora que te parece mejor. En tus propios juegos ves lo que te han dicho, puedes contestar y, con ⭐, usar un tema recomendado como el tuyo. Las tarjetas muestran 💬 y 🎶 cuando hay algo. Se guarda en `data/social.json`.
- `data/` está bloqueada desde el navegador (.htaccess). Las carátulas se guardan en `covers/`.

## Copia de pruebas

Para probar cambios sin tocar la app de verdad: copia la app en una subcarpeta (p. ej. `creditos/dev/`) con su propia `data/` y pon `'session_name' => 'creditos_dev'` en su `config.php`, para que las sesiones no se mezclen.

## Fuentes externas

- RAWG: autocompletado e imagen apaisada. HowLongToBeat: carátula vertical y las 3 duraciones (no tiene API oficial; si un día deja de ir, `api.php?action=diag` lo dirá).
- YouTube: la banda sonora sale de listas de reproducción (pista a pista). Cada juego nuevo gasta ~102 unidades de las 10.000 diarias gratis; se cachea 30 días en `data/cache/`.

## Datos

- Cada guardado deja la versión anterior del usuario en `data/u_<usuario>.json.bak`.
- Si el servidor no responde, sale un aviso rojo y los cambios quedan solo en el navegador → usa *Exportar JSON*.
- Si una carátula no acierta: pincha el juego → *Buscar otra imagen*.
- Truco: en la tarjeta de nota, si ya tiene 5 estrellas y vuelves a pinchar la quinta, sube a 6 (como Expedition 33).

## Licencia

Dominio público ([The Unlicense](LICENSE)): haz lo que quieras con este código, sin pedir permiso ni citar a nadie.

Los datos e imágenes que la app obtiene de servicios externos (RAWG, HowLongToBeat y YouTube) siguen sujetos a las condiciones de cada servicio.
