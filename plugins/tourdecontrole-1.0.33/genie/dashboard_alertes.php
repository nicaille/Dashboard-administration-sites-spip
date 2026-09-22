<?php
/**
 * Alerte quotidienne du webmestre.
 *
 * La tâche tourne tous les jours ; elle n'écrit que s'il y a du nouveau. Voir
 * `inc/dashboard_alertes.php` pour la règle et ce qu'elle protège.
 *
 * @package SPIP\Dashboard\Genie
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @param int $t Horodatage du dernier passage
 * @return int 1 si la tâche est faite
 */
function genie_dashboard_alertes_dist($t) {
	include_spip('inc/dashboard_alertes');

	if (!dashboard_alerte_active()) {
		return 1;
	}

	$etat = dashboard_alerte_etat();
	$empreinte = dashboard_alerte_empreinte($etat);
	$connue = dashboard_alerte_empreinte_connue();

	if ($empreinte === $connue) {
		return 1;
	}

	// Rien à signaler et rien de signalé auparavant : c'est le cas d'une
	// installation neuve, dont l'empreinte connue est vide alors que celle d'un
	// parc sain ne l'est pas. On mémorise sans écrire à personne — sans quoi
	// la première alerte du parc serait « tout va bien », ce qui ne se demande
	// pas.
	if (dashboard_alerte_vide($etat) && $connue === '') {
		dashboard_alerte_memoriser($empreinte);

		return 1;
	}

	$bilan = dashboard_alerte_diffuser($etat);

	// L'empreinte ne se mémorise que si quelque chose est effectivement parti.
	// Enregistrer après un échec d'envoi ferait taire l'alerte pour de bon : la
	// nouveauté aurait été consommée sans avoir été dite, et il faudrait qu'un
	// *autre* changement survienne pour que le canal se réveille.
	if ($bilan['courriels'] || $bilan['push']) {
		dashboard_alerte_memoriser($empreinte);
	}

	spip_log('dashboard_alertes : ' . $bilan['courriels'] . ' courriel(s), '
		. $bilan['push'] . ' notification(s), ' . $bilan['retires'] . ' abonnement(s) périmé(s)', 'dashboard');

	return 1;
}
