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
 * Création / mise à jour du schéma.
 *
 * @param string $nom_meta_base_version
 * @param string $version_cible
 * @return void
 */
function dashboard_upgrade($nom_meta_base_version, $version_cible) {
	$maj = [];

	$maj['create'] = [
		['dashboard_creer_tables'],
		['dashboard_initialiser_configuration'],
	];

	// L'étape « create » n'est jamais rejouée une fois la version enregistrée en
	// meta. Cette étape versionnée rattrape les installations où les tables
	// n'ont pas été créées ; elle est sans effet quand tout est déjà en place.
	$maj['1.0.2'] = [
		['dashboard_creer_tables'],
		['dashboard_initialiser_configuration'],
	];

	// 1.0.2 créait les tables sans prévenir le compilateur : les squelettes
	// gardaient une vue du schéma antérieure à leur création.
	$maj['1.0.3'] = [
		['dashboard_creer_tables'],
	];

	include_spip('base/upgrade');
	maj_plugin($nom_meta_base_version, $version_cible, $maj);
}

/**
 * Suppression complète des données du plugin.
 *
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
 * Crée les tables du plugin, et vérifie qu'elles existent réellement ensuite.
 *
 * `maj_tables()` ne traite que les tables présentes dans le registre de SPIP au
 * moment de l'appel ; si le plugin vient d'être activé et que ce registre n'est
 * pas encore peuplé, elle ne fait rien — sans le signaler. On repasse donc
 * derrière avec les descripteurs du plugin lui-même, qui ne dépendent de rien.
 *
 * @return array Tables encore absentes après coup (vide si tout va bien)
 */
function dashboard_creer_tables() {
	include_spip('base/create');
	include_spip('base/dashboard_tables');

	$descriptions = dashboard_descriptions_tables();
	$noms = array_keys($descriptions);

	if (function_exists('maj_tables')) {
		maj_tables($noms);
	}

	$manquantes = dashboard_tables_manquantes($noms);
	foreach ($manquantes as $nom) {
		dashboard_creer_table($nom, $descriptions[$nom]);
	}

	$restantes = dashboard_tables_manquantes($noms);

	// Créer des tables sans réinitialiser les caches laisse le compilateur sur
	// une vue périmée du schéma, et les boucles échouent sur une table qui
	// existe pourtant.
	dashboard_vider_caches();

	if ($restantes) {
		spip_log('installation : tables toujours absentes après création : ' . implode(', ', $restantes), 'dashboard');
	} elseif ($manquantes) {
		spip_log('installation : tables créées directement depuis les descripteurs : ' . implode(', ', $manquantes), 'dashboard');
	}

	return $restantes;
}

/**
 * Crée une table à partir de son descripteur.
 *
 * @param string $nom
 * @param array $description
 * @return void
 */
function dashboard_creer_table($nom, $description) {
	if (!function_exists('sql_create') || empty($description['field'])) {
		return;
	}

	sql_create(
		$nom,
		$description['field'],
		$description['key'] ?? [],
		dashboard_table_autoincrement($description),
		false,
		'',
		'continue'
	);
}

/**
 * La clef primaire de cette table est-elle un identifiant auto-incrémenté ?
 *
 * @param array $description
 * @return bool
 */
function dashboard_table_autoincrement($description) {
	$primaire = $description['key']['PRIMARY KEY'] ?? '';

	// Une clef composite ou textuelle ne s'auto-incrémente pas.
	return (strpos($primaire, ',') === false) && (strncmp($primaire, 'id_', 3) === 0);
}

/**
 * Réinitialise ce que SPIP a mémorisé du schéma et des squelettes compilés.
 *
 * @return void
 */
function dashboard_vider_caches() {
	// Cache des descriptions de tables, consulté par le compilateur.
	$trouver_table = charger_fonction('trouver_table', 'base', true);
	if ($trouver_table) {
		$trouver_table('');
	}

	// Squelettes compilés et pages calculées.
	include_spip('inc/flock');
	if (function_exists('purger_repertoire') && defined('_DIR_CACHE') && is_dir(_DIR_CACHE)) {
		purger_repertoire(_DIR_CACHE, ['subdir' => true]);
	}

	if (function_exists('ecrire_meta')) {
		ecrire_meta('derniere_modif', (string) time());
	}
}

/**
 * Parmi ces tables, lesquelles n'existent pas en base ?
 *
 * @param array $noms
 * @return array
 */
function dashboard_tables_manquantes($noms) {
	$existantes = sql_alltable('%');
	$existantes = is_array($existantes) ? array_flip($existantes) : [];

	$manquantes = [];
	foreach ($noms as $nom) {
		if (!isset($existantes[$nom])) {
			$manquantes[] = $nom;
		}
	}

	return $manquantes;
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
