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

/**
 * Ce serveur sait-il valider un certificat https ?
 *
 * Toutes les mises à jour passent par un téléchargement https. Sans magasin
 * d'autorités lisible, aucune n'aboutira, quel que soit le dépôt : le dire sur
 * la page de configuration évite de chercher la panne du côté du dépôt.
 *
 * @filtre
 * @return string Chaîne vide si tout va bien, message d'alerte sinon
 */
function dashagent_alerte_autorites($rien = '') {
	include_spip('inc/dashagent_fs');
	$magasin = dashagent_magasin_autorites();

	return $magasin['ok'] ? '' : dashagent_conseil_autorites();
}
