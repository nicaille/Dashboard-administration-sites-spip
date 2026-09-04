<?php
/**
 * Déclaration des tables de l'agent Dashboard.
 *
 * @package SPIP\Dashagent\Base
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Table de journalisation des opérations reçues par l'agent.
 *
 * Elle est volontairement autonome (pas d'objet éditorial) : c'est une piste
 * d'audit, consultable dans la page de configuration du plugin.
 *
 * @pipeline declarer_tables_principales
 * @param array $tables
 * @return array
 */
function dashagent_declarer_tables_principales($tables) {
	$tables['spip_dashagent_journal'] = [
		'field' => [
			'id_dashagent_journal' => 'bigint(21) NOT NULL',
			'date'                 => "datetime DEFAULT '0000-00-00 00:00:00' NOT NULL",
			'operation'            => "varchar(64) DEFAULT '' NOT NULL",
			'statut'               => "varchar(16) DEFAULT 'ok' NOT NULL",
			'ip'                   => "varchar(45) DEFAULT '' NOT NULL",
			'duree'                => 'int(11) DEFAULT 0 NOT NULL',
			'message'              => "text DEFAULT '' NOT NULL",
			'detail'               => "mediumtext DEFAULT '' NOT NULL",
		],
		'key' => [
			'PRIMARY KEY'   => 'id_dashagent_journal',
			'KEY date'      => 'date',
			'KEY operation' => 'operation',
			'KEY statut'    => 'statut',
		],
	];

	return $tables;
}

/**
 * Table des nonces consommés, pour la protection anti-rejeu.
 *
 * La clef primaire porte sur le nonce lui-même : c'est l'échec de l'insertion
 * qui garantit qu'un nonce ne peut pas servir deux fois, sans verrou applicatif.
 *
 * @pipeline declarer_tables_auxiliaires
 * @param array $tables
 * @return array
 */
function dashagent_declarer_tables_auxiliaires($tables) {
	$tables['spip_dashagent_nonces'] = [
		'field' => [
			'nonce' => "varchar(64) DEFAULT '' NOT NULL",
			'date'  => "datetime DEFAULT '0000-00-00 00:00:00' NOT NULL",
		],
		'key' => [
			'PRIMARY KEY' => 'nonce',
			'KEY date'    => 'date',
		],
	];

	return $tables;
}

/**
 * Rend la table de journal utilisable dans une boucle `<BOUCLE(DASHAGENT_JOURNAL)>`.
 *
 * Sans cet alias, SPIP chercherait une table au nom pluralisé.
 *
 * @pipeline declarer_tables_interfaces
 * @param array $interfaces
 * @return array
 */
function dashagent_declarer_tables_interfaces($interfaces) {
	$interfaces['table_des_tables']['dashagent_journal'] = 'dashagent_journal';

	return $interfaces;
}
