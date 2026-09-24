<?php
/**
 * lamparo — la lanterne
 * ---------------------------------------------------------------------------
 * Un seul fichier, générique et public, à déposer à la racine web d'un site à surveiller. Identique pour tous
 * les sites : il se commit dans git sans risque, il se met à jour comme n'importe quelle dépendance.
 * Aucune dépendance, aucun framework, PHP 7.4 ou plus (mutualisé compris).
 *
 * LA CLÉ N'EST PAS DANS CE FICHIER. Elle vient de l'environnement de production, dans une variable nommée
 * d'après la clé — LAMPARO_KEY_09887C4F pour la clé k_09887c4f, valeur « identifiant:secret » — définie dans le
 * panneau d'hébergement, un SetEnv Apache, un fastcgi_param nginx ou l'env[] de PHP-FPM ; et/ou dans un fichier
 * nommé de même, lamparo-key-09887c4f.php, posé à côté (exclu de git) :
 *     <?php return 'k_09887c4f:secret';
 * Chaque compte lamparo qui surveille le site a ainsi sa variable ou son fichier, sans toucher à ceux des autres
 * (un client et son agence, chacun payant sa ligne). LAMPARO_KEY et lamparo-key.php, sans suffixe, restent lus.
 * Les fichiers de clé sont lus comme du texte, jamais exécutés ; demandés depuis le web, ils ne renvoient rien.
 * Chaque requête dit quelle clé elle porte (X-Lamparo-Key-Id) ; la lanterne répond avec celle-là, ou pas du tout.
 * Sans clé, la lanterne répond 404 : une préproduction ou une copie ne parle jamais.
 *
 * PRINCIPES NON NÉGOCIABLES (CLAUDE.md §2) :
 *   1. LECTURE SEULE ABSOLUE — n'écrit jamais, ne modifie jamais rien.
 *   2. AUCUNE EXÉCUTION DYNAMIQUE — pas d'eval, pas d'include, pas d'exec, aucun paramètre de requête
 *      interprété comme un chemin.
 *   3. LISTE BLANCHE — ne collecte que les faits énumérés dans lamparo_collect().
 *   4. MUETTE SANS SIGNATURE — toute requête invalide reçoit un 404 vide.
 *
 * @version 0.10.1
 * @license MIT — lamp (lamp-ic.fr). Publiée sur packagist : composer require lamparo/lantern
 */

declare(strict_types=1);

define('LAMPARO_PROBE_VERSION', '0.10.1');

/** Tolérance d'horloge, en secondes. */
define('LAMPARO_MAX_SKEW', 300);

/**
 * Plafonds de collecte. Ils bornent ce qu'on RENVOIE, ce qui est peu de chose : mesuré sur un composer.lock de
 * 161 paquets, lire et décoder le fichier coûte 2 Mo, soit environ 12 Ko par paquet. Deux mille paquets tiennent
 * donc dans quelques dizaines de méga-octets, loin de la limite d'un hébergement mutualisé.
 *
 * La vraie dépense est ailleurs : le fichier est lu puis décodé ENTIÈREMENT avant que ces plafonds ne s'appliquent.
 * C'est donc la taille du fichier qu'il faut borner, et c'est ce que fait LAMPARO_MAX_LOCK_BYTES.
 */
define('LAMPARO_MAX_PACKAGES', 2000);
define('LAMPARO_MAX_COMPONENTS', 500);
define('LAMPARO_MAX_HEADER_BYTES', 8192);
/** Un manifeste d'extension Joomla liste ses fichiers avant de nommer son serveur de mise à jour : on lit plus loin, mais jamais sans borne. */
define('LAMPARO_MAX_MANIFEST_BYTES', 65536);

/**
 * Au-delà, on ne lit pas le composer.lock du tout. Six méga-octets de JSON pèsent environ quatre fois cela une fois
 * décodés : on reste sous les trente méga-octets, ce qu'un hébergement mutualisé accorde sans broncher. La lanterne
 * tourne chez le client : elle n'a pas le droit de faire tomber son site pour se renseigner.
 */
define('LAMPARO_MAX_LOCK_BYTES', 6291456);

/** Plus de clés que ça, ce n'est plus un site partagé : c'est une erreur de configuration. */
define('LAMPARO_MAX_KEYS', 10);

// La lanterne n'a aucun usage en CLI ; les tests la chargent avec LAMPARO_TESTING défini, sans la lancer.
if (PHP_SAPI === 'cli') {
    if (!defined('LAMPARO_TESTING')) {
        exit(0);
    }
} else {
    // Aucun avertissement PHP ne doit sortir sur le web : un chemin dans un message d'erreur serait une fuite.
    @ini_set('display_errors', '0');
    error_reporting(0);
    lamparo_main();
}

// ---------------------------------------------------------------------------
// Entrée
// ---------------------------------------------------------------------------

function lamparo_main(): void
{
    $key = lamparo_key_for_request(lamparo_load_keys());
    if ($key === null || !lamparo_request_is_authentic($key)) {
        lamparo_not_found();
    }

    $errors  = [];
    $root    = lamparo_detect_root();
    $facts   = lamparo_collect($root, $errors);

    lamparo_respond([
        'probe_version' => LAMPARO_PROBE_VERSION,
        'key_id'        => $key['id'],
        'collected_at'  => gmdate('c'),
        'facts'         => $facts,
        'errors'        => $errors,
    ], $key['secret']);
}

// ---------------------------------------------------------------------------
// Les clés : l'environnement et les fichiers à côté, jamais ce fichier. Une variable ou un fichier par compte
// lamparo qui surveille le site ; au plus LAMPARO_MAX_KEYS.
// ---------------------------------------------------------------------------

/**
 * Toutes les clés en place : chaque variable LAMPARO_KEY ou LAMPARO_KEY_xxx de l'environnement (REDIRECT_ compris,
 * qu'Apache ajoute en réécriture), puis chaque fichier lamparo-key*.php à côté ; sans doublon d'identifiant.
 *
 * @return array<int, array{id: string, secret: string}>
 */
function lamparo_load_keys(): array
{
    $raw = [];
    $env = getenv();
    foreach ([is_array($env) ? $env : [], $_SERVER, $_ENV] as $vars) {
        foreach ($vars as $name => $value) {
            if (is_string($name) && is_string($value) && trim($value) !== '' && preg_match('/^(REDIRECT_)?LAMPARO_KEY(_[A-Za-z0-9]+)?$/', $name) === 1) {
                $raw[] = $value;
            }
        }
    }
    $files = glob(__DIR__ . '/lamparo-key*.php');
    foreach (is_array($files) ? array_slice($files, 0, LAMPARO_MAX_KEYS) : [] as $path) {
        $file = lamparo_read_key_file($path);
        if ($file !== null) {
            $raw[] = $file;
        }
    }

    return lamparo_parse_keys(implode(',', $raw));
}

/** La clé que la requête annonce, si elle est en place ; l'identifiant se compare en temps constant, comme le reste. */
function lamparo_key_for_request(array $keys): ?array
{
    $keyId = lamparo_header('X-Lamparo-Key-Id');
    if ($keyId === null) {
        return null;
    }
    foreach ($keys as $key) {
        if (hash_equals($key['id'], $keyId)) {
            return $key;
        }
    }

    return null;
}

/**
 * Le fichier de clé est lu comme du texte : on y prend chaque « identifiant:secret » entre guillemets, sans
 * l'exécuter. Plusieurs chaînes (un tableau) donnent plusieurs clés, rendues séparées par des virgules.
 */
function lamparo_read_key_file(string $path): ?string
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $head = lamparo_read_head($path);
    if (preg_match_all('/[\'"]([A-Za-z0-9_]+:[A-Za-z0-9]+(?:\s*,\s*[A-Za-z0-9_]+:[A-Za-z0-9]+)*)[\'"]/', $head, $matches) > 0) {
        return implode(',', $matches[1]);
    }

    return null;
}

/**
 * Une liste de clés — séparées par des virgules, des points-virgules ou des blancs — sans doublon d'identifiant,
 * les malformées ignorées, au plus LAMPARO_MAX_KEYS.
 *
 * @return array<int, array{id: string, secret: string}>
 */
function lamparo_parse_keys(string $raw): array
{
    $keys = [];
    foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $part) {
        $key = lamparo_parse_key($part);
        if ($key === null || isset($keys[$key['id']])) {
            continue;
        }
        $keys[$key['id']] = $key;
        if (count($keys) >= LAMPARO_MAX_KEYS) {
            break;
        }
    }

    return array_values($keys);
}

/**
 * @return array{id: string, secret: string}|null
 */
function lamparo_parse_key(string $raw): ?array
{
    if (preg_match('/^\s*([A-Za-z0-9_]{4,32}):([A-Za-z0-9]{32,128})\s*$/', $raw, $matches) !== 1) {
        return null;
    }

    return ['id' => $matches[1], 'secret' => $matches[2]];
}

/**
 * Réponse indistinguable d'un fichier inexistant.
 * Aucun message différencié : on ne confirme jamais l'existence de la sonde.
 */
function lamparo_not_found(): void
{
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/html; charset=utf-8');
    exit;
}

/** La réponse est signée avec le même secret : la plateforme sait que c'est bien la lanterne qui parle. */
function lamparo_respond(array $payload, string $secret): void
{
    $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Lamparo-Signature: ' . hash_hmac('sha256', $body, $secret));
    echo $body;
    exit;
}

// ---------------------------------------------------------------------------
// Authentification HMAC
// ---------------------------------------------------------------------------

/**
 * Chaîne canonique signée (séparateur \n) :
 *   GET \n {path} \n {key_id} \n {timestamp} \n {nonce}
 */
/** @param array{id: string, secret: string} $key */
function lamparo_request_is_authentic(array $key): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        return false;
    }

    $keyId     = lamparo_header('X-Lamparo-Key-Id');
    $timestamp = lamparo_header('X-Lamparo-Timestamp');
    $nonce     = lamparo_header('X-Lamparo-Nonce');
    $signature = lamparo_header('X-Lamparo-Signature');

    if ($keyId === null || $timestamp === null || $nonce === null || $signature === null) {
        return false;
    }

    if (!hash_equals($key['id'], $keyId)) {
        return false;
    }

    if (!ctype_digit($timestamp)) {
        return false;
    }

    // Fenêtre temporelle : ±LAMPARO_MAX_SKEW secondes.
    if (abs(time() - (int) $timestamp) > LAMPARO_MAX_SKEW) {
        return false;
    }

    // Nonce : format contrôlé, non stocké (cf. ARCHITECTURE.md §3.1).
    if (!preg_match('/^[A-Za-z0-9]{8,64}$/', $nonce)) {
        return false;
    }

    $path = lamparo_request_path();

    $canonical = implode("\n", [
        'GET',
        $path,
        $keyId,
        $timestamp,
        $nonce,
    ]);

    $expected = hash_hmac('sha256', $canonical, $key['secret']);

    return hash_equals($expected, strtolower($signature));
}

function lamparo_header(string $name): ?string
{
    $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
    if (!isset($_SERVER[$key])) {
        return null;
    }
    $value = trim((string) $_SERVER[$key]);

    return $value === '' ? null : $value;
}

/** Chemin de la requête, sans query string. */
function lamparo_request_path(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $pos = strpos($uri, '?');

    return $pos === false ? $uri : substr($uri, 0, $pos);
}

// ---------------------------------------------------------------------------
// Détection de la racine applicative
// ---------------------------------------------------------------------------

/**
 * La sonde est normalement à la racine web, mais celle-ci peut être un
 * sous-dossier (web/, public/, httpdocs/). On remonte au maximum 4 niveaux
 * en cherchant des marqueurs connus. Aucun chemin ne vient d'une entrée
 * utilisateur : on part toujours de __DIR__.
 */
function lamparo_detect_root(): array
{
    $webRoot = __DIR__;
    $appRoot = $webRoot;

    $current = $webRoot;
    for ($i = 0; $i < 4; $i++) {
        if (is_file($current . '/composer.json') || is_file($current . '/composer.lock')) {
            $appRoot = $current;
            break;
        }
        $parent = dirname($current);
        if ($parent === $current) {
            break;
        }
        $current = $parent;
    }

    return ['web' => $webRoot, 'app' => $appRoot];
}

// ---------------------------------------------------------------------------
// Collecte (LISTE BLANCHE — ne rien ajouter sans mettre à jour ARCHITECTURE.md)
// ---------------------------------------------------------------------------

function lamparo_collect(array $root, array &$errors): array
{
    $facts = [
        'php'        => lamparo_facts_php(),
        'server'     => lamparo_facts_server($root),
        'cms'        => ['type' => 'unknown', 'version' => null, 'detection' => null],
        'packages'   => [],
        'components' => [],
    ];

    // Paquets composer : utile même sans CMS (site sur mesure).
    $facts['packages'] = lamparo_read_composer_lock($root['app'], $errors);

    // Détection CMS, dans l'ordre des marqueurs les plus fiables.
    $detectors = [
        'lamparo_detect_wordpress',
        'lamparo_detect_drupal',
        'lamparo_detect_drupal7',
        'lamparo_detect_prestashop',
        'lamparo_detect_joomla',
        'lamparo_detect_typo3',
        'lamparo_detect_spip',
    ];

    foreach ($detectors as $detector) {
        $result = $detector($root, $errors);
        if ($result !== null) {
            $facts['cms']        = $result['cms'];
            $facts['components'] = $result['components'];
            break;
        }
    }

    return $facts;
}

function lamparo_facts_php(): array
{
    $extensions = get_loaded_extensions();
    sort($extensions);

    return [
        'version'      => PHP_VERSION,
        'sapi'         => PHP_SAPI,
        'extensions'   => $extensions,
        'memory_limit' => (string) ini_get('memory_limit'),
        'opcache'      => function_exists('opcache_get_status'),
    ];
}

function lamparo_facts_server(array $root): array
{
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT'])
        ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/')
        : null;

    return [
        'software'             => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
        'os'                   => PHP_OS_FAMILY,
        'document_root_match'  => $documentRoot !== null && $documentRoot === rtrim($root['web'], '/'),
    ];
}

// ---------------------------------------------------------------------------
// composer.lock
// ---------------------------------------------------------------------------

function lamparo_read_composer_lock(string $appRoot, array &$errors): array
{
    $path = $appRoot . '/composer.lock';
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    // La taille AVANT la lecture : c'est le seul moment où l'on peut encore refuser sans avoir rien dépensé.
    $size = @filesize($path);
    if ($size === false || $size > LAMPARO_MAX_LOCK_BYTES) {
        $errors[] = ['scope' => 'packages', 'reason' => 'composer.lock too large'];

        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        $errors[] = ['scope' => 'packages', 'reason' => 'composer.lock unreadable'];

        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['packages']) || !is_array($data['packages'])) {
        $errors[] = ['scope' => 'packages', 'reason' => 'composer.lock unparsable'];

        return [];
    }

    $packages = [];
    foreach ($data['packages'] as $package) {
        if (!isset($package['name'], $package['version'])) {
            continue;
        }
        // L'hôte qui a distribué le paquet (dist.url) : packagist pour presque tous, repo.magento.com pour ce que
        // Magento installe lui-même. Un nom d'hôte public, jamais l'adresse entière ni ce qu'elle pourrait porter.
        $host = null;
        if (isset($package['dist']['url']) && is_string($package['dist']['url'])) {
            $host = parse_url($package['dist']['url'], PHP_URL_HOST);
            $host = is_string($host) ? strtolower($host) : null;
        }
        $packages[] = [
            'name'    => (string) $package['name'],
            'version' => ltrim((string) $package['version'], 'v'),
            'host'    => $host,
            'source'  => 'composer.lock',
        ];
        if (count($packages) >= LAMPARO_MAX_PACKAGES) {
            $errors[] = ['scope' => 'packages', 'reason' => 'truncated at ' . LAMPARO_MAX_PACKAGES];
            break;
        }
    }

    return $packages;
}

// ---------------------------------------------------------------------------
// WordPress
// ---------------------------------------------------------------------------

function lamparo_detect_wordpress(array $root, array &$errors): ?array
{
    $versionFile = null;
    foreach ([$root['web'], $root['app']] as $base) {
        $candidate = $base . '/wp-includes/version.php';
        if (is_file($candidate)) {
            $versionFile = $candidate;
            $wpRoot      = $base;
            break;
        }
    }

    if ($versionFile === null) {
        return null;
    }

    $version = lamparo_match_in_file($versionFile, '/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/');

    $components = array_merge(
        lamparo_scan_wp_plugins($wpRoot . '/wp-content/plugins', $errors),
        lamparo_scan_wp_themes($wpRoot . '/wp-content/themes', $errors)
    );

    return [
        'cms' => [
            'type'      => 'wordpress',
            'version'   => $version,
            'detection' => 'wp-includes/version.php',
        ],
        'components' => $components,
    ];
}

function lamparo_scan_wp_plugins(string $dir, array &$errors): array
{
    if (!is_dir($dir) || !is_readable($dir)) {
        return [];
    }

    $components = [];
    foreach (lamparo_list_dirs($dir) as $slug) {
        $pluginDir = $dir . '/' . $slug;
        $mainFile  = lamparo_find_wp_plugin_file($pluginDir);
        if ($mainFile === null) {
            continue;
        }

        $header = lamparo_read_head($mainFile);
        $name   = lamparo_match_in_string($header, '/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi');
        if ($name === null) {
            continue;
        }

        $components[] = [
            'type'    => 'plugin',
            'slug'    => $slug,
            'name'    => trim($name),
            'version' => lamparo_match_in_string($header, '/^[ \t\/*#@]*Version:\s*(.+)$/mi'),
            'source'  => 'plugin-header',
        ];

        if (count($components) >= LAMPARO_MAX_COMPONENTS) {
            $errors[] = ['scope' => 'components', 'reason' => 'truncated'];
            break;
        }
    }

    return $components;
}

function lamparo_find_wp_plugin_file(string $pluginDir): ?string
{
    $files = @scandir($pluginDir);
    if ($files === false) {
        return null;
    }

    foreach ($files as $file) {
        if (substr($file, -4) !== '.php') {
            continue;
        }
        $path = $pluginDir . '/' . $file;
        if (!is_file($path)) {
            continue;
        }
        if (stripos(lamparo_read_head($path), 'Plugin Name:') !== false) {
            return $path;
        }
    }

    return null;
}

function lamparo_scan_wp_themes(string $dir, array &$errors): array
{
    if (!is_dir($dir) || !is_readable($dir)) {
        return [];
    }

    $components = [];
    foreach (lamparo_list_dirs($dir) as $slug) {
        $styleFile = $dir . '/' . $slug . '/style.css';
        if (!is_file($styleFile)) {
            continue;
        }
        $header = lamparo_read_head($styleFile);
        $name   = lamparo_match_in_string($header, '/^[ \t\/*#@]*Theme Name:\s*(.+)$/mi');
        if ($name === null) {
            continue;
        }
        $components[] = [
            'type'    => 'theme',
            'slug'    => $slug,
            'name'    => trim($name),
            'version' => lamparo_match_in_string($header, '/^[ \t\/*#@]*Version:\s*(.+)$/mi'),
            'source'  => 'theme-header',
        ];
    }

    return $components;
}

// ---------------------------------------------------------------------------
// Drupal
// ---------------------------------------------------------------------------

function lamparo_detect_drupal(array $root, array &$errors): ?array
{
    $candidates = [
        $root['web'] . '/core/lib/Drupal.php',
        $root['app'] . '/web/core/lib/Drupal.php',
        $root['app'] . '/docroot/core/lib/Drupal.php',
        $root['app'] . '/core/lib/Drupal.php',
    ];

    $found = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $found = $candidate;
            break;
        }
    }

    if ($found === null) {
        return null;
    }

    $version     = lamparo_match_in_file($found, '/const\s+VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/');
    $drupalRoot  = dirname($found, 3); // .../core/lib/Drupal.php → racine Drupal
    $components  = [];

    // Le dossier dit l'origine : Drupal range le contribué et le sur-mesure à part. On remonte le fait, pas un avis.
    $folders = [
        ['/modules/contrib', 'module', 'contrib'],
        ['/modules/custom',  'module', 'custom'],
        ['/themes/contrib',  'theme',  'contrib'],
        ['/themes/custom',   'theme',  'custom'],
    ];
    foreach ($folders as $folder) {
        $components = array_merge(
            $components,
            lamparo_scan_drupal_infos($drupalRoot . $folder[0], $folder[1], $folder[2], $errors)
        );
    }

    return [
        'cms' => [
            'type'      => 'drupal',
            'version'   => $version,
            'detection' => 'core/lib/Drupal.php',
        ],
        'components' => $components,
    ];
}

function lamparo_scan_drupal_infos(string $dir, string $type, string $origin, array &$errors): array
{
    if (!is_dir($dir) || !is_readable($dir)) {
        return [];
    }

    $components = [];
    foreach (lamparo_list_dirs($dir) as $slug) {
        $infoFile = $dir . '/' . $slug . '/' . $slug . '.info.yml';
        if (!is_file($infoFile)) {
            continue;
        }
        $head = lamparo_read_head($infoFile, LAMPARO_MAX_MANIFEST_BYTES);
        $components[] = [
            'type'    => $type,
            'slug'    => $slug,
            'name'    => lamparo_match_in_string($head, '/^name:\s*[\'"]?([^\'"\n]+)/mi'),
            'version' => lamparo_match_in_string($head, '/^version:\s*[\'"]?([^\'"\n]+)/mi'),
            'origin'  => $origin,
            // Le script d'empaquetage de drupal.org ajoute « project: » en fin de fichier. Son absence est un fait :
            // ce composant n'est pas venu du dépôt public, quel que soit le dossier où il se trouve.
            'project' => lamparo_match_in_string($head, '/^project:\s*[\'"]?([^\'"\n]+)/mi'),
            'source'  => 'info.yml',
        ];
        if (count($components) >= LAMPARO_MAX_COMPONENTS) {
            $errors[] = ['scope' => 'components', 'reason' => 'truncated'];
            break;
        }
    }

    return $components;
}

// ---------------------------------------------------------------------------
// PrestaShop (best effort) et Joomla
// ---------------------------------------------------------------------------

/**
 * Drupal 7 : un autre logiciel sous le même nom. Pas de dossier core/, pas de composer ; la version dans
 * includes/bootstrap.inc, les modules et thèmes dans sites/all/, décrits par des fichiers .info (clé = valeur), où le
 * script d'empaquetage de drupal.org ajoute « project = "…" » comme il ajoute « project: » en 8 et suivants.
 */
function lamparo_detect_drupal7(array $root, array &$errors): ?array
{
    $file = $root['web'] . '/includes/bootstrap.inc';
    if (!is_file($file)) {
        return null;
    }
    $version = lamparo_match_in_file($file, '/define\(\s*[\'"]VERSION[\'"]\s*,\s*[\'"]([0-9][^\'"]*)[\'"]\s*\)/');
    if ($version === null) {
        return null;
    }
    $components = [];
    // Le dossier dit l'origine quand le site range contrib et custom à part ; ailleurs on ne l'invente pas.
    $folders = [
        ['/sites/all/modules/contrib', 'module', 'contrib'],
        ['/sites/all/modules/custom',  'module', 'custom'],
        ['/sites/all/modules',         'module', null],
        ['/sites/all/themes',          'theme',  null],
    ];
    foreach ($folders as [$folder, $type, $origin]) {
        $components = array_merge($components, lamparo_scan_drupal7_infos($root['web'] . $folder, $type, $origin, $errors));
    }

    return [
        'cms' => ['type' => 'drupal', 'version' => $version, 'detection' => 'includes/bootstrap.inc'],
        'components' => $components,
    ];
}

function lamparo_scan_drupal7_infos(string $dir, string $type, ?string $origin, array &$errors): array
{
    if (!is_dir($dir) || !is_readable($dir)) {
        return [];
    }
    $components = [];
    foreach (lamparo_list_dirs($dir) as $slug) {
        if ($slug === 'contrib' || $slug === 'custom') {
            continue; // lus à part, avec leur origine
        }
        $infoFile = $dir . '/' . $slug . '/' . $slug . '.info';
        if (!is_file($infoFile)) {
            continue;
        }
        // Un .info de Drupal 7 liste ses fichiers avant le bloc d'empaquetage : Views en porte dix kilo-octets.
        $head = lamparo_read_head($infoFile, LAMPARO_MAX_MANIFEST_BYTES);
        $components[] = [
            'type'    => $type,
            'slug'    => $slug,
            'name'    => lamparo_match_in_string($head, '/^name\s*=\s*[\'"]?([^\'"\n]+?)[\'"]?\s*$/mi'),
            'version' => lamparo_match_in_string($head, '/^version\s*=\s*[\'"]?([^\'"\n]+?)[\'"]?\s*$/mi'),
            'origin'  => $origin,
            'project' => lamparo_match_in_string($head, '/^project\s*=\s*[\'"]?([^\'"\n]+?)[\'"]?\s*$/mi'),
            'source'  => 'info',
        ];
        if (count($components) >= LAMPARO_MAX_COMPONENTS) {
            $errors[] = ['scope' => 'components', 'reason' => 'truncated'];
            break;
        }
    }

    return $components;
}

function lamparo_detect_prestashop(array $root, array &$errors): ?array
{
    // PrestaShop 8 et 9 écrivent la version dans src/Core/Version.php, et AppKernel ne fait qu'y renvoyer
    // (`const VERSION = Version::VERSION`) ; 1.7 la met dans config/settings.inc.php ou defines.inc.php.
    $candidates = [
        $root['web'] . '/src/Core/Version.php',
        $root['web'] . '/app/AppKernel.php',
        $root['web'] . '/config/settings.inc.php',
        $root['web'] . '/config/defines.inc.php',
    ];

    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $version = lamparo_match_in_file($candidate, '/_PS_VERSION_[\'"]?\s*,\s*[\'"]([0-9.]+)/')
            ?? lamparo_match_in_file($candidate, '/const\s+VERSION\s*=\s*[\'"]([0-9.]+)/');

        if ($version !== null) {
            return [
                'cms' => [
                    'type'      => 'prestashop',
                    'version'   => $version,
                    'detection' => basename($candidate),
                ],
                'components' => lamparo_scan_prestashop_modules($root['web'], $errors),
            ];
        }
    }

    return null;
}

/**
 * Les modules PrestaShop installés : PrestaShop écrit un config.xml dans le dossier d'un module au moment de
 * l'installer — nom, nom affiché, version, auteur — et rien pour un module seulement déposé. On lit ce fichier,
 * jamais le PHP du module.
 */
function lamparo_scan_prestashop_modules(string $web, array &$errors): array
{
    $components = [];
    foreach (lamparo_list_dirs($web . '/modules') as $dir) {
        $manifest = $web . '/modules/' . $dir . '/config.xml';
        if (!is_file($manifest)) {
            continue;
        }
        $xml = lamparo_read_head($manifest, LAMPARO_MAX_MANIFEST_BYTES);
        if (stripos($xml, '<module') === false) {
            continue;
        }
        $components[] = [
            'type'    => 'module',
            'slug'    => $dir,
            'name'    => lamparo_xml_text($xml, 'displayName') ?? lamparo_xml_text($xml, 'name') ?? $dir,
            'version' => lamparo_xml_text($xml, 'version'),
            'author'  => lamparo_xml_text($xml, 'author'),
            'source'  => 'config.xml',
        ];
        if (count($components) >= LAMPARO_MAX_COMPONENTS) {
            $errors[] = ['scope' => 'components', 'reason' => 'truncated'];
            break;
        }
    }

    return $components;
}

/** Le texte d'une balise XML simple, nu ou en CDATA, entités décodées ; null si la balise manque ou est vide. */
function lamparo_xml_text(string $xml, string $tag): ?string
{
    if (preg_match('~<' . preg_quote($tag, '~') . '\b[^>]*>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</' . preg_quote($tag, '~') . '>~is', $xml, $m) !== 1) {
        return null;
    }
    $text = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));

    return $text === '' ? null : $text;
}

/**
 * TYPO3. En mode composer, le cœur est le paquet typo3/cms-core, sous vendor/ à la racine du projet ; en mode
 * classique, il vit dans typo3/sysext/core sous la racine web. Les deux portent Typo3Version.php et sa constante.
 * Les extensions du mode classique se lisent dans typo3conf/ext/<clé>/ext_emconf.php ; en mode composer elles
 * sont des paquets composer, déjà remontés dans « packages ».
 */
function lamparo_detect_typo3(array $root, array &$errors): ?array
{
    $candidates = [
        $root['app'] . '/vendor/typo3/cms-core/Classes/Information/Typo3Version.php',
        $root['web'] . '/typo3/sysext/core/Classes/Information/Typo3Version.php',
    ];
    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $version = lamparo_match_in_file($candidate, '/const\s+VERSION\s*=\s*[\'"]([0-9.]+)/');
        if ($version === null) {
            continue;
        }

        return [
            'cms' => [
                'type'      => 'typo3',
                'version'   => $version,
                'detection' => strpos($candidate, '/vendor/') !== false ? 'vendor/typo3/cms-core' : 'typo3/sysext/core',
            ],
            'components' => lamparo_scan_typo3_extensions($root['web'], $errors),
        ];
    }

    return null;
}

/** Les extensions d'un TYPO3 classique : une par dossier de typo3conf/ext, décrite par son ext_emconf.php — lu comme du texte, jamais inclus. */
function lamparo_scan_typo3_extensions(string $web, array &$errors): array
{
    $components = [];
    foreach (lamparo_list_dirs($web . '/typo3conf/ext') as $dir) {
        $manifest = $web . '/typo3conf/ext/' . $dir . '/ext_emconf.php';
        if (!is_file($manifest)) {
            continue;
        }
        $php = lamparo_read_head($manifest, LAMPARO_MAX_MANIFEST_BYTES);
        $components[] = [
            'type'    => 'plugin',
            'slug'    => $dir,
            'name'    => lamparo_match_in_string($php, '/[\'"]title[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/') ?? $dir,
            'version' => lamparo_match_in_string($php, '/[\'"]version[\'"]\s*=>\s*[\'"]([0-9][^\'"]*)[\'"]/'),
            'author'  => lamparo_match_in_string($php, '/[\'"]author[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/'),
            'source'  => 'ext_emconf.php',
        ];
        if (count($components) >= LAMPARO_MAX_COMPONENTS) {
            $errors[] = ['scope' => 'components', 'reason' => 'truncated'];
            break;
        }
    }

    return $components;
}

/**
 * SPIP. La version vit dans ecrire/inc_version.php ($spip_version_branche). Les plugins installés sont dans
 * plugins/ — directement, ou sous plugins/auto/ quand SVP les a téléchargés —, chacun décrit par un paquet.xml ;
 * ceux de plugins-dist/ sont livrés avec SPIP et se mettent à jour avec lui : pas de ligne.
 */
function lamparo_detect_spip(array $root, array &$errors): ?array
{
    $file = $root['web'] . '/ecrire/inc_version.php';
    if (!is_file($file)) {
        return null;
    }
    // La version est au milieu d'un fichier de vingt kilo-octets, bien après les huit premiers : on lit plus loin.
    $version = lamparo_match_in_file($file, '/\$spip_version_branche\s*=\s*[\'"]([0-9][^\'"]*)[\'"]/', LAMPARO_MAX_MANIFEST_BYTES);
    if ($version === null) {
        return null;
    }

    return [
        'cms' => ['type' => 'spip', 'version' => $version, 'detection' => 'ecrire/inc_version.php'],
        'components' => lamparo_scan_spip_plugins($root['web'], $errors),
    ];
}

function lamparo_scan_spip_plugins(string $web, array &$errors): array
{
    $components = [];
    $dirs = [];
    foreach (lamparo_list_dirs($web . '/plugins') as $dir) {
        if ($dir === 'auto') {
            foreach (lamparo_list_dirs($web . '/plugins/auto') as $sub) {
                $dirs[] = 'auto/' . $sub;
            }
        } else {
            $dirs[] = $dir;
        }
    }
    foreach ($dirs as $dir) {
        $manifest = $web . '/plugins/' . $dir . '/paquet.xml';
        if (!is_file($manifest)) {
            continue;
        }
        $xml = lamparo_read_head($manifest, LAMPARO_MAX_MANIFEST_BYTES);
        if (preg_match('~<paquet\b([^>]*)>~i', $xml, $m) !== 1) {
            continue;
        }
        $prefix = lamparo_match_in_string($m[1], '~\bprefix="([^"]+)"~i');
        if ($prefix === null) {
            continue;
        }
        $components[] = [
            'type'    => 'plugin',
            'slug'    => strtolower($prefix),
            'name'    => lamparo_xml_text($xml, 'nom') ?? $prefix,
            'version' => lamparo_match_in_string($m[1], '~\bversion="([^"]+)"~i'),
            'author'  => lamparo_xml_text($xml, 'auteur'),
            'state'   => lamparo_match_in_string($m[1], '~\betat="([^"]+)"~i'),
            'source'  => 'paquet.xml',
        ];
        if (count($components) >= LAMPARO_MAX_COMPONENTS) {
            $errors[] = ['scope' => 'components', 'reason' => 'truncated'];
            break;
        }
    }

    return $components;
}

function lamparo_detect_joomla(array $root, array &$errors): ?array
{
    foreach ([$root['web'], $root['app']] as $base) {
        foreach (['/libraries/src/Version.php', '/libraries/cms/version/version.php'] as $candidate) {
            if (!is_file($base . $candidate)) {
                continue;
            }
            $major = lamparo_match_in_file($base . $candidate, '/MAJOR_VERSION\s*=\s*(\d+)/');
            $minor = lamparo_match_in_file($base . $candidate, '/MINOR_VERSION\s*=\s*(\d+)/');
            $patch = lamparo_match_in_file($base . $candidate, '/PATCH_VERSION\s*=\s*(\d+)/');
            if ($major === null) {
                continue;
            }

            return [
                'cms' => [
                    'type'      => 'joomla',
                    'version'   => implode('.', array_filter([$major, $minor, $patch], 'strlen')),
                    'detection' => ltrim($candidate, '/'),
                ],
                'components' => lamparo_scan_joomla_extensions($base, $errors),
            ];
        }
    }

    return null;
}

/**
 * Les extensions d'un Joomla se lisent dans leurs manifestes XML, un par extension : le nom, la version, l'auteur et
 * le serveur de mise à jour. Joomla n'a pas de dépôt central de versions : c'est ce serveur, déclaré par l'éditeur,
 * que lamparo interrogera. Les extensions livrées avec Joomla (auteur « Joomla! Project ») sont le cœur, dont la
 * version se lit ailleurs : elles n'ont pas leur ligne.
 *
 *   composants  administrator/components/com_x/*.xml         → com_x
 *   modules     modules/mod_x/mod_x.xml, administrator/modules/mod_x/mod_x.xml → mod_x
 *   plugins     plugins/<groupe>/<élément>/<élément>.xml    → plg_<groupe>_<élément>
 *   templates   templates/<nom>/templateDetails.xml, administrator/templates/… → <nom>
 */
function lamparo_scan_joomla_extensions(string $joomla, array &$errors): array
{
    $components = [];
    $full = false;
    // Les paquets d'abord : c'est leur manifeste qui porte le serveur de mise à jour et la liste de ce qu'ils
    // installent — un composant, des plugins, un module. Chaque membre saura à quel paquet il appartient.
    $packaged = [];
    foreach (glob($joomla . '/administrator/manifests/packages/pkg_*.xml') ?: [] as $manifest) {
        $xml = lamparo_read_head($manifest, LAMPARO_MAX_MANIFEST_BYTES);
        if (stripos($xml, '<extension') === false) {
            continue;
        }
        $packagename = lamparo_match_in_string($xml, '~<packagename>\s*([^<]+?)\s*</packagename>~i');
        $slug = $packagename !== null ? 'pkg_' . strtolower($packagename) : strtolower(basename($manifest, '.xml'));
        // Un paquet du cœur — un pack de langue, par exemple — se met à jour avec Joomla : pas de ligne.
        if (lamparo_joomla_is_core($xml, $slug)) {
            continue;
        }
        $members = [];
        if (preg_match_all('~<file\b([^>]*)>~i', $xml, $files) > 0) {
            foreach ($files[1] as $attributes) {
                $type  = lamparo_match_in_string($attributes, '~\btype="([^"]+)"~i');
                $id    = lamparo_match_in_string($attributes, '~\bid="([^"]+)"~i');
                $group = lamparo_match_in_string($attributes, '~\bgroup="([^"]+)"~i');
                if ($type === null || $id === null) {
                    continue;
                }
                $member = strtolower($id);
                if ($type === 'plugin' && $group !== null) {
                    $member = 'plg_' . strtolower($group) . '_' . $member;
                } elseif ($type === 'module' && strpos($member, 'mod_') !== 0) {
                    $member = 'mod_' . $member;
                } elseif ($type === 'component' && strpos($member, 'com_') !== 0) {
                    $member = 'com_' . $member;
                }
                $members[] = $member;
                $packaged[$member] = $slug;
            }
        }
        $components[] = [
            'type'       => 'bundle',
            'slug'       => $slug,
            'name'       => lamparo_match_in_string($xml, '~<name>\s*([^<]+?)\s*</name>~i'),
            'version'    => lamparo_match_in_string($xml, '~<version>\s*([^<]+?)\s*</version>~i'),
            'author'     => lamparo_match_in_string($xml, '~<author>\s*([^<]+?)\s*</author>~i'),
            'client'     => 'administrator',
            'update_url' => lamparo_joomla_update_server($xml),
            'members'    => $members,
            'source'     => 'manifest',
        ];
    }

    $add = static function (string $type, string $slug, string $manifest, string $client) use (&$components, &$full, &$errors, $packaged): void {
        if ($full) {
            return;
        }
        $xml = lamparo_read_head($manifest, LAMPARO_MAX_MANIFEST_BYTES);
        if ($xml === '' || stripos($xml, '<extension') === false) {
            return;
        }
        $author = lamparo_match_in_string($xml, '~<author>\s*([^<]+?)\s*</author>~i');
        if (lamparo_joomla_is_core($xml, $slug)) {
            return;
        }
        $name = lamparo_match_in_string($xml, '~<name>\s*([^<]+?)\s*</name>~i');
        if ($name === null) {
            return;
        }
        $components[] = [
            'type'       => $type,
            'slug'       => $slug,
            'name'       => $name,
            'version'    => lamparo_match_in_string($xml, '~<version>\s*([^<]+?)\s*</version>~i'),
            'author'     => $author,
            'client'     => $client,
            // La première adresse de <updateservers> : là où l'éditeur publie ses versions. Rien n'est appelé d'ici.
            'update_url' => lamparo_joomla_update_server($xml),
            // Installée par un paquet : c'est lui qui se met à jour, et son serveur qui répond.
            'package'    => isset($packaged[$slug]) ? $packaged[$slug] : null,
            'source'     => 'manifest',
        ];
        if (count($components) >= LAMPARO_MAX_COMPONENTS) {
            $errors[] = ['scope' => 'components', 'reason' => 'truncated'];
            $full = true;
        }
    };

    // Composants : le manifeste porte le nom du composant sans son préfixe, ou avec — on prend le premier .xml qui est un manifeste.
    foreach (lamparo_list_dirs($joomla . '/administrator/components') as $dir) {
        if (strpos($dir, 'com_') !== 0) {
            continue;
        }
        foreach ([substr($dir, 4) . '.xml', $dir . '.xml'] as $file) {
            $manifest = $joomla . '/administrator/components/' . $dir . '/' . $file;
            if (is_file($manifest)) {
                $add('component', $dir, $manifest, 'administrator');
                break;
            }
        }
    }
    foreach ([['/modules', 'site'], ['/administrator/modules', 'administrator']] as [$folder, $client]) {
        foreach (lamparo_list_dirs($joomla . $folder) as $dir) {
            if (strpos($dir, 'mod_') === 0) {
                $add('module', $dir, $joomla . $folder . '/' . $dir . '/' . $dir . '.xml', $client);
            }
        }
    }
    foreach (lamparo_list_dirs($joomla . '/plugins') as $group) {
        foreach (lamparo_list_dirs($joomla . '/plugins/' . $group) as $element) {
            $add('plugin', 'plg_' . $group . '_' . $element, $joomla . '/plugins/' . $group . '/' . $element . '/' . $element . '.xml', 'site');
        }
    }
    foreach ([['/templates', 'site'], ['/administrator/templates', 'administrator']] as [$folder, $client]) {
        foreach (lamparo_list_dirs($joomla . $folder) as $dir) {
            $add('template', $dir, $joomla . $folder . '/' . $dir . '/templateDetails.xml', $client);
        }
    }

    return $components;
}

/**
 * Livré avec Joomla : l'auteur, l'adresse ou le copyright du manifeste le disent. Deux éditeurs embarqués (TinyMCE,
 * CodeMirror) signent de leur nom mais se mettent à jour avec le cœur : on les écarte de même.
 */
function lamparo_joomla_is_core(string $xml, string $slug): bool
{
    return preg_match('~<author>\s*joomla!?\s*project~i', $xml) === 1
        || preg_match('~<authorUrl>\s*(https?://)?(www\.)?joomla\.org~i', $xml) === 1
        || preg_match('~<copyright>[^<]*Open Source Matters~i', $xml) === 1
        || in_array($slug, ['plg_editors_tinymce', 'plg_editors_codemirror'], true);
}

/** L'adresse du premier serveur de mise à jour d'un manifeste : nue, en CDATA, ou avec des entités — les trois se voient. */
function lamparo_joomla_update_server(string $xml): ?string
{
    if (preg_match('~<server\b[^>]*>\s*(?:<!\[CDATA\[)?\s*(https?://[^\]<\s]+)~i', $xml, $m) !== 1) {
        return null;
    }

    return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Utilitaires de lecture (aucune écriture, aucune exécution)
// ---------------------------------------------------------------------------

/** Liste les sous-répertoires immédiats, sans les points. */
function lamparo_list_dirs(string $dir): array
{
    $entries = @scandir($dir);
    if ($entries === false) {
        return [];
    }

    $dirs = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir($dir . '/' . $entry)) {
            $dirs[] = $entry;
        }
    }

    return $dirs;
}

/** Lit uniquement l'en-tête d'un fichier (métadonnées), jamais son contenu entier. */
function lamparo_read_head(string $path, int $bytes = LAMPARO_MAX_HEADER_BYTES): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return '';
    }
    $head = (string) fread($handle, $bytes);
    fclose($handle);

    return $head;
}

function lamparo_match_in_file(string $path, string $pattern, int $bytes = LAMPARO_MAX_HEADER_BYTES): ?string
{
    return lamparo_match_in_string(lamparo_read_head($path, $bytes), $pattern);
}

function lamparo_match_in_string(string $subject, string $pattern): ?string
{
    if ($subject === '') {
        return null;
    }
    if (preg_match($pattern, $subject, $matches) === 1) {
        return trim($matches[1]);
    }

    return null;
}
