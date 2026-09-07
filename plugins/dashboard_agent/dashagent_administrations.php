<?php
/**
 * Installation / désinstallation de l'agent Dashboard.
 *
 * @package SPIP\Dashagent\Installation
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Création / mise à jour du schéma.
 *
 * @param string $nom_meta_base_version
 * @param string $version_cible
 * @return void
 */
function dashagent_upgrade($nom_meta_base_version, $version_cible) {
	$maj = [];

	$tables = ['spip_dashagent_journal', 'spip_dashagent_nonces'];

	$maj['create'] = [
		['maj_tables', $tables],
		['dashagent_initialiser_configuration'],
	];

	// Rattrapage : voir dashboard_administrations.php. Une activation avec un
	// paquet.xml invalide laissait la meta de version posée et les tables absentes.
	$maj['1.0.1'] = [
		['maj_tables', $tables],
		['dashagent_initialiser_configuration'],
	];

	include_spip('base/upgrade');
	maj_plugin($nom_meta_base_version, $version_cible, $maj);
}

/**
 * Suppression complète des données de l'agent.
 *
 * @param string $nom_meta_base_version
 * @return void
 */
function dashagent_vider_tables($nom_meta_base_version) {
	sql_drop_table('spip_dashagent_journal');
	sql_drop_table('spip_dashagent_nonces');

	effacer_meta('dashagent');
	effacer_meta($nom_meta_base_version);
}

/**
 * Valeurs de configuration par défaut, posées à la première installation.
 *
 * Le secret partagé n'est *pas* généré ici : tant qu'il est vide, l'agent
 * refuse toutes les requêtes. C'est un défaut volontairement fermé.
 *
 * @return void
 */
function dashagent_initialiser_configuration() {
	include_spip('inc/config');

	$config = lire_config('dashagent', []);
	if (!is_array($config)) {
		$config = [];
	}

	$defaut = [
		'secret'            => '',
		'ips_autorisees'    => '',
		'tolerance_horloge' => 300,
		'op_infos'          => 'on',
		'op_purger'         => 'on',
		'op_sauvegarde'     => 'on',
		'op_plugin_maj'     => '',
		'op_core_maj'       => '',
		'retention_journal' => 90,
		'retention_backup'  => 7,
	];

	ecrire_config('dashagent', array_merge($defaut, $config));
}
