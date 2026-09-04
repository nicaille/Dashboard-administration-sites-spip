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
		'table_objet_surnoms' => ['dashboardsite'],

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
			'spip_version'      => "varchar(32) DEFAULT '' NOT NULL",
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
		],

		'titre'   => 'titre AS titre, "" AS lang',
		'date'    => 'date',
		'champs_editables'  => ['titre', 'url_site', 'url_agent', 'groupe', 'notes'],
		'champs_versionnes' => [],
		'rechercher_champs' => ['titre' => 8, 'url_site' => 4, 'notes' => 1],

		'statut_textes_instituer' => [
			'publie' => 'dashboard:statut_supervise',
			'prepa'  => 'dashboard:statut_pause',
			'poubelle' => 'dashboard:statut_poubelle',
		],
		'statut' => [[
			'champ'     => 'statut',
			'publie'    => 'publie',
			'previsu'   => 'publie,prepa',
			'exception' => ['statut', 'tout'],
		]],

		'texte_retour'         => 'icone_retour',
		'texte_modifier'       => 'dashboard:icone_modifier_site',
		'texte_creer'          => 'dashboard:icone_creer_site',
		'texte_objets'         => 'dashboard:titre_sites',
		'texte_objet'          => 'dashboard:titre_site',
		'texte_signale_edition' => 'texte_travail_article',
		'info_aucun_objet'     => 'dashboard:info_aucun_site',
		'info_1_objet'         => 'dashboard:info_1_site',
		'info_nb_objets'       => 'dashboard:info_nb_sites',
		'icone_objet'          => 'dashboard',

		'tables_jointures' => ['spip_dashboard_plugins'],
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
	$interfaces['table_des_tables']['dashboard_plugins']    = 'dashboard_plugins';
	$interfaces['table_des_tables']['dashboard_journal']    = 'dashboard_journal';
	$interfaces['table_des_tables']['dashboard_sauvegardes'] = 'dashboard_sauvegardes';

	return $interfaces;
}
