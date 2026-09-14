<?php
/**
 * Journal des opérations du tableau de bord.
 *
 * @package SPIP\Dashboard\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Enregistre une opération.
 *
 * @param int $id_dashboard_site
 * @param string $operation
 * @param string $statut ok|erreur
 * @param string $message
 * @param mixed $detail
 * @param int $duree Millisecondes
 * @return int Identifiant de l'entrée
 */
function dashboard_journaliser($id_dashboard_site, $operation, $statut, $message = '', $detail = null, $duree = 0) {
	include_spip('inc/session');

	return (int) sql_insertq('spip_dashboard_journal', [
		'id_dashboard_site' => (int) $id_dashboard_site,
		'id_auteur'         => (int) (session_get('id_auteur') ?: 0),
		'operation'         => substr((string) $operation, 0, 64),
		'statut'            => substr((string) $statut, 0, 16),
		'message'           => (string) $message,
		'detail'            => $detail === null ? '' : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
		'duree'             => (int) $duree,
		'date'              => date('Y-m-d H:i:s'),
	]);
}

/**
 * Supprime les entrées de journal trop anciennes.
 *
 * @param int $jours
 * @return void
 */
function dashboard_journal_purger($jours = 180) {
	sql_delete('spip_dashboard_journal', 'date < ' . sql_quote(date('Y-m-d H:i:s', time() - max(1, (int) $jours) * 86400)));
}
