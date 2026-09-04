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
 * @return int 1 si la tâche est faite, -1 pour la relancer plus tard
 */
function genie_dashboard_sync_dist($t) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_sync');
	include_spip('inc/dashboard_versions');

	if (dashboard_config('sync_auto', 'on') !== 'on') {
		return 1;
	}

	// Rafraîchit d'abord les versions amont : sans elles, aucun site ne peut
	// être signalé comme en retard de core.
	dashboard_versions_spip(true);

	// Le lot est borné pour tenir dans le temps d'exécution d'un cron web ;
	// les sites sont pris du plus anciennement synchronisé au plus récent, donc
	// les passages successifs finissent par couvrir tout le parc.
	$lot = max(1, (int) dashboard_config('sync_lot', 10));
	$rapport = dashboard_synchroniser_tous($lot);

	spip_log('dashboard_sync : ' . $rapport['traites'] . ' site(s), ' . $rapport['erreurs'] . ' en erreur', 'dashboard');

	return 1;
}
