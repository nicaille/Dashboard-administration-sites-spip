<?php
/**
 * Téléchargement d'une sauvegarde rapatriée sur le tableau de bord.
 *
 * Les sauvegardes sont stockées hors de l'espace web : ce script est le seul
 * chemin d'accès, et il exige les droits d'opération sur le site concerné.
 *
 * @package SPIP\Dashboard\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @return void
 */
function action_dashboard_telecharger_dist() {
	include_spip('inc/dashboard_operations');

	$securiser_action = charger_fonction('securiser_action', 'inc');
	$id_sauvegarde = (int) $securiser_action();

	$ligne = sql_fetsel('*', 'spip_dashboard_sauvegardes', 'id_dashboard_sauvegarde = ' . $id_sauvegarde);
	if (!$ligne || $ligne['statut'] !== 'locale' || $ligne['fichier'] === '') {
		dashboard_telecharger_refus(404, 'Sauvegarde indisponible');
	}
	if (!autoriser('operer', 'dashboard_site', (int) $ligne['id_dashboard_site'])) {
		dashboard_telecharger_refus(403, 'Accès refusé');
	}

	$dir = dashboard_dir_sauvegardes((int) $ligne['id_dashboard_site']);
	$chemin = $dir . basename((string) $ligne['fichier']);
	if (!$dir || !is_file($chemin)) {
		dashboard_telecharger_refus(404, 'Fichier absent du disque');
	}

	while (ob_get_level()) {
		ob_end_clean();
	}

	header('Content-Type: application/gzip');
	header('Content-Length: ' . filesize($chemin));
	header('Content-Disposition: attachment; filename="' . basename($chemin) . '"');
	header('Cache-Control: no-store, private');

	readfile($chemin);
	exit;
}

/**
 * @param int $code
 * @param string $message
 * @return void
 */
function dashboard_telecharger_refus($code, $message) {
	http_response_code($code);
	header('Content-Type: text/plain; charset=utf-8');
	echo $message;
	exit;
}
