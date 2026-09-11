<?php
/**
 * Avancement d'un chantier, appelé en boucle par la fiche du site.
 *
 * Répond toujours en JSON : c'est le navigateur qui pousse le chantier, un
 * aller-retour à la fois, et qui affiche l'étape en cours au fur et à mesure.
 * Fermer l'onglet n'annule rien — le cron reprendra là où l'on s'est arrêté.
 *
 * @package SPIP\Dashboard\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @return void
 */
function action_dashboard_chantier_dist() {
	include_spip('inc/autoriser');
	include_spip('inc/dashboard_chantiers');

	$securiser_action = charger_fonction('securiser_action', 'inc');
	$id_chantier = (int) $securiser_action();

	$chantier = $id_chantier ? dashboard_chantier_charger($id_chantier) : null;
	if (!$chantier) {
		dashboard_chantier_repondre(['ok' => false, 'fini' => true, 'message' => 'Opération inconnue']);
	}

	if (!autoriser('operer', 'dashboard_site', (int) $chantier['id_dashboard_site'])) {
		dashboard_chantier_repondre(['ok' => false, 'fini' => true, 'message' => 'Opération non autorisée']);
	}

	// Une étape distante peut prendre plusieurs minutes : le temps d'exécution
	// par défaut ne suffirait pas, et la boucle s'arrêterait sans explication.
	@set_time_limit(600);

	if (!dashboard_chantier_fini($chantier)) {
		$chantier = dashboard_chantier_avancer((int) $chantier['id_dashboard_chantier']);
	}

	dashboard_chantier_repondre(dashboard_chantier_etat_json($chantier));
}

/**
 * L'état d'un chantier, tel que l'attend le navigateur.
 *
 * @param array|null $chantier
 * @return array
 */
function dashboard_chantier_etat_json($chantier) {
	if (!$chantier) {
		return ['ok' => false, 'fini' => true, 'message' => 'Opération introuvable'];
	}

	$etapes = dashboard_chantier_etapes((string) $chantier['operation']);
	$total  = max(1, count($etapes));

	return [
		'ok'      => ((string) $chantier['statut'] !== 'erreur'),
		'fini'    => dashboard_chantier_fini($chantier),
		'statut'  => (string) $chantier['statut'],
		'etape'   => (string) $chantier['etape'],
		'libelle' => dashboard_chantier_libelle((string) $chantier['operation'], (string) $chantier['cible']),
		'rang'    => min((int) $chantier['rang'] + 1, $total),
		'total'   => $total,
		'message' => (string) $chantier['message'],
	];
}

/**
 * Émet la réponse JSON et termine.
 *
 * @param array $donnees
 * @return void
 */
function dashboard_chantier_repondre($donnees) {
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, private');
	}
	echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}
