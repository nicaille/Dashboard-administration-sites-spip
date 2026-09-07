<?php
/**
 * Filtres mis à disposition des squelettes de l'agent.
 *
 * @package SPIP\Dashagent\Fonctions
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Les tables de l'agent sont-elles réellement présentes en base ?
 *
 * Une activation ratée peut laisser la version enregistrée en meta sans que les
 * tables aient été créées ; la page de configuration le dit alors clairement au
 * lieu de laisser remonter une erreur SQL.
 *
 * @filtre
 * @return bool
 */
function dashagent_tables_presentes($rien = '') {
	static $presentes = null;

	if ($presentes === null) {
		$liste = sql_alltable('%');
		$liste = is_array($liste) ? array_flip($liste) : [];
		$presentes = isset($liste['spip_dashagent_journal']) && isset($liste['spip_dashagent_nonces']);
	}

	return $presentes;
}
