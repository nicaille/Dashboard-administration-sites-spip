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
		// La liste est celle que le plugin déclare, jamais une liste écrite
		// ici : une liste à la main ne suit pas les tables qu'on ajoute, et
		// rien ne le signale. Celle de la tour de contrôle en était restée à
		// quatre sur sept, et une base amputée passait pour saine.
		include_spip('base/tourdecontrole_agent_tables');

		$attendues = function_exists('tourdecontrole_agent_descriptions_tables')
			? array_keys(tourdecontrole_agent_descriptions_tables())
			: [];

		$liste = sql_alltable('%');
		$liste = is_array($liste) ? array_flip($liste) : [];

		// Sans déclaration lisible, on ne conclut pas à l'absence.
		$presentes = true;
		foreach ($attendues as $table) {
			$presentes = $presentes && isset($liste[$table]);
		}
	}

	return $presentes;
}

/**
 * Les sauvegardes présentes sur ce site, la plus récente d'abord.
 *
 * Elles vivent dans `tmp/dashagent/sauvegardes/`, hors espace web : rien ne les
 * montrait au webmestre du site, qui pouvait donc refuser qu'on en produise
 * mais pas constater ce qui avait été pris chez lui. Sur un site hébergé pour
 * un tiers, cette asymétrie n'est pas tenable.
 *
 * @filtre
 * @param string $rien
 * @return array
 */
function dashagent_sauvegardes($rien = '') {
	include_spip('inc/dashagent_sauvegarde');

	return dashagent_sauvegarde_lister();
}

/**
 * Poids total des sauvegardes présentes, en octets.
 *
 * C'est le chiffre qui compte sur un hébergement au quota : le nombre de
 * fichiers ne dit rien, leur somme si.
 *
 * @filtre
 * @param string $rien
 * @return int
 */
function dashagent_sauvegardes_octets($rien = '') {
	$total = 0;
	foreach (dashagent_sauvegardes() as $sauvegarde) {
		$total += (int) ($sauvegarde['octets'] ?? 0);
	}

	return $total;
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
