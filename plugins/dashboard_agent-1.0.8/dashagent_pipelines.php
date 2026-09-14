<?php
/**
 * Pipelines de l'agent Dashboard.
 *
 * @package SPIP\Dashagent\Pipelines
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Déclare la tâche d'entretien de l'agent.
 *
 * @pipeline taches_generales_cron
 * @param array $taches
 * @return array
 */
function dashagent_taches_generales_cron($taches) {
	$taches['dashagent_entretien'] = 24 * 3600;

	return $taches;
}
