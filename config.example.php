<?php
// Copia este fichero como config.php y rellena tus claves (gratuitas).
// Sin claves la app funciona con menos magia:
//  - sin rawg_key    -> autocompletado y carátulas desde la tienda de Steam (sin retro de consola)
//  - sin youtube_key -> no suena la OST; se puede pegar un enlace de YouTube a mano
return [
  'rawg_key'    => '',   // https://rawg.io/apidocs
  'youtube_key' => '',   // Google Cloud Console -> YouTube Data API v3 -> Credenciales -> Clave de API
  'invite_code' => '',   // código para poder crear cuenta; vacío = registro abierto
  // 'session_name' => 'creditos_dev',   // solo en una copia de pruebas en el mismo servidor
];
