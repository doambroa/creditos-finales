<?php
// Créditos Finales — backend multiusuario para XAMPP
// Cuentas: config, me, register, login, logout, password, share
// Datos:   load, save, users (colegas), view (lista de un colega), public (enlace sin sesión)
// Fuera:   search (RAWG/Steam), hltb (HowLongToBeat), ost / ostlist / yt (YouTube), diag
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 90, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 90);
$cfg    = file_exists(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
// una copia de pruebas en el mismo servidor usa otro nombre de sesión para no mezclarse con la de verdad
session_name(preg_replace('/[^A-Za-z0-9_]/', '', $cfg['session_name'] ?? 'creditos') ?: 'creditos');
session_start();

$DIR    = __DIR__ . '/data';
$CACHE  = $DIR . '/cache';
$COVERS = __DIR__ . '/covers';
$USERS  = $DIR . '/users.json';
$SOCIAL = $DIR . '/social.json';   // comentarios y temas recomendados por los colegas
$action = $_GET['action'] ?? '';
if (!function_exists('str_starts_with')) { function str_starts_with($h, $n) { return strncmp($h, $n, strlen($n)) === 0; } }
if (!function_exists('str_contains'))    { function str_contains($h, $n) { return $n === '' || strpos($h, $n) !== false; } }
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

function out($d, $code = 200) {
  http_response_code($code);
  echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function body() { $j = json_decode(file_get_contents('php://input'), true); return is_array($j) ? $j : []; }

/* ---------- almacenamiento ---------- */
function ensureDir() {
  global $DIR, $CACHE, $COVERS;
  foreach ([$DIR, $CACHE, $COVERS] as $d) if (!is_dir($d)) @mkdir($d, 0775, true);
  if (!file_exists("$DIR/.htaccess")) @file_put_contents("$DIR/.htaccess", "Require all denied\nDeny from all\n");
  if (!file_exists("$DIR/index.html")) @file_put_contents("$DIR/index.html", '');
}
function readJson($f, $def) { if (!file_exists($f)) return $def; $j = json_decode(file_get_contents($f), true); return is_array($j) ? $j : $def; }
function writeJson($f, $d) {
  return file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}
function users() { global $USERS; return readJson($USERS, []); }
function saveUsers($u) { global $USERS; ensureDir(); return writeJson($USERS, $u); }
function userFile($u) { global $DIR; return "$DIR/u_$u.json"; }
function emptyDb() { return ['version' => 1, 'years' => new stdClass()]; }
function validName($u) { return (bool)preg_match('/^[a-z0-9_-]{3,20}$/', $u); }
function display($name, $info) { return $info['display'] ?? $name; }

function me() { return $_SESSION['user'] ?? null; }
function requireUser() {
  $u = me();
  if (!$u || !isset(users()[$u])) out(['error' => 'No has iniciado sesión'], 401);
  // suelta el bloqueo de sesión: si no, PHP atiende de una en una las peticiones
  // del mismo usuario (HLTB y la OST se esperaban la una a la otra)
  session_write_close();
  return $u;
}
function meInfo($name, $info) { return ['user' => $name, 'display' => display($name, $info), 'public' => !empty($info['public'])]; }

// caché en disco para no gastar cuota de APIs externas
function cacheGet($key, $ttl) {
  global $CACHE; $f = "$CACHE/" . md5($key) . '.json';
  if (!file_exists($f) || filemtime($f) < time() - $ttl) return null;
  return json_decode(file_get_contents($f), true);
}
function cachePut($key, $val) { global $CACHE; ensureDir(); writeJson("$CACHE/" . md5($key) . '.json', $val); }

function norm($s) {
  $s = mb_strtolower((string)$s, 'UTF-8');
  $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','’'=>"'",'é'=>'e']);
  $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
  $R = ['ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5', 'vi' => '6', 'vii' => '7', 'viii' => '8', 'ix' => '9', 'x' => '10'];
  $w = array_map(fn($x) => $R[$x] ?? $x, preg_split('/\s+/', trim($s)));
  return implode(' ', array_filter($w, fn($x) => $x !== ''));
}

/* ---------- peticiones externas ---------- */
function caBundle() {
  $c = [ini_get('curl.cainfo'), ini_get('openssl.cafile'), __DIR__ . '/cacert.pem',
        dirname(PHP_BINARY) . '/extras/ssl/cacert.pem', 'C:/xampp/php/extras/ssl/cacert.pem', 'C:/xampp/apache/bin/curl-ca-bundle.crt'];
  foreach ($c as $p) if ($p && @is_file($p)) return $p;
  return null;
}
$LAST_ERR = null;
// devuelve [código, cuerpo, error]
function http($url, $post = null, $headers = []) {
  $ca = caBundle(); $body = false; $code = 0; $err = '';
  $headers = array_merge(['User-Agent: ' . UA], $headers);
  if ($post !== null) $headers[] = 'Content-Type: application/json';
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => ''];
    if ($ca) $opt[CURLOPT_CAINFO] = $ca;
    if ($post !== null) { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = $post; }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
  } else {
    $http = ['timeout' => 15, 'ignore_errors' => true, 'header' => implode("\r\n", $headers) . "\r\n"];
    if ($post !== null) { $http['method'] = 'POST'; $http['content'] = $post; }
    $body = @file_get_contents($url, false, stream_context_create(['http' => $http, 'ssl' => $ca ? ['cafile' => $ca] : []]));
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
    if ($body === false) $err = error_get_last()['message'] ?? 'falló la petición';
  }
  return [$code, $body, $err];
}
function fetchJson($url) {
  global $LAST_ERR;
  [$code, $body, $err] = http($url);
  if ($body === false || $code >= 400) {
    $detail = $err ?: "HTTP $code";
    if ($body) { $j = json_decode($body, true); if (isset($j['error']['message'])) $detail .= ' — ' . $j['error']['message']; elseif (isset($j['error'])) $detail .= ' — ' . (is_string($j['error']) ? $j['error'] : json_encode($j['error'])); }
    $LAST_ERR = $detail;
    return ['__error' => $detail];
  }
  return json_decode($body, true);
}

/* ---------- HowLongToBeat (sin API oficial: igual que GameVault, imitando su web) ---------- */
// el token puede venir con una pareja "honeypot" (clave/valor) que hay que devolver en cabeceras y cuerpo
function hltbInit() {
  [$c, $b] = http('https://howlongtobeat.com/api/search/site/init?t=' . round(microtime(true) * 1000), null,
    ['Referer: https://howlongtobeat.com/', 'Origin: https://howlongtobeat.com', 'Accept: */*']);
  $j = json_decode((string)$b, true);
  if (empty($j['token'])) return null;
  $t = ['token' => $j['token'], 'hpKey' => null, 'hpVal' => null];
  foreach ($j as $k => $v) {
    if ($k === 'token') continue;
    if (stripos($k, 'key') !== false) $t['hpKey'] = $v; elseif (stripos($k, 'val') !== false) $t['hpVal'] = $v;
  }
  return $t;
}
function hltbSearch($q) {
  static $tok = null;
  $payload = ['searchType' => 'games', 'searchTerms' => preg_split('/\s+/', trim($q)), 'searchPage' => 1, 'size' => 20,
    'searchOptions' => ['games' => ['userId' => 0, 'platform' => '', 'sortCategory' => 'popular', 'rangeCategory' => 'main',
      'rangeTime' => ['min' => 0, 'max' => 0], 'gameplay' => ['perspective' => '', 'flow' => '', 'genre' => '', 'difficulty' => ''],
      'rangeYear' => ['min' => '', 'max' => ''], 'modifier' => ''],
      'users' => ['sortCategory' => 'postcount'], 'lists' => ['sortCategory' => 'follows'], 'filter' => '', 'sort' => 0, 'randomizer' => 0],
    'useCache' => true];
  $last = 'sin respuesta';
  for ($i = 0; $i < 3; $i++) {
    if (!$tok || $i > 0) $tok = hltbInit();
    if (!$tok) { $last = 'HLTB no dio token'; continue; }
    $h = ['Referer: https://howlongtobeat.com/', 'Origin: https://howlongtobeat.com', 'Accept: */*', 'x-auth-token: ' . $tok['token']];
    $body = $payload;
    if ($tok['hpKey']) { $h[] = 'x-hp-key: ' . $tok['hpKey']; $h[] = 'x-hp-val: ' . $tok['hpVal']; $body[$tok['hpKey']] = $tok['hpVal']; }
    [$c, $b] = http('https://howlongtobeat.com/api/search/site', json_encode($body), $h);
    $j = json_decode((string)$b, true);
    if ($c === 200 && isset($j['data']) && is_array($j['data'])) return $j['data'];
    $last = "HTTP $c";
    if ($c === 429) usleep(1500000);
  }
  return ['__error' => "HowLongToBeat: $last"];
}
// variantes del nombre: completo, sin subtítulo, sin paréntesis, sin "Remastered/Deluxe/…"
function hltbVariants($name) {
  $v = [$name];
  $v[] = preg_replace('/\s*\([^)]*\)\s*/', ' ', $name);
  $v[] = preg_replace('/\s*[:\-–—]\s.*$/u', '', $name);
  $v[] = preg_replace('/\b(remastered|remaster|remake|deluxe|definitive edition|game of the year edition|goty edition|hd|complete edition|director\'?s cut)\b/i', '', $name);
  $v = array_map(fn($x) => trim(preg_replace('/\s+/', ' ', $x)), $v);
  return array_values(array_unique(array_filter($v, fn($x) => mb_strlen($x) >= 2)));
}
function hltbSim($a, $b) {
  $a = norm($a); $b = norm($b);
  if ($a === '' || $b === '') return 0;
  if ($a === $b) return 200;
  similar_text($a, $b, $pct);
  if (str_starts_with($b, $a) || str_starts_with($a, $b)) {
    $extra = trim(str_starts_with($b, $a) ? substr($b, strlen($a)) : substr($a, strlen($b)));
    $pct += preg_match('/^\d+$/', $extra) ? -40 : 10;     // "Baldur's Gate" no es "Baldur's Gate 3"
  }
  return $pct;
}
function hltbPick($list, $names, $year) {
  $best = null; $bestScore = -999;
  foreach ($list as $i => $g) {
    $cands = array_merge([$g['game_name']], array_filter(array_map('trim', explode(',', (string)($g['game_alias'] ?? '')))));
    $s = 0;
    foreach ($names as $n) foreach ($cands as $c) $s = max($s, hltbSim($n, $c));
    if (($g['game_type'] ?? 'game') !== 'game') $s -= 30;
    $ry = (int)($g['release_world'] ?? 0);
    if ($year && $ry) { $d = abs($ry - (int)$year); $s += $d <= 1 ? 15 : ($d > 3 ? -25 : 0); }
    if (!empty($g['comp_main']) || !empty($g['comp_plus'])) $s += 5;
    $s -= $i;
    if ($s > $bestScore) { $bestScore = $s; $best = $g; }
  }
  return $bestScore >= 80 ? $best : null;                   // mejor nada que un juego equivocado
}
function hrs($s) { return $s ? round($s / 3600 * 2) / 2 : null; }   // a medias horas
function coverLocal($img, $id) {
  if (!$img) return null;
  $rel = 'covers/hltb_' . (int)$id . '.jpg';
  $abs = __DIR__ . '/' . $rel;
  if (!file_exists($abs)) {
    ensureDir();
    [$c, $b] = http('https://howlongtobeat.com/games/' . rawurlencode($img) . '?width=400', null, ['Referer: https://howlongtobeat.com/']);
    if ($c !== 200 || !$b) return 'https://howlongtobeat.com/games/' . rawurlencode($img) . '?width=400';
    file_put_contents($abs, $b);
  }
  return $rel;
}
function hltb($q, $year = null) {
  $key = 'hltb2:' . norm($q) . ':' . (int)$year;
  if (($c = cacheGet($key, 60 * 60 * 24 * 30)) !== null) return $c;
  $names = hltbVariants($q); $g = null; $err = null;
  foreach (array_slice($names, 0, 3) as $query) {
    $list = hltbSearch($query);
    if (isset($list['__error'])) { $err = $list; continue; }
    if ($g = hltbPick($list, $names, $year)) break;
  }
  if (!$g && $err) return $err;                              // no se cachea un fallo de conexión
  $res = $g ? [
    'id' => $g['game_id'], 'name' => $g['game_name'], 'year' => $g['release_world'] ?? null,
    'main' => hrs($g['comp_main']), 'plus' => hrs($g['comp_plus']), 'full' => hrs($g['comp_100']),
    'cover' => coverLocal($g['game_image'] ?? null, $g['game_id']),
    'url' => 'https://howlongtobeat.com/game/' . $g['game_id'],
  ] : ['none' => true];
  cachePut($key, $res);
  return $res;
}

/* ---------- YouTube: bandas sonoras como listas de reproducción ---------- */
function ytPlaylistItems($id, $maxPages = 3) {
  global $cfg;
  $key = 'ostlist:' . $id;
  if (($c = cacheGet($key, 60 * 60 * 24 * 30)) !== null) return $c;
  if ($maxPages === 1 && ($c = cacheGet("ostlist1:$id", 60 * 60 * 24 * 30)) !== null) return $c;
  $tracks = []; $page = null; $n = 0;
  do {
    $j = fetchJson('https://www.googleapis.com/youtube/v3/playlistItems?' . http_build_query(array_filter([
      'part' => 'snippet,contentDetails', 'playlistId' => $id, 'maxResults' => 50, 'pageToken' => $page, 'key' => $cfg['youtube_key']])));
    if (isset($j['__error'])) return $j;
    foreach (($j['items'] ?? []) as $it) {
      $t = $it['snippet']['title'] ?? '';
      if ($t === 'Deleted video' || $t === 'Private video') continue;
      $tracks[] = ['videoId' => $it['contentDetails']['videoId'], 'title' => $t,
                   'thumb' => $it['snippet']['thumbnails']['default']['url'] ?? null];
    }
    $page = $j['nextPageToken'] ?? null;
  } while ($page && ++$n < $maxPages);           // hasta 150 pistas
  if ($maxPages === 1 && $page) { cachePut("ostlist1:$id", $tracks); return $tracks; }   // parcial: el resto lo pide el navegador después
  cachePut($key, $tracks);
  return $tracks;
}
function ostPlaylists($game) {
  global $cfg;
  $key = 'ost:' . norm($game);
  if (($c = cacheGet($key, 60 * 60 * 24 * 30)) !== null) return $c;
  $j = fetchJson('https://www.googleapis.com/youtube/v3/search?' . http_build_query([
    'part' => 'snippet', 'type' => 'playlist', 'maxResults' => 10, 'q' => "$game soundtrack", 'key' => $cfg['youtube_key']]));
  if (isset($j['__error'])) return $j;
  $words = array_filter(explode(' ', norm(preg_replace('/[:\-–].*$/u', '', $game))), fn($w) => strlen($w) > 1 && !in_array($w, ['the', 'of', 'and', 'el', 'la', 'de']));
  $res = [];
  foreach (($j['items'] ?? []) as $i => $it) {
    $t = html_entity_decode($it['snippet']['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $nt = norm($t); $hit = 0;
    foreach ($words as $w) if (str_contains($nt, $w)) $hit++;
    $score = ($words ? $hit / count($words) : 1) * 100 - $i * 3;
    if (preg_match('/\b(ost|soundtrack|original|music|bso|banda sonora|complete|full)\b/', $nt)) $score += 20;
    if (preg_match('/\b(remix|cover|piano|lofi|lo fi|reaction|guitar|metal|orchestra|orchestral|extended|10 hours|1 hour|ambience|playthrough|walkthrough|gameplay|bangers|best of|top)\b/', $nt)) $score -= 35;
    $ng = norm($game);
    foreach (['dlc', 'mea culpa', 'remastered', 'remake'] as $w) if (str_contains($nt, $w) && !str_contains($ng, $w)) $score -= 25;
    $res[] = ['id' => $it['id']['playlistId'], 'title' => $t,
              'channel' => html_entity_decode($it['snippet']['channelTitle'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'score' => $score];
  }
  usort($res, fn($a, $b) => $b['score'] <=> $a['score']);
  $res = array_values(array_filter($res, fn($p) => $p['score'] > 40));
  cachePut($key, $res);
  return $res;
}

/* ---------- comentarios y temas recomendados (data/social.json) ---------- */
// clave: "<dueño>/<id del juego>" → ['comments' => [...], 'recs' => [...]]
function socialTx(callable $fn) {
  global $SOCIAL; ensureDir();
  $fp = fopen($SOCIAL, 'c+');
  if (!$fp) out(['error' => 'No se pudo abrir social.json'], 500);
  flock($fp, LOCK_EX);
  $db = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
  $res = $fn($db);
  if ($res !== false) {
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
  }
  flock($fp, LOCK_UN); fclose($fp);
  return $res;
}
function socialRead() {
  global $SOCIAL;
  if (!file_exists($SOCIAL)) return [];
  $fp = fopen($SOCIAL, 'r'); flock($fp, LOCK_SH);
  $db = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
  flock($fp, LOCK_UN); fclose($fp);
  return $db;
}
// el juego tiene que existir en la lista de su dueño
function findGame($owner, $gid) {
  if (!validName($owner) || !isset(users()[$owner])) out(['error' => 'Ese usuario no existe'], 404);
  $db = readJson(userFile($owner), ['years' => []]);
  foreach (($db['years'] ?? []) as $y) foreach (($y['games'] ?? []) as $g) if (($g['id'] ?? null) === $gid) return $g;
  out(['error' => 'Ese juego ya no está en su lista'], 404);
}
function socialKey($owner, $gid) { return $owner . '/' . $gid; }
function socialView($entry) { return ['comments' => array_values($entry['comments'] ?? []), 'recs' => array_values($entry['recs'] ?? [])]; }

/* ---------- resúmenes de colegas ---------- */
function summary($name, $info) {
  $db = readJson(userFile($name), ['years' => []]);
  $years = []; $all = []; $last = null;
  foreach (($db['years'] ?? []) as $y => $v) {
    $gs = $v['games'] ?? [];
    $years[$y] = count($gs);
    foreach ($gs as $g) { $g['_y'] = $y; $all[] = $g; }
  }
  usort($all, fn($a, $b) => strcmp(($b['date'] ?? $b['_y'] . '-00'), ($a['date'] ?? $a['_y'] . '-00')));
  $last = $all[0] ?? null;
  $rated = array_filter($all, fn($g) => isset($g['rating']) && $g['rating'] !== null);
  return [
    'user' => $name, 'display' => display($name, $info), 'years' => (object)$years, 'total' => count($all),
    'hours' => array_sum(array_map(fn($g) => (float)($g['hours'] ?? 0), $all)),
    'avg' => $rated ? round(array_sum(array_map(fn($g) => min(5, $g['rating']), $rated)) / count($rated), 1) : null,
    'last' => $last ? ['title' => $last['title'], 'cover' => $last['cover'] ?? ($last['image'] ?? null), 'rating' => $last['rating'] ?? null] : null,
    'updated' => file_exists(userFile($name)) ? filemtime(userFile($name)) : 0,
  ];
}

switch ($action) {

  case 'diag':
    ensureDir();
    $r = [
      'php' => PHP_VERSION,
      'config.php' => file_exists(__DIR__ . '/config.php') ? 'encontrado' : 'NO ENCONTRADO',
      'rawg_key' => empty($cfg['rawg_key']) ? 'vacía' : 'puesta',
      'youtube_key' => empty($cfg['youtube_key']) ? 'vacía' : 'puesta',
      'invite_code' => empty($cfg['invite_code']) ? 'VACÍO (cualquiera puede registrarse)' : 'puesto',
      'curl' => function_exists('curl_init') ? 'activada' : 'DESACTIVADA (se usa file_get_contents)',
      'openssl' => extension_loaded('openssl') ? 'activada' : 'DESACTIVADA',
      'certificados' => caBundle() ?: 'NO ENCONTRADOS',
      'carpeta data/' => is_writable($DIR) ? 'escribible' : 'NO ESCRIBIBLE',
      'carpeta covers/' => is_writable($COVERS) ? 'escribible' : 'NO ESCRIBIBLE',
      'usuarios' => count(users()),
    ];
    $t = fetchJson('https://store.steampowered.com/api/storesearch/?term=valheim&cc=es');
    $r['prueba_steam'] = isset($t['__error']) ? 'ERROR: ' . $t['__error'] : 'OK';
    if (!empty($cfg['rawg_key'])) {
      $t = fetchJson('https://api.rawg.io/api/games?' . http_build_query(['key' => $cfg['rawg_key'], 'search' => 'valheim', 'page_size' => 1]));
      $r['prueba_rawg'] = isset($t['__error']) ? 'ERROR: ' . $t['__error'] : 'OK';
    }
    if (!empty($cfg['youtube_key'])) {
      $t = fetchJson('https://www.googleapis.com/youtube/v3/search?' . http_build_query(['part' => 'snippet', 'type' => 'video', 'maxResults' => 1, 'q' => 'valheim ost', 'key' => $cfg['youtube_key']]));
      $r['prueba_youtube'] = isset($t['__error']) ? 'ERROR: ' . $t['__error'] : 'OK';
    }
    $t = hltbSearch('outer wilds');
    $r['prueba_hltb'] = isset($t['__error']) ? 'ERROR: ' . $t['__error'] : 'OK (' . count($t) . ' resultados)';
    if (!empty($_GET['q'])) { $h = hltb($_GET['q'], $_GET['year'] ?? null); $r['hltb_' . $_GET['q']] = $h; }
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;

  /* ---------- cuentas ---------- */
  case 'config':
    out(['rawg' => !empty($cfg['rawg_key']), 'youtube' => !empty($cfg['youtube_key']), 'needsInvite' => !empty($cfg['invite_code'])]);

  case 'me':
    $u = me(); session_write_close(); $all = users();
    if (!$u || !isset($all[$u])) out(['user' => null]);
    out(meInfo($u, $all[$u]));

  case 'register':
    $b = body();
    $disp = trim($b['user'] ?? ''); $u = strtolower($disp); $p = (string)($b['pass'] ?? '');
    if (!validName($u)) out(['error' => 'Usuario: de 3 a 20 caracteres, solo letras, números, - y _'], 400);
    if (strlen($p) < 6) out(['error' => 'La contraseña debe tener al menos 6 caracteres'], 400);
    if (!empty($cfg['invite_code']) && !hash_equals((string)$cfg['invite_code'], trim((string)($b['invite'] ?? '')))) {
      sleep(1); out(['error' => 'Código de invitación incorrecto'], 403);
    }
    ensureDir();
    $all = users();
    if (isset($all[$u])) out(['error' => 'Ese usuario ya existe'], 409);
    $all[$u] = ['hash' => password_hash($p, PASSWORD_DEFAULT), 'display' => $disp, 'created' => date('c'), 'public' => false];
    if (!saveUsers($all)) out(['error' => 'No se pudo escribir en data/ (¿permisos?)'], 500);
    // cuenta nueva = lista vacía, siempre
    if (!file_exists(userFile($u))) writeJson(userFile($u), emptyDb());
    session_regenerate_id(true);
    $_SESSION['user'] = $u;
    out(meInfo($u, $all[$u]));

  case 'login':
    $b = body();
    $u = strtolower(trim($b['user'] ?? '')); $p = (string)($b['pass'] ?? '');
    $all = users();
    if (!isset($all[$u]) || !password_verify($p, $all[$u]['hash'])) { sleep(1); out(['error' => 'Usuario o contraseña incorrectos'], 401); }
    if (password_needs_rehash($all[$u]['hash'], PASSWORD_DEFAULT)) { $all[$u]['hash'] = password_hash($p, PASSWORD_DEFAULT); saveUsers($all); }
    session_regenerate_id(true);
    $_SESSION['user'] = $u;
    out(meInfo($u, $all[$u]));

  case 'logout':
    $_SESSION = [];
    session_destroy();
    out(['ok' => true]);

  case 'password':
    $u = requireUser(); $b = body(); $all = users();
    if (!password_verify((string)($b['old'] ?? ''), $all[$u]['hash'])) { sleep(1); out(['error' => 'La contraseña actual no es correcta'], 403); }
    if (strlen((string)($b['new'] ?? '')) < 6) out(['error' => 'La nueva contraseña debe tener al menos 6 caracteres'], 400);
    $all[$u]['hash'] = password_hash($b['new'], PASSWORD_DEFAULT);
    saveUsers($all);
    out(['ok' => true]);

  case 'share':
    $u = requireUser(); $b = body(); $all = users();
    $all[$u]['public'] = !empty($b['public']);
    saveUsers($all);
    out(meInfo($u, $all[$u]));

  /* ---------- datos ---------- */
  case 'load':
    $u = requireUser();
    out(readJson(userFile($u), null) ?: emptyDb());

  case 'save':
    $u = requireUser();
    $j = body();
    if (!isset($j['years'])) out(['error' => 'JSON no válido'], 400);
    ensureDir();
    $f = userFile($u);
    if (file_exists($f)) @copy($f, "$f.bak");
    if (!writeJson($f, $j)) out(['error' => 'No se pudo escribir (¿permisos de data/?)'], 500);
    out(['ok' => true, 'savedAt' => date('c')]);

  case 'users':   // la pandilla: todos ven a todos estando dentro
    requireUser();
    $res = [];
    foreach (users() as $name => $info) $res[] = summary($name, $info);
    usort($res, fn($a, $b) => $b['updated'] <=> $a['updated']);
    out($res);

  /* ---------- comentarios y temas recomendados ---------- */
  case 'social':          // GET owner, id
    requireUser();
    $owner = strtolower(trim($_GET['owner'] ?? '')); $gid = (string)($_GET['id'] ?? '');
    findGame($owner, $gid);
    out(socialView(socialRead()[socialKey($owner, $gid)] ?? []));

  case 'social_summary':  // GET owner → { idJuego: {c, r} }
    requireUser();
    $owner = strtolower(trim($_GET['owner'] ?? ''));
    $res = new stdClass();
    foreach (socialRead() as $k => $e) {
      if (strpos($k, $owner . '/') !== 0) continue;
      $c = count($e['comments'] ?? []); $r = count($e['recs'] ?? []);
      if ($c || $r) $res->{substr($k, strlen($owner) + 1)} = ['c' => $c, 'r' => $r];
    }
    out($res);

  case 'comment':         // POST {owner, id, text}
    $me = requireUser(); $b = body(); $all = users();
    $owner = strtolower(trim($b['owner'] ?? '')); $gid = (string)($b['id'] ?? '');
    $text = trim(preg_replace('/\r\n?/', "\n", (string)($b['text'] ?? '')));
    if ($text === '') out(['error' => 'El comentario está vacío'], 400);
    if (mb_strlen($text) > 1000) out(['error' => 'Máximo 1000 caracteres'], 400);
    findGame($owner, $gid);
    $k = socialKey($owner, $gid); $view = null;
    socialTx(function (&$db) use ($k, $me, $all, $text, &$view) {
      $db[$k]['comments'][] = ['id' => bin2hex(random_bytes(6)), 'user' => $me, 'display' => display($me, $all[$me]), 'text' => $text, 'ts' => date('c')];
      $db[$k]['recs'] ??= [];
      $view = socialView($db[$k]);
    });
    out($view);

  case 'comment_del':     // POST {owner, id, cid}: lo borra quien lo escribió o el dueño del juego
    $me = requireUser(); $b = body();
    $owner = strtolower(trim($b['owner'] ?? '')); $gid = (string)($b['id'] ?? ''); $cid = (string)($b['cid'] ?? '');
    $k = socialKey($owner, $gid); $view = null; $err = null;
    socialTx(function (&$db) use ($k, $me, $owner, $cid, &$view, &$err) {
      $list = $db[$k]['comments'] ?? [];
      foreach ($list as $i => $c) if ($c['id'] === $cid) {
        if ($c['user'] !== $me && $owner !== $me) { $err = 'Solo puede borrarlo quien lo escribió o el dueño del juego'; return false; }
        array_splice($list, $i, 1); $db[$k]['comments'] = $list; $view = socialView($db[$k]); return true;
      }
      $view = socialView($db[$k] ?? []); return false;
    });
    if ($err) out(['error' => $err], 403);
    out($view);

  case 'rec':             // POST {owner, id, title, videoId}: un tema recomendado por colega y juego
    $me = requireUser(); $b = body(); $all = users();
    $owner = strtolower(trim($b['owner'] ?? '')); $gid = (string)($b['id'] ?? '');
    if ($owner === $me) out(['error' => 'Tus propios juegos no se recomiendan a ti mismo 😉'], 400);
    $title = trim((string)($b['title'] ?? '')); $vid = (string)($b['videoId'] ?? '');
    if ($title === '' || mb_strlen($title) > 200) out(['error' => 'Falta el nombre del tema'], 400);
    if ($vid !== '' && !preg_match('/^[\w-]{11}$/', $vid)) out(['error' => 'Enlace de YouTube no válido'], 400);
    findGame($owner, $gid);
    $k = socialKey($owner, $gid); $view = null;
    socialTx(function (&$db) use ($k, $me, $all, $title, $vid, &$view) {
      $recs = array_values(array_filter($db[$k]['recs'] ?? [], fn($r) => $r['user'] !== $me));
      $recs[] = ['user' => $me, 'display' => display($me, $all[$me]), 'title' => $title, 'videoId' => $vid ?: null, 'ts' => date('c')];
      $db[$k]['recs'] = $recs; $db[$k]['comments'] ??= [];
      $view = socialView($db[$k]);
    });
    out($view);

  case 'rec_del':         // POST {owner, id, user}: la quita quien la hizo o el dueño del juego
    $me = requireUser(); $b = body();
    $owner = strtolower(trim($b['owner'] ?? '')); $gid = (string)($b['id'] ?? ''); $who = strtolower((string)($b['user'] ?? $me));
    if ($who !== $me && $owner !== $me) out(['error' => 'No puedes quitar la recomendación de otro'], 403);
    $k = socialKey($owner, $gid); $view = null;
    socialTx(function (&$db) use ($k, $who, &$view) {
      $db[$k]['recs'] = array_values(array_filter($db[$k]['recs'] ?? [], fn($r) => $r['user'] !== $who));
      $db[$k]['comments'] ??= [];
      $view = socialView($db[$k]);
    });
    out($view);

  case 'view':
    requireUser();
    $u = strtolower(trim($_GET['u'] ?? '')); $all = users();
    if (!isset($all[$u])) out(['error' => 'Ese usuario no existe'], 404);
    out(['user' => $u, 'display' => display($u, $all[$u]), 'db' => readJson(userFile($u), null) ?: emptyDb()]);

  case 'public':   // enlace para gente sin cuenta, solo si el dueño lo activa
    $u = strtolower(trim($_GET['u'] ?? '')); $all = users();
    $logged = me() && isset($all[me()]);
    if (!validName($u) || !isset($all[$u]) || (empty($all[$u]['public']) && !$logged)) out(['error' => 'Esta lista no existe o es privada'], 404);
    out(['user' => $u, 'display' => display($u, $all[$u]), 'db' => readJson(userFile($u), null) ?: emptyDb()]);

  /* ---------- búsquedas (solo con sesión, para no gastar tu cuota) ---------- */
  case 'search':
    requireUser();
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) out([]);
    $res = [];
    if (!empty($cfg['rawg_key'])) {
      $j = fetchJson('https://api.rawg.io/api/games?' . http_build_query(['key' => $cfg['rawg_key'], 'search' => $q, 'page_size' => 8, 'search_precise' => 'true']));
      foreach (($j['results'] ?? []) as $g) {
        $res[] = [
          'id' => 'rawg:' . $g['id'], 'name' => $g['name'], 'image' => $g['background_image'] ?? null,
          'year' => isset($g['released']) ? substr($g['released'], 0, 4) : null,
          'platforms' => array_slice(array_map(fn($p) => $p['platform']['name'], $g['platforms'] ?? []), 0, 4),
        ];
      }
    }
    if (!$res) {
      $j = fetchJson('https://store.steampowered.com/api/storesearch/?' . http_build_query(['term' => $q, 'cc' => 'es', 'l' => 'spanish']));
      foreach (array_slice($j['items'] ?? [], 0, 8) as $g) {
        $res[] = ['id' => 'steam:' . $g['id'], 'name' => $g['name'],
                  'image' => 'https://cdn.cloudflare.steamstatic.com/steam/apps/' . $g['id'] . '/header.jpg', 'year' => null, 'platforms' => ['Steam']];
      }
    }
    if (!$res && $LAST_ERR) out(['error' => 'Búsqueda fallida: ' . $LAST_ERR . ' · abre api.php?action=diag'], 502);
    out($res);

  case 'hltb':
    requireUser();
    $q = trim($_GET['q'] ?? '');
    if ($q === '') out(['none' => true]);
    $r = hltb($q, preg_match('/^\d{4}$/', $_GET['year'] ?? '') ? (int)$_GET['year'] : null);
    if (isset($r['__error'])) out(['error' => $r['__error']], 502);
    out($r);

  case 'ost':      // listas de reproducción candidatas + pistas de la mejor
    requireUser();
    if (empty($cfg['youtube_key'])) out(['error' => 'Sin clave de YouTube'], 400);
    $q = trim($_GET['q'] ?? '');
    if ($q === '') out(['playlists' => [], 'tracks' => []]);
    $pls = ostPlaylists($q);
    if (isset($pls['__error'])) out(['error' => $pls['__error']], 502);
    $tracks = $pls ? ytPlaylistItems($pls[0]['id'], 1) : [];   // solo la 1ª página: que suene cuanto antes
    if (isset($tracks['__error'])) $tracks = [];
    out(['playlists' => $pls, 'tracks' => $tracks, 'partial' => count($tracks) >= 50]);

  case 'ostlist':
    requireUser();
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['id'] ?? '');
    $t = ytPlaylistItems($id);
    if (isset($t['__error'])) out(['error' => $t['__error']], 502);
    out($t);

  case 'yt':
    requireUser();
    $q = trim($_GET['q'] ?? '');
    if (empty($cfg['youtube_key'])) out(['error' => 'Sin clave de YouTube'], 400);
    if ($q === '') out([]);
    $j = fetchJson('https://www.googleapis.com/youtube/v3/search?' . http_build_query([
      'part' => 'snippet', 'type' => 'video', 'maxResults' => 15, 'videoEmbeddable' => 'true', 'q' => $q, 'key' => $cfg['youtube_key']]));
    if (isset($j['__error'])) out(['error' => $j['__error']], 502);
    $res = [];
    foreach (($j['items'] ?? []) as $it) {
      $res[] = [
        'videoId' => $it['id']['videoId'],
        'title' => html_entity_decode($it['snippet']['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'channel' => html_entity_decode($it['snippet']['channelTitle'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'thumb' => $it['snippet']['thumbnails']['default']['url'] ?? null,
      ];
    }
    out($res);

  default:
    out(['error' => 'Acción desconocida'], 400);
}
