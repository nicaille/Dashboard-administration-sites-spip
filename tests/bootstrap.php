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

/**
 * Le journal du core. Muet ici, mais présent : `dashboard_chiffrer()` s'en sert
 * pour signaler qu'un secret part en clair faute de chiffrement, et sans ce
 * stub la fonction tombe au lieu de rendre sa valeur de repli.
 */
function spip_log($message, $nom = 'spip') {
	$GLOBALS['spip_log_test'][] = [$nom, $message];
}

/**
 * `dashboard_config()` est fourni par le plugin lui-même : on ne stube que sa
 * source.
 *
 * Le repli sur le défaut porte sur `null` et **pas** sur la chaîne vide, comme
 * dans le vrai `lire_config()` de SPIP, qui ne rend son défaut que sur une
 * valeur absente. La distinction compte : `dashboard_url_check_source()` s'en
 * sert pour séparer « jamais réglé » de « vidé exprès ». Ce stub les confondait,
 * et un test de cette fonction y aurait passé au vert sans rien prouver.
 *
 * `dashboard_config()` ajoute son propre repli sur le vide par-dessus : rien ne
 * change pour ses appelants.
 */
function lire_config($chemin, $defaut = null) {
	$clef = preg_replace('#^dashboard/#', '', (string) $chemin);
	$valeur = $GLOBALS['dashboard_config_test'][$clef] ?? null;

	return ($valeur === null) ? $defaut : $valeur;
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
 * Les dossiers de plugins portent leur version (`tourdecontrole-1.0.30`), pour qu'une
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

require_once chemin_plugin('tourdecontrole_agent') . '/tourdecontrole_agent_options.php';
require_once chemin_plugin('tourdecontrole_agent') . '/inc/dashagent_securite.php';
require_once chemin_plugin('tourdecontrole_agent') . '/inc/dashagent_fs.php';
require_once chemin_plugin('tourdecontrole_agent') . '/inc/dashagent_infos.php';
require_once chemin_plugin('tourdecontrole_agent') . '/inc/dashagent_maj.php';
require_once chemin_plugin('tourdecontrole_agent') . '/inc/dashagent_base.php';
require_once chemin_plugin('tourdecontrole_agent') . '/inc/dashagent_serveur.php';
/* Le contrôle du contenu d'un spip_loader est une fonction pure : vérifiable ici. */
require_once chemin_plugin('tourdecontrole_agent') . '/inc/dashagent_loader.php';
/* Idem pour SPIP Check : lecture de version, d'édition et contrôle de conformité. */
require_once chemin_plugin('tourdecontrole_agent') . '/inc/dashagent_check.php';
/* La sauvegarde a besoin de la base pour exporter, mais son état de reprise et
   son recalage sur le dernier point de contrôle ne touchent qu'au disque. */
require_once chemin_plugin('tourdecontrole_agent') . '/inc/dashagent_sauvegarde.php';
/* La délégation à SVP : seules ses fonctions pures sont chargeables hors SPIP,
   les autres ont besoin de SVP lui-même. */
require_once chemin_plugin('tourdecontrole_agent') . '/inc/dashagent_svp.php';

/* Le client du dashboard tire quelques fonctions du core SPIP : on les neutralise
   avant de le charger, pour ne garder que la partie protocole. */
function url_de_base() {
	return 'https://dashboard.test/';
}

require_once chemin_plugin('tourdecontrole') . '/inc/dashboard_client.php';
require_once chemin_plugin('tourdecontrole') . '/inc/dashboard_operations.php';
/* Les filtres de squelette : lecture d'adresses, fenêtres, décomptes. */
require_once chemin_plugin('tourdecontrole') . '/tourdecontrole_fonctions.php';
/* La synchronisation touche à la base, mais ses règles de décompte sont pures. */
require_once chemin_plugin('tourdecontrole') . '/inc/dashboard_sync.php';
/* Le moteur de chantiers ne touche à la base que dans ses fonctions d'accès :
   la logique d'enchaînement des étapes, elle, est vérifiable telle quelle. */
require_once chemin_plugin('tourdecontrole') . '/inc/dashboard_chantiers.php';
require_once chemin_plugin('tourdecontrole') . '/tourdecontrole_fonctions.php';
