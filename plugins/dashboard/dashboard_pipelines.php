<?php
/**
 * Pipelines du tableau de bord.
 *
 * @package SPIP\Dashboard\Pipelines
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Déclare la synchronisation périodique du parc.
 *
 * La période suit la configuration : sur un parc de plusieurs dizaines de
 * sites, interroger tout le monde toutes les heures n'apporte rien et charge
 * inutilement les hébergements.
 *
 * @pipeline taches_generales_cron
 * @param array $taches
 * @return array
 */
function dashboard_taches_generales_cron($taches) {
	include_spip('inc/dashboard_client');

	$heures = max(1, (int) dashboard_config('sync_frequence', 6));
	$taches['dashboard_sync'] = $heures * 3600;
	$taches['dashboard_entretien'] = 24 * 3600;

	return $taches;
}
