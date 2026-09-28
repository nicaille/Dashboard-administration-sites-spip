<?php
/**
 * Synchronisation périodique du parc.
 *
 * @package SPIP\Dashboard\Genie
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @param int $t Horodatage du dernier passage
 * @return int 1 si la tâche est faite, -1 pour la reprendre aussitôt
 */
function genie_dashboard_sync_dist($t) {
	include_spip('inc/config');
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_sync');
	include_spip('inc/dashboard_versions');
	include_spip('inc/dashboard_catalogue');

	if (!dashboard_sync_auto()) {
		return 1;
	}

	$depart = microtime(true);
	$budget = dashboard_sync_budget();

	// L'amont une fois par cycle, et non une fois par passe : depuis que la
	// tâche se replanifie pour reprendre le parc là où le budget l'a arrêtée,
	// « à chaque passage » voudrait dire trois appels réseau forcés par tranche
	// de sites, pour des versions qui n'ont pas bougé entre deux tranches.
	if (dashboard_sync_amont_due(lire_config('dashboard/sync_amont', 0), dashboard_sync_periode(), time())) {
		// Rafraîchit les versions amont : sans elles, aucun site ne peut être
		// signalé comme en retard de core. L'annuaire officiel de spip.net
		// d'abord — c'est lui qui annonce les versions —, l'index d'archives
		// ensuite pour les miroirs qui n'ont pas d'annuaire.
		include_spip('inc/dashboard_spip_api');
		dashboard_api_annuaire(true);
		dashboard_versions_spip(true);

		// Puis le catalogue de la tour, de force : c'est lui qui tranchera
		// ensuite pour chaque site, et un catalogue de tour périmé ferait mentir
		// tout le parc d'un coup au lieu d'un seul site.
		$catalogue = dashboard_catalogue_rafraichir();
		if ($catalogue['depots']) {
			spip_log('dashboard_sync : catalogue de la tour, ' . $catalogue['telecharges']
				. ' dépôt(s) rapatrié(s) sur ' . $catalogue['depots'], 'dashboard');
		}

		ecrire_config('dashboard/sync_amont', time());
	}

	// Le lot borne le nombre de sites, le budget borne le temps — et c'est le
	// second qui protège. Dix sites dont chacun peut prendre trente secondes
	// font cinq minutes dans un seul travail, et SPIP ne borne que le nombre de
	// travaux lancés par passage, jamais la durée de l'un d'eux une fois parti.
	// Ce travail-là s'exécute très souvent dans un processus web, la file étant
	// relancée à la fin de chaque page.
	$lot = max(1, (int) dashboard_config('sync_lot', 10));
	$rapport = dashboard_synchroniser_tous($lot, max(5, $budget - (int) (microtime(true) - $depart)));

	// La durée et le SAPI dans le journal, et non le seul décompte : le jour où
	// une page du parc met plus de temps que l'hébergeur n'en accorde, c'est
	// cette ligne qui dira si une synchronisation occupait un processus web au
	// même moment. Un décompte seul ne l'aurait jamais dit.
	spip_log('dashboard_sync : ' . $rapport['traites'] . ' site(s), ' . $rapport['erreurs'] . ' en erreur, '
		. round(microtime(true) - $depart, 1) . ' s (' . PHP_SAPI . ')'
		. ($rapport['budget_epuise'] ? ' — budget épuisé, ' . $rapport['restants'] . ' site(s) à reprendre' : ''),
		'dashboard');

	// Négatif : « à poursuivre ». SPIP replanifie alors le travail aussitôt, en
	// baissant sa priorité d'un cran — ce qui laisse passer les autres tâches
	// plutôt que de monopoliser la file.
	return $rapport['budget_epuise'] ? -1 : 1;
}
