<?php
/**
 * Inventaire du site géré : core, environnement, plugins, caches, base.
 *
 * @package SPIP\Dashagent\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Construit l'inventaire complet renvoyé au dashboard.
 *
 * @param array $args
 *     - bool `caches` : mesurer les caches (défaut true)
 *     - bool `plugins` : inventorier les plugins (défaut true)
 * @return array
 */
function dashagent_infos_collecter($args = []) {
	$avec_caches  = !isset($args['caches']) || $args['caches'];
	$avec_plugins = !isset($args['plugins']) || $args['plugins'];

	$infos = [
		'site'    => dashagent_infos_site(),
		'spip'    => dashagent_infos_spip(),
		'serveur' => dashagent_infos_serveur(),
		'base'    => dashagent_infos_base(),
	];

	$infos['plugins'] = $avec_plugins ? dashagent_infos_plugins() : null;
	$infos['caches']  = $avec_caches ? dashagent_infos_caches() : null;
	$infos['capacites'] = dashagent_infos_capacites();

	return $infos;
}

/**
 * Identité du site.
 *
 * @return array
 */
function dashagent_infos_site() {
	$adresse = (string) ($GLOBALS['meta']['adresse_site'] ?? '');

	return [
		'nom'    => (string) ($GLOBALS['meta']['nom_site'] ?? ''),
		'url'    => url_de_base(),
		'langue' => (string) ($GLOBALS['meta']['langue_site'] ?? ''),
		'https'  => (strncmp($adresse, 'https://', 8) === 0),
	];
}

/**
 * Version du core et principaux réglages.
 *
 * @return array
 */
function dashagent_infos_spip() {
	$branche = (string) ($GLOBALS['spip_version_branche'] ?? '');
	$code    = $GLOBALS['spip_version_code'] ?? null;

	return [
		'version'         => $branche,
		'version_code'    => $code,
		'version_base'    => (string) ($GLOBALS['meta']['version_installee'] ?? ''),
		'ecran_securite'  => defined('_ECRAN_SECURITE') ? _ECRAN_SECURITE : null,
		'charset'         => (string) ($GLOBALS['meta']['charset'] ?? ''),
		'dir_plugins'     => defined('_DIR_PLUGINS') ? _DIR_PLUGINS : '',
		'racine_absolue'  => defined('_ROOT_RACINE') ? _ROOT_RACINE : '',
	];
}

/**
 * Environnement d'exécution.
 *
 * @return array
 */
function dashagent_infos_serveur() {
	include_spip('inc/dashagent_fs');

	return [
		'php'                 => PHP_VERSION,
		'php_sapi'            => PHP_SAPI,
		'memory_limit'        => ini_get('memory_limit'),
		'max_execution_time'  => (int) ini_get('max_execution_time'),
		'upload_max_filesize' => ini_get('upload_max_filesize'),
		'extensions'          => [
			'zip'      => class_exists('ZipArchive'),
			'curl'     => function_exists('curl_init'),
			'gd'       => function_exists('imagecreatetruecolor'),
			'zlib'     => function_exists('gzopen'),
			'sodium'   => function_exists('sodium_crypto_secretbox'),
		],
		'exec_disponible'     => dashagent_exec_disponible(),
		'disque_libre'        => dashagent_espace_disque_libre(),
		'os'                  => PHP_OS_FAMILY,
	];
}

/**
 * Volumétrie de la base et du contenu éditorial.
 *
 * @return array
 */
function dashagent_infos_base() {
	$base = [
		'serveur'      => '',
		'version'      => '',
		'octets'       => null,
		'nb_tables'    => null,
		'contenus'     => [],
	];

	$version = sql_version();
	if (is_string($version)) {
		$base['version'] = $version;
	}
	$base['serveur'] = $GLOBALS['connexions'][0]['type'] ?? '';

	$tables = sql_alltable('%');
	if (is_array($tables)) {
		$base['nb_tables'] = count($tables);
	}

	// Taille réelle : spécifique à MySQL/MariaDB, on échoue silencieusement ailleurs.
	$res = sql_query('SHOW TABLE STATUS', '', 'continue');
	if ($res) {
		$octets = 0;
		while ($ligne = sql_fetch($res, '')) {
			$octets += (int) ($ligne['Data_length'] ?? 0) + (int) ($ligne['Index_length'] ?? 0);
		}
		$base['octets'] = $octets;
	}

	foreach (['article' => 'spip_articles', 'rubrique' => 'spip_rubriques', 'document' => 'spip_documents', 'auteur' => 'spip_auteurs'] as $clef => $table) {
		$n = sql_countsel($table, '', '', '', '', 'continue');
		if ($n !== false) {
			$base['contenus'][$clef] = (int) $n;
		}
	}

	return $base;
}

/**
 * Inventaire des plugins actifs, avec version disponible si SVP est présent.
 *
 * @return array
 */
function dashagent_infos_plugins() {
	include_spip('inc/plugin');

	$actifs = unserialize($GLOBALS['meta']['plugin'] ?? '');
	if (!is_array($actifs)) {
		$actifs = [];
	}

	$disponibles = dashagent_versions_disponibles();
	$plugins = [];

	foreach ($actifs as $prefixe => $plugin) {
		$dir_type = $plugin['dir_type'] ?? '_DIR_PLUGINS';
		$racine   = defined($dir_type) ? constant($dir_type) : _DIR_PLUGINS;
		$chemin   = $racine . ($plugin['dir'] ?? '');

		$version = (string) ($plugin['version'] ?? '');
		$dispo   = $disponibles[strtoupper($prefixe)] ?? null;

		$plugins[] = [
			'prefixe'            => strtoupper($prefixe),
			'nom'                => dashagent_nom_plugin($plugin),
			'version'            => $version,
			'version_disponible' => $dispo,
			'maj_disponible'     => ($dispo && $version && spip_version_compare($dispo, $version, '>')),
			'etat'               => (string) ($plugin['etat'] ?? ''),
			'dossier'            => (string) ($plugin['dir'] ?? ''),
			'chemin'             => $chemin,
			'dir_type'           => $dir_type,
			'distribue'          => ($dir_type === '_DIR_PLUGINS_DIST'),
			'source'             => dashagent_source_plugin($chemin),
			'inscriptible'       => is_dir($chemin) && is_writable($chemin),
			'version_base'       => (string) ($plugin['version_base'] ?? ''),
		];
	}

	usort($plugins, function ($a, $b) {
		return strcasecmp($a['prefixe'], $b['prefixe']);
	});

	return $plugins;
}

/**
 * Nom lisible d'un plugin, quel que soit le format stocké dans la meta.
 *
 * @param array $plugin
 * @return string
 */
function dashagent_nom_plugin($plugin) {
	$nom = $plugin['nom'] ?? '';
	if (is_array($nom)) {
		$nom = reset($nom);
	}
	$nom = (string) $nom;

	// Les noms peuvent être des chaînes de langue non traduites hors contexte.
	if (preg_match('/^<:.*:>$/', $nom)) {
		return (string) ($plugin['dir'] ?? $nom);
	}

	return $nom;
}

/**
 * Détermine par quel moyen un plugin a été déployé.
 *
 * @param string $chemin
 * @return string git|svp|manuel|introuvable
 */
function dashagent_source_plugin($chemin) {
	if (!is_dir($chemin)) {
		return 'introuvable';
	}
	if (is_dir(rtrim($chemin, '/') . '/.git')) {
		return 'git';
	}
	if (file_exists(rtrim($chemin, '/') . '/.svp')) {
		return 'svp';
	}

	return 'manuel';
}

/**
 * Versions disponibles connues localement, via les dépôts SVP.
 *
 * SVP maintient déjà un miroir des dépôts : on l'interroge plutôt que de faire
 * sortir N sites sur le réseau. Sans SVP, la comparaison de versions est faite
 * côté dashboard.
 *
 * @return array Tableau prefixe majuscule => version la plus haute disponible
 */
function dashagent_versions_disponibles() {
	if (!dashagent_table_existe('spip_paquets') || !dashagent_table_existe('spip_plugins')) {
		return [];
	}

	include_spip('inc/plugin');
	$versions = [];

	$res = sql_select(
		['pl.prefixe AS prefixe', 'pa.version AS version'],
		['spip_paquets AS pa', 'spip_plugins AS pl'],
		['pa.id_plugin = pl.id_plugin', 'pa.id_depot > 0'],
		'',
		'',
		'',
		'',
		'',
		'continue'
	);
	if (!$res) {
		return [];
	}

	while ($ligne = sql_fetch($res, '')) {
		$prefixe = strtoupper((string) $ligne['prefixe']);
		$version = (string) $ligne['version'];
		if ($version === '') {
			continue;
		}
		if (!isset($versions[$prefixe]) || spip_version_compare($version, $versions[$prefixe], '>')) {
			$versions[$prefixe] = $version;
		}
	}

	return $versions;
}

/**
 * Mesure des différents caches purgeables.
 *
 * @return array
 */
function dashagent_infos_caches() {
	include_spip('inc/dashagent_cache');
	include_spip('inc/dashagent_fs');

	$mesures = [];
	foreach (dashagent_cibles_cache() as $cible => $definition) {
		$total = ['existe' => false, 'octets' => 0, 'fichiers' => 0, 'partiel' => false];
		foreach ($definition['repertoires'] as $dir) {
			$m = dashagent_mesurer_repertoire($dir);
			$total['existe']   = $total['existe'] || $m['existe'];
			$total['octets']  += $m['octets'];
			$total['fichiers'] += $m['fichiers'];
			$total['partiel']  = $total['partiel'] || $m['partiel'];
		}
		$total['libelle'] = $definition['libelle'];
		$mesures[$cible]  = $total;
	}

	return $mesures;
}

/**
 * Ce que l'agent est en mesure de faire ici et maintenant.
 *
 * Le dashboard s'en sert pour griser les actions impossibles plutôt que de les
 * proposer puis d'échouer.
 *
 * @return array
 */
function dashagent_infos_capacites() {
	include_spip('inc/dashagent');
	include_spip('inc/dashagent_fs');

	$capacites = [];
	foreach (['infos', 'purger', 'sauvegarde_creer', 'plugin_maj', 'core_maj'] as $op) {
		$capacites[$op] = dashagent_operation_autorisee($op);
	}

	$capacites['zip']              = class_exists('ZipArchive');
	$capacites['tmp_inscriptible'] = (bool) dashagent_dir_travail();
	$capacites['core_inscriptible'] = is_writable(_DIR_RACINE ?: '.') && is_writable(_DIR_RESTREINT_ABS ?: (_DIR_RACINE . 'ecrire/'));
	$capacites['plugins_inscriptible'] = defined('_DIR_PLUGINS') && is_dir(_DIR_PLUGINS) && is_writable(_DIR_PLUGINS);
	$capacites['svp']              = dashagent_table_existe('spip_paquets');

	return $capacites;
}

/**
 * La table existe-t-elle dans la base courante ?
 *
 * @param string $table
 * @return bool
 */
function dashagent_table_existe($table) {
	static $tables = null;
	if ($tables === null) {
		$liste  = sql_alltable('%');
		$tables = is_array($liste) ? array_flip($liste) : [];
	}

	return isset($tables[$table]);
}

/**
 * La fonction exec() est-elle réellement utilisable ?
 *
 * @return bool
 */
function dashagent_exec_disponible() {
	if (!function_exists('exec')) {
		return false;
	}
	$desactivees = array_map('trim', explode(',', (string) ini_get('disable_functions')));

	return !in_array('exec', $desactivees, true);
}
