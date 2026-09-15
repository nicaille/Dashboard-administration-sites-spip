<?php
/**
 * Amorçage minimal pour exécuter les fonctions pures des deux plugins hors SPIP.
 *
 * Seules les briques qui ne dépendent pas de la base ni des squelettes sont
 * testables ainsi : signature, comparaison d'adresses, validation d'archives,
 * formatage. C'est justement là que se jouent la sécurité et l'interopérabilité
 * des deux extrémités.
 */

define('_ECRIRE_INC_VERSION', '1');
define('_DIR_TMP', sys_get_temp_dir() . '/dashboard-tests/');
define('_DIR_RACINE', __DIR__ . '/');

if (!is_dir(_DIR_TMP)) {
	mkdir(_DIR_TMP, 0777, true);
}

/** Configuration simulée de l'agent, pilotée par les tests. */
$GLOBALS['dashagent_config_test'] = [];
/** Configuration simulée du tableau de bord. */
$GLOBALS['dashboard_config_test'] = [];

function dashagent_config($clef, $defaut = null) {
	$valeur = $GLOBALS['dashagent_config_test'][$clef] ?? null;

	return ($valeur === null || $valeur === '') ? $defaut : $valeur;
}

/** `dashboard_config()` est fourni par le plugin lui-même : on ne stube que sa source. */
function lire_config($chemin, $defaut = null) {
	$clef = preg_replace('#^dashboard/#', '', (string) $chemin);
	$valeur = $GLOBALS['dashboard_config_test'][$clef] ?? null;

	return ($valeur === null || $valeur === '') ? $defaut : $valeur;
}

function include_spip($chemin) {
	return true;
}

/* `ecrire_config()` du core : le cache des versions y passe. Les tests le
   relisent par `lire_config()`, qui puise dans la même configuration simulée. */
function ecrire_config($chemin, $valeur) {
	$GLOBALS['dashboard_config_test'][preg_replace('#^dashboard/#', '', (string) $chemin)] = $valeur;

	return true;
}

/* Aucun appel sortant depuis les tests unitaires. Deux simulations : l'index des
   archives, et la liste des adresses auxquelles un HEAD répond 200. */
function recuperer_url($url, $options = []) {
	if (($options['methode'] ?? 'GET') === 'HEAD') {
		$servies = $GLOBALS['dashboard_archives_servies_test'] ?? [];

		return ['status' => in_array($url, $servies, true) ? 200 : 404];
	}

	$page = $GLOBALS['dashboard_index_archives_test'] ?? null;

	return $page === null ? false : ['status' => 200, 'page' => $page];
}

function _T($clef, $args = []) {
	return $clef;
}

function spip_version_compare($v1, $v2, $op = null) {
	$normaliser = function ($v) {
		return preg_replace('/[^0-9.]/', '', (string) $v);
	};

	return version_compare($normaliser($v1), $normaliser($v2), $op);
}

/**
 * Chemin d'un plugin, retrouvé par son préfixe.
 *
 * Les dossiers de plugins portent leur version (`dashboard-1.0.16`), pour qu'une
 * mise en ligne n'écrase pas la version précédente. Les retrouver par préfixe
 * évite d'avoir à toucher ce fichier à chaque montée de version.
 *
 * @param string $prefixe
 * @return string
 */
function chemin_plugin($prefixe) {
	$trouves = glob(__DIR__ . '/../plugins/' . $prefixe . '-*', GLOB_ONLYDIR);
	if (!$trouves) {
		fwrite(STDERR, "Plugin introuvable : $prefixe\n");
		exit(2);
	}
	// La plus haute version, comme le fait SPIP quand deux dossiers déclarent
	// le même préfixe.
	usort($trouves, 'version_compare');

	return end($trouves);
}

require_once chemin_plugin('dashboard_agent') . '/dashagent_options.php';
require_once chemin_plugin('dashboard_agent') . '/inc/dashagent_securite.php';
require_once chemin_plugin('dashboard_agent') . '/inc/dashagent_fs.php';
require_once chemin_plugin('dashboard_agent') . '/inc/dashagent_infos.php';
require_once chemin_plugin('dashboard_agent') . '/inc/dashagent_maj.php';
require_once chemin_plugin('dashboard_agent') . '/inc/dashagent_base.php';
require_once chemin_plugin('dashboard_agent') . '/inc/dashagent_serveur.php';
/* La délégation à SVP : seules ses fonctions pures sont chargeables hors SPIP,
   les autres ont besoin de SVP lui-même. */
require_once chemin_plugin('dashboard_agent') . '/inc/dashagent_svp.php';

/* Le client du dashboard tire quelques fonctions du core SPIP : on les neutralise
   avant de le charger, pour ne garder que la partie protocole. */
function url_de_base() {
	return 'https://dashboard.test/';
}

require_once chemin_plugin('dashboard') . '/inc/dashboard_client.php';
require_once chemin_plugin('dashboard') . '/inc/dashboard_operations.php';
/* La synchronisation touche à la base, mais ses règles de décompte sont pures. */
require_once chemin_plugin('dashboard') . '/inc/dashboard_sync.php';
/* Le moteur de chantiers ne touche à la base que dans ses fonctions d'accès :
   la logique d'enchaînement des étapes, elle, est vérifiable telle quelle. */
require_once chemin_plugin('dashboard') . '/inc/dashboard_chantiers.php';
require_once chemin_plugin('dashboard') . '/dashboard_fonctions.php';
