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

function _T($clef, $args = []) {
	return $clef;
}

function spip_version_compare($v1, $v2, $op = null) {
	$normaliser = function ($v) {
		return preg_replace('/[^0-9.]/', '', (string) $v);
	};

	return version_compare($normaliser($v1), $normaliser($v2), $op);
}

require_once __DIR__ . '/../plugins/dashboard_agent/dashagent_options.php';
require_once __DIR__ . '/../plugins/dashboard_agent/inc/dashagent_securite.php';
require_once __DIR__ . '/../plugins/dashboard_agent/inc/dashagent_fs.php';
require_once __DIR__ . '/../plugins/dashboard_agent/inc/dashagent_maj.php';

/* Le client du dashboard tire quelques fonctions du core SPIP : on les neutralise
   avant de le charger, pour ne garder que la partie protocole. */
function url_de_base() {
	return 'https://dashboard.test/';
}

require_once __DIR__ . '/../plugins/dashboard/inc/dashboard_client.php';
require_once __DIR__ . '/../plugins/dashboard/inc/dashboard_operations.php';
