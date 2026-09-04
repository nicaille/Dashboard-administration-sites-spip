<?php
/**
 * Installation / désinstallation du tableau de bord.
 *
 * @package SPIP\Dashboard\Installation
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @param string $nom_meta_base_version
 * @param string $version_cible
 * @return void
 */
function dashboard_upgrade($nom_meta_base_version, $version_cible) {
	$maj = [];

	$maj['create'] = [
		['maj_tables', [
			'spip_dashboard_sites',
			'spip_dashboard_plugins',
			'spip_dashboard_journal',
			'spip_dashboard_sauvegardes',
		]],
		['dashboard_initialiser_configuration'],
	];

	include_spip('base/upgrade');
	maj_plugin($nom_meta_base_version, $version_cible, $maj);
}

/**
 * @param string $nom_meta_base_version
 * @return void
 */
function dashboard_vider_tables($nom_meta_base_version) {
	sql_drop_table('spip_dashboard_sites');
	sql_drop_table('spip_dashboard_plugins');
	sql_drop_table('spip_dashboard_journal');
	sql_drop_table('spip_dashboard_sauvegardes');

	effacer_meta('dashboard');
	effacer_meta($nom_meta_base_version);
}

/**
 * Configuration par défaut.
 *
 * @return void
 */
function dashboard_initialiser_configuration() {
	include_spip('inc/config');

	$config = lire_config('dashboard', []);
	if (!is_array($config)) {
		$config = [];
	}

	$defaut = [
		'timeout'              => 30,
		'timeout_long'         => 300,
		'sync_auto'            => 'on',
		'sync_frequence'       => 6,
		'url_archives_spip'    => 'https://files.spip.net/spip/archives/',
		'version_spip_cible'   => '',
		'confirmer_core_maj'   => 'on',
		'retention_sauvegardes' => 30,
	];

	ecrire_config('dashboard', array_merge($defaut, $config));
}
