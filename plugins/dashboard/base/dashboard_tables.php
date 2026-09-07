<?php
/**
 * Déclaration des tables du tableau de bord.
 *
 * @package SPIP\Dashboard\Base
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Schéma de la table des sites gérés.
 *
 * Il est isolé ici parce qu'il est déclaré deux fois : comme objet éditorial,
 * pour la machinerie d'édition, et comme table principale, parce que c'est
 * cette déclaration-là que `maj_tables()` consulte pour créer la table. SPIP
 * procède de même pour ses propres objets.
 *
 * @return array
 */
function dashboard_schema_sites() {
	return [
		'field' => [
			'id_dashboard_site' => 'bigint(21) NOT NULL',
			'titre'             => "text DEFAULT '' NOT NULL",
			'url_site'          => "varchar(255) DEFAULT '' NOT NULL",
			'url_agent'         => "varchar(255) DEFAULT '' NOT NULL",
			'secret'            => "text DEFAULT '' NOT NULL",
			'groupe'            => "varchar(64) DEFAULT '' NOT NULL",
			'notes'             => "text DEFAULT '' NOT NULL",

			// Dernier état connu, alimenté par la synchronisation.
			'etat'              => "varchar(16) DEFAULT 'inconnu' NOT NULL",
			'erreur'            => "text DEFAULT '' NOT NULL",
			// Surtout pas « spip_version » : la couche SQL de SPIP réécrit tout
			// identifiant préfixé spip_ en nom de table, et la requête devient invalide.
			'version_spip'      => "varchar(32) DEFAULT '' NOT NULL",
			'php_version'       => "varchar(32) DEFAULT '' NOT NULL",
			'sql_version'       => "varchar(64) DEFAULT '' NOT NULL",
			'agent_version'     => "varchar(32) DEFAULT '' NOT NULL",
			'nb_plugins'        => 'int(11) DEFAULT 0 NOT NULL',
			'nb_plugins_maj'    => 'int(11) DEFAULT 0 NOT NULL',
			'core_maj'          => "varchar(3) DEFAULT 'non' NOT NULL",
			'infos'             => "mediumtext DEFAULT '' NOT NULL",

			'date_sync'         => "datetime DEFAULT '0000-00-00 00:00:00' NOT NULL",
			'date_sync_ok'      => "datetime DEFAULT '0000-00-00 00:00:00' NOT NULL",
			'date'              => "datetime DEFAULT '0000-00-00 00:00:00' NOT NULL",
			'statut'            => "varchar(20) DEFAULT 'prepa' NOT NULL",
			'maj'               => 'TIMESTAMP',
		],

		'key' => [
			'PRIMARY KEY'  => 'id_dashboard_site',
			'KEY statut'   => 'statut',
			'KEY etat'     => 'etat',
			'KEY groupe'   => 'groupe',
		]
	];
}

/**
 * Objet éditorial « site géré ».
 *
 * @pipeline declarer_tables_objets_sql
 * @param array $tables
 * @return array
 */
function dashboard_declarer_tables_objets_sql($tables) {
	$tables['spip_dashboard_sites'] = [
		'type'          => 'dashboard_site',
		'principale'    => 'oui',
		// Objet purement interne à l'espace privé : pas de page publique.
		'page'          => '',

		'field' => dashboard_schema_sites()['field'],
		'key'   => dashboard_schema_sites()['key'],

		'titre'   => "titre, '' AS lang",
		'date'    => 'date',
		// « statut » n'y figure pas volontairement : objet_modifier() le routerait
		// vers objet_instituer(), en plus de l'écrire lui-même. Le formulaire
		// l'institue explicitement.
		'champs_editables'  => ['titre', 'url_site', 'url_agent', 'groupe', 'notes'],
		'champs_versionnes' => [],
		'rechercher_champs' => ['titre' => 8, 'url_site' => 4, 'notes' => 1],

		'statut_textes_instituer' => [
			'prepa'    => 'dashboard:statut_pause',
			'publie'   => 'dashboard:statut_supervise',
			'poubelle' => 'dashboard:statut_poubelle',
		],

		'texte_retour'         => 'icone_retour',
		'texte_modifier'       => 'dashboard:icone_modifier_site',
		'texte_creer'          => 'dashboard:icone_creer_site',
		'texte_objets'         => 'dashboard:titre_sites',
		'texte_objet'          => 'dashboard:titre_site',
		'info_aucun_objet'     => 'dashboard:info_aucun_site',
		'info_1_objet'         => 'dashboard:info_1_site',
		'info_nb_objets'       => 'dashboard:info_nb_sites',
	];

	return $tables;
}

/**
 * Tables satellites : inventaire des plugins, journal des opérations,
 * catalogue des sauvegardes rapatriées.
 *
 * @pipeline declarer_tables_principales
 * @param array $tables
 * @return array
 */
function dashboard_declarer_tables_principales($tables) {
	// Déclarée ici en plus de declarer_tables_objets_sql : c'est le registre des
	// tables principales que consulte maj_tables() au moment de créer la base.
	$tables['spip_dashboard_sites'] = dashboard_schema_sites();

	$tables['spip_dashboard_plugins'] = [
		'field' => [
			'id_dashboard_plugin' => 'bigint(21) NOT NULL',
			'id_dashboard_site'   => 'bigint(21) DEFAULT 0 NOT NULL',
			'prefixe'             => "varchar(64) DEFAULT '' NOT NULL",
			'nom'                 => "varchar(255) DEFAULT '' NOT NULL",
			'version'             => "varchar(64) DEFAULT '' NOT NULL",
			'version_disponible'  => "varchar(64) DEFAULT '' NOT NULL",
			'maj_disponible'      => "varchar(3) DEFAULT 'non' NOT NULL",
			'etat'                => "varchar(32) DEFAULT '' NOT NULL",
			'dossier'             => "varchar(255) DEFAULT '' NOT NULL",
			'source'              => "varchar(16) DEFAULT '' NOT NULL",
			'distribue'           => "varchar(3) DEFAULT 'non' NOT NULL",
			'inscriptible'        => "varchar(3) DEFAULT 'non' NOT NULL",
			'maj'                 => 'TIMESTAMP',
		],
		'key' => [
			'PRIMARY KEY'          => 'id_dashboard_plugin',
			'KEY id_dashboard_site' => 'id_dashboard_site',
			'KEY prefixe'          => 'prefixe',
			'KEY maj_disponible'   => 'maj_disponible',
		],
	];

	$tables['spip_dashboard_journal'] = [
		'field' => [
			'id_dashboard_journal' => 'bigint(21) NOT NULL',
			'id_dashboard_site'    => 'bigint(21) DEFAULT 0 NOT NULL',
			'id_auteur'            => 'bigint(21) DEFAULT 0 NOT NULL',
			'operation'            => "varchar(64) DEFAULT '' NOT NULL",
			'statut'               => "varchar(16) DEFAULT 'ok' NOT NULL",
			'message'              => "text DEFAULT '' NOT NULL",
			'detail'               => "mediumtext DEFAULT '' NOT NULL",
			'duree'                => 'int(11) DEFAULT 0 NOT NULL',
			'date'                 => "datetime DEFAULT '0000-00-00 00:00:00' NOT NULL",
			'maj'                  => 'TIMESTAMP',
		],
		'key' => [
			'PRIMARY KEY'           => 'id_dashboard_journal',
			'KEY id_dashboard_site' => 'id_dashboard_site',
			'KEY date'              => 'date',
			'KEY statut'            => 'statut',
		],
	];

	$tables['spip_dashboard_sauvegardes'] = [
		'field' => [
			'id_dashboard_sauvegarde' => 'bigint(21) NOT NULL',
			'id_dashboard_site'       => 'bigint(21) DEFAULT 0 NOT NULL',
			'identifiant'             => "varchar(64) DEFAULT '' NOT NULL",
			'fichier'                 => "varchar(255) DEFAULT '' NOT NULL",
			'octets'                  => 'bigint(21) DEFAULT 0 NOT NULL',
			'sha256'                  => "varchar(64) DEFAULT '' NOT NULL",
			'statut'                  => "varchar(16) DEFAULT 'distante' NOT NULL",
			'date'                    => "datetime DEFAULT '0000-00-00 00:00:00' NOT NULL",
			'maj'                     => 'TIMESTAMP',
		],
		'key' => [
			'PRIMARY KEY'           => 'id_dashboard_sauvegarde',
			'KEY id_dashboard_site' => 'id_dashboard_site',
			'KEY date'              => 'date',
		],
	];

	return $tables;
}

/**
 * Alias de boucles pour les tables satellites, dont le nom n'est pas pluralisable
 * automatiquement par SPIP.
 *
 * @pipeline declarer_tables_interfaces
 * @param array $interfaces
 * @return array
 */
function dashboard_declarer_tables_interfaces($interfaces) {
	// L'objet « site géré » devrait se déclarer tout seul, mais le compilateur
	// ne retrouve pas toujours le nom de boucle d'un type composé : on lui donne
	// la correspondance explicitement. Redondant sur une installation saine,
	// déterminant sur les autres.
	$interfaces['table_des_tables']['dashboard_sites']      = 'dashboard_sites';
	$interfaces['table_des_tables']['dashboard_plugins']    = 'dashboard_plugins';
	$interfaces['table_des_tables']['dashboard_journal']    = 'dashboard_journal';
	$interfaces['table_des_tables']['dashboard_sauvegardes'] = 'dashboard_sauvegardes';

	return $interfaces;
}

/**
 * Descripteurs de toutes les tables du plugin, indexés par nom de table.
 *
 * Les fonctions de pipeline sont appelées directement, sur un tableau vide :
 * l'installation dispose ainsi des descripteurs sans dépendre de l'état du
 * registre de tables de SPIP au moment où elle s'exécute.
 *
 * @return array
 */
function dashboard_descriptions_tables() {
	return array_merge(
		dashboard_declarer_tables_objets_sql([]),
		dashboard_declarer_tables_principales([])
	);
}
