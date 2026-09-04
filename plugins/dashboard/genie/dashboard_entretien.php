<?php
/**
 * Entretien du tableau de bord : journal et sauvegardes rapatriées.
 *
 * @package SPIP\Dashboard\Genie
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @param int $t
 * @return int
 */
function genie_dashboard_entretien_dist($t) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');
	include_spip('inc/dashboard_operations');

	dashboard_journal_purger((int) dashboard_config('retention_journal', 180));
	dashboard_entretien_sauvegardes((int) dashboard_config('retention_sauvegardes', 30));

	return 1;
}

/**
 * Supprime les sauvegardes rapatriées au-delà de la rétention, fichier et fiche.
 *
 * @param int $jours
 * @return int Nombre de sauvegardes supprimées
 */
function dashboard_entretien_sauvegardes($jours = 30) {
	$jours = max(1, (int) $jours);
	$limite = date('Y-m-d H:i:s', time() - $jours * 86400);

	$anciennes = sql_allfetsel(
		['id_dashboard_sauvegarde', 'id_dashboard_site', 'fichier'],
		'spip_dashboard_sauvegardes',
		'date < ' . sql_quote($limite)
	);

	$n = 0;
	foreach ($anciennes as $ligne) {
		if (!empty($ligne['fichier'])) {
			$dir = dashboard_dir_sauvegardes((int) $ligne['id_dashboard_site']);
			if ($dir) {
				@unlink($dir . basename((string) $ligne['fichier']));
			}
		}
		sql_delete('spip_dashboard_sauvegardes', 'id_dashboard_sauvegarde = ' . (int) $ligne['id_dashboard_sauvegarde']);
		$n++;
	}

	return $n;
}
