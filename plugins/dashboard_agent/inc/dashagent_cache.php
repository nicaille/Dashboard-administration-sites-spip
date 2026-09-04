<?php
/**
 * Purge des caches du site géré.
 *
 * @package SPIP\Dashagent\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Décrit les cibles de purge disponibles.
 *
 * Chaque cible liste les répertoires concernés ; ceux qui n'existent pas sur
 * cette installation sont simplement ignorés au moment de la purge.
 *
 * @return array
 */
function dashagent_cibles_cache() {
	$var = defined('_DIR_VAR') ? _DIR_VAR : _DIR_RACINE . 'local/';

	$cibles = [
		'pages' => [
			'libelle'     => 'Cache des pages calculées',
			'repertoires' => [defined('_DIR_CACHE') ? _DIR_CACHE : _DIR_TMP . 'cache/'],
			'dans_tout'   => true,
		],
		'squelettes' => [
			'libelle'     => 'Squelettes compilés',
			'repertoires' => [defined('_DIR_SKELS') ? _DIR_SKELS : _DIR_TMP . 'cache/skel/'],
			'dans_tout'   => true,
		],
		'images' => [
			'libelle'     => 'Images calculées (vignettes, filtres graphiques)',
			'repertoires' => [
				$var . 'cache-vignettes/',
				$var . 'cache-gd2/',
				$var . 'cache-images/',
			],
			'dans_tout'   => true,
		],
		'css_js' => [
			'libelle'     => 'CSS et JS compactés',
			'repertoires' => [
				$var . 'cache-css/',
				$var . 'cache-js/',
			],
			'dans_tout'   => true,
		],
		'sessions' => [
			'libelle'     => 'Sessions des visiteurs',
			'repertoires' => [defined('_DIR_SESSIONS') ? _DIR_SESSIONS : _DIR_TMP . 'sessions/'],
			// Purger les sessions déconnecte tout le monde : jamais dans « tout ».
			'dans_tout'   => false,
		],
	];

	return $cibles;
}

/**
 * Purge une ou plusieurs cibles de cache.
 *
 * @param array $cibles Liste de cibles, ou ['tout']
 * @return array{cibles: array, fichiers: int, erreurs: array}
 */
function dashagent_purger($cibles) {
	include_spip('inc/flock');

	$definitions = dashagent_cibles_cache();

	if (in_array('tout', $cibles, true)) {
		$cibles = array_keys(array_filter($definitions, function ($d) {
			return $d['dans_tout'];
		}));
	}

	$rapport = ['cibles' => [], 'fichiers' => 0, 'erreurs' => []];

	foreach (array_unique($cibles) as $cible) {
		if (!isset($definitions[$cible])) {
			$rapport['erreurs'][] = 'Cible inconnue : ' . $cible;
			continue;
		}
		$supprimes = 0;
		foreach ($definitions[$cible]['repertoires'] as $dir) {
			if (!is_dir($dir)) {
				continue;
			}
			if (!is_writable($dir)) {
				$rapport['erreurs'][] = 'Répertoire non inscriptible : ' . $dir;
				continue;
			}
			// Compté avant purge : après, le répertoire est vide par construction,
			// et un second parcours complet doublerait le coût sur les gros caches.
			$supprimes += dashagent_compter_fichiers($dir);
			purger_repertoire($dir, ['subdir' => true]);
		}
		$rapport['cibles'][$cible] = $supprimes;
		$rapport['fichiers'] += $supprimes;
	}

	// Force le recalcul des pages encore servies depuis un cache résiduel.
	if (array_intersect(['pages', 'squelettes'], array_keys($rapport['cibles']))) {
		ecrire_meta('derniere_modif', (string) time());
	}

	return $rapport;
}

/**
 * Compte les fichiers d'un répertoire, récursivement et sans exploser la mémoire.
 *
 * @param string $dir
 * @return int
 */
function dashagent_compter_fichiers($dir) {
	include_spip('inc/dashagent_fs');
	$mesure = dashagent_mesurer_repertoire($dir, 200000);

	return $mesure['fichiers'];
}
