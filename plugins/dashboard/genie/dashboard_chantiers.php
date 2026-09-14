<?php
/**
 * Reprise des chantiers abandonnés.
 *
 * Un chantier est normalement poussé par le navigateur de celui qui l'a lancé.
 * Un onglet fermé, une coupure réseau, un ordinateur en veille : la mise à jour
 * resterait alors à mi-chemin, le site géré dans un état intermédiaire. Cette
 * tâche la mène à son terme.
 *
 * @package SPIP\Dashboard\Genie
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @param int $t Horodatage du dernier passage
 * @return int
 */
function genie_dashboard_chantiers_dist($t) {
	include_spip('inc/dashboard_chantiers');

	$abandonnes = dashboard_chantiers_abandonnes(3);
	if (!$abandonnes) {
		return 1;
	}

	// Le temps d'un cron web est compté : quelques étapes par passage, et la
	// tâche repasse. Un chantier avance donc à coup sûr, même lentement.
	$debut = time();
	$avances = 0;

	foreach ($abandonnes as $chantier) {
		$id = (int) $chantier['id_dashboard_chantier'];
		do {
			$chantier = dashboard_chantier_avancer($id);
			$avances++;
		} while (
			!dashboard_chantier_fini($chantier)
			&& (time() - $debut) < 60
		);

		if ((time() - $debut) >= 60) {
			break;
		}
	}

	spip_log('dashboard_chantiers : ' . $avances . ' étape(s) reprise(s)', 'dashboard');

	return 1;
}
