<?php
/**
 * Entretien périodique de l'agent : journal, nonces, sauvegardes et
 * répertoires de rollback laissés par une mise à jour.
 *
 * @package SPIP\Dashagent\Genie
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @param int $t Horodatage du dernier passage
 * @return int
 */
function genie_dashagent_entretien_dist($t) {
	include_spip('inc/dashagent');
	include_spip('inc/dashagent_sauvegarde');
	include_spip('inc/dashagent_fs');

	$retention = max(1, (int) dashagent_config('retention_journal', 90));
	sql_delete('spip_dashagent_journal', 'date < ' . sql_quote(date('Y-m-d H:i:s', time() - $retention * 86400)));
	sql_delete('spip_dashagent_nonces', 'date < ' . sql_quote(date('Y-m-d H:i:s', time() - 86400)));

	dashagent_sauvegarde_purger_anciennes();
	dashagent_entretien_rollbacks();

	return 1;
}

/**
 * Supprime les copies de sécurité laissées par les mises à jour au-delà de 7 jours.
 *
 * On les garde volontairement quelques jours : c'est le seul moyen de revenir
 * en arrière si une mise à jour s'avère mauvaise après coup.
 *
 * @return int Nombre d'entrées supprimées
 */
function dashagent_entretien_rollbacks() {
	include_spip('inc/dashagent_fs');

	$limite = time() - 7 * 86400;
	$n = 0;
	$candidats = array_merge(
		(array) glob((_DIR_RACINE ?: './') . '*.dashagent-[0-9]*'),
		defined('_DIR_PLUGINS') ? (array) glob(_DIR_PLUGINS . '*.dashagent-[0-9]*') : []
	);

	foreach ($candidats as $chemin) {
		if (filemtime($chemin) > $limite) {
			continue;
		}
		if (is_dir($chemin)) {
			dashagent_supprimer_repertoire($chemin, true);
			$n++;
		} elseif (@unlink($chemin)) {
			$n++;
		}
	}

	return $n;
}
