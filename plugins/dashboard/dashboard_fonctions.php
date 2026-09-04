<?php
/**
 * Filtres mis à disposition des squelettes du tableau de bord.
 *
 * @package SPIP\Dashboard\Fonctions
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

include_spip('inc/dashboard_client');
include_spip('inc/dashboard_operations');
include_spip('inc/dashboard_versions');

/**
 * Extrait une valeur de l'inventaire JSON mémorisé pour un site.
 *
 * Exemple : `[(#INFOS|dashboard_info{serveur/php})]`
 *
 * @filtre
 * @param string $json
 * @param string $chemin Chemin séparé par des `/`
 * @param mixed $defaut
 * @return mixed
 */
function dashboard_info($json, $chemin, $defaut = '') {
	$infos = is_array($json) ? $json : json_decode((string) $json, true);
	if (!is_array($infos)) {
		return $defaut;
	}

	foreach (explode('/', (string) $chemin) as $clef) {
		if (!is_array($infos) || !array_key_exists($clef, $infos)) {
			return $defaut;
		}
		$infos = $infos[$clef];
	}

	return ($infos === null || $infos === '') ? $defaut : $infos;
}

/**
 * Formate une taille en octets pour l'affichage.
 *
 * @filtre
 * @param int $octets
 * @return string
 */
function dashboard_taille($octets) {
	return dashboard_octets((int) $octets);
}

/**
 * Classe CSS correspondant à l'état d'un site.
 *
 * @filtre
 * @param string $etat
 * @return string
 */
function dashboard_classe_etat($etat) {
	$classes = [
		'ok'      => 'dashboard-ok',
		'erreur'  => 'dashboard-erreur',
		'inconnu' => 'dashboard-inconnu',
	];

	return $classes[$etat] ?? 'dashboard-inconnu';
}

/**
 * Version de SPIP disponible pour la branche d'un site, ou chaîne vide.
 *
 * @filtre
 * @param string $version_actuelle
 * @return string
 */
function dashboard_version_disponible($version_actuelle) {
	return dashboard_version_cible((string) $version_actuelle);
}

/**
 * Rend lisible une opération du journal.
 *
 * @filtre
 * @param string $operation
 * @return string
 */
function dashboard_libelle_operation($operation) {
	$libelles = [
		'sync'            => 'dashboard:operation_sync',
		'purger'          => 'dashboard:operation_purger',
		'sauvegarde'      => 'dashboard:operation_sauvegarde',
		'plugin_maj'      => 'dashboard:operation_plugin_maj',
		'plugin_maj_tous' => 'dashboard:operation_plugin_maj_tous',
		'core_maj'        => 'dashboard:operation_core_maj',
	];

	return isset($libelles[$operation]) ? _T($libelles[$operation]) : $operation;
}

/**
 * Synthèse d'un parc : compteurs pour la page d'ensemble.
 *
 * @filtre
 * @return array
 */
function dashboard_synthese($rien = '') {
	$synthese = [
		'sites'        => (int) sql_countsel('spip_dashboard_sites', 'statut != ' . sql_quote('poubelle')),
		'supervises'   => (int) sql_countsel('spip_dashboard_sites', 'statut = ' . sql_quote('publie')),
		'en_erreur'    => (int) sql_countsel('spip_dashboard_sites', ['statut = ' . sql_quote('publie'), 'etat = ' . sql_quote('erreur')]),
		'core_a_jour'  => 0,
		'core_retard'  => (int) sql_countsel('spip_dashboard_sites', ['statut = ' . sql_quote('publie'), 'core_maj = ' . sql_quote('oui')]),
		'plugins_maj'  => 0,
	];

	$synthese['core_a_jour'] = max(0, $synthese['supervises'] - $synthese['core_retard']);

	$total = sql_fetsel('SUM(nb_plugins_maj) AS total', 'spip_dashboard_sites', 'statut = ' . sql_quote('publie'));
	$synthese['plugins_maj'] = (int) ($total['total'] ?? 0);

	return $synthese;
}

/**
 * Mesure d'un cache donné dans l'inventaire mémorisé.
 *
 * Exemple : `[(#INFOS|dashboard_cache_info{images,octets})]`
 *
 * @filtre
 * @param string $json
 * @param string $cible
 * @param string $clef octets|fichiers|partiel
 * @return int
 */
function dashboard_cache_info($json, $cible, $clef = 'octets') {
	return (int) dashboard_info($json, 'caches/' . $cible . '/' . $clef, 0);
}

/**
 * Libellé d'une cible de purge.
 *
 * @filtre
 * @param string $cible
 * @return string
 */
function dashboard_libelle_cache($cible) {
	$cibles = dashboard_cibles_purge();

	return $cibles[$cible] ?? $cible;
}

/**
 * Cibles de purge proposées par l'interface.
 *
 * @filtre
 * @return array
 */
function dashboard_cibles_purge() {
	return [
		'tout'       => _T('dashboard:purge_tout'),
		'pages'      => _T('dashboard:purge_pages'),
		'squelettes' => _T('dashboard:purge_squelettes'),
		'images'     => _T('dashboard:purge_images'),
		'css_js'     => _T('dashboard:purge_css_js'),
		'sessions'   => _T('dashboard:purge_sessions'),
	];
}
