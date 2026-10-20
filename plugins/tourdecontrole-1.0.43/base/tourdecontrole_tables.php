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

			// Authentification HTTP du serveur, quand un htpasswd garde le site.
			// Le nom d'utilisateur n'est pas un secret et reste en clair — le
			// voir en base aide à diagnostiquer ; le mot de passe est chiffré
			// comme le secret partagé, et ne ressort jamais du formulaire.
			'auth_user'         => "varchar(128) DEFAULT '' NOT NULL",
			'auth_pass'         => "text DEFAULT '' NOT NULL",
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
			// L'état du core a quatre valeurs là où `core_maj` n'en a que deux.
			// Les deux cohabitent : `core_maj` reste le « y a-t-il quelque chose
			// à faire », celui qui arme les boutons et compte dans les alertes,
			// et il vaut « non » pour un site bloqué par son PHP comme pour un
			// site à jour — ce qui est juste, puisqu'il n'y a rien à lancer.
			'core_etat'         => "varchar(8) DEFAULT 'inconnu' NOT NULL",
			'core_cible'        => "varchar(32) DEFAULT '' NOT NULL",
			'core_majeure'      => "varchar(32) DEFAULT '' NOT NULL",
			'core_provenance'   => "varchar(8) DEFAULT '' NOT NULL",
			// Le schéma de base attend sa migration : le site répond, mais son
			// espace privé est bloqué derrière la page de mise à niveau.
			'base_maj'          => "varchar(3) DEFAULT 'non' NOT NULL",
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
function tourdecontrole_declarer_tables_objets_sql($tables) {
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
		'champs_editables'  => ['titre', 'url_site', 'url_agent', 'auth_user', 'groupe', 'notes'],
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
function tourdecontrole_declarer_tables_principales($tables) {
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
			// La version retenue après confrontation avec le catalogue de la
			// tour, et la provenance de cet avis : « tour » ou « site ». Un
			// chiffre sans sa source est un chiffre auquel on ne peut rien
			// opposer.
			'version_retenue'     => "varchar(64) DEFAULT '' NOT NULL",
			'provenance'          => "varchar(8) DEFAULT '' NOT NULL",
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

	// Une mise à jour distante ne tient pas dans une requête HTTP : elle se
	// déroule en étapes, chacune bornée à un aller-retour avec l'agent. Cette
	// table porte l'état entre deux étapes, ce qui permet aussi bien de rendre
	// compte de l'avancement que de reprendre un chantier abandonné.
	$tables['spip_dashboard_chantiers'] = [
		'field' => [
			'id_dashboard_chantier' => 'bigint(21) NOT NULL',
			'id_dashboard_site'     => 'bigint(21) DEFAULT 0 NOT NULL',
			'id_auteur'             => 'bigint(21) DEFAULT 0 NOT NULL',
			'operation'             => "varchar(32) DEFAULT '' NOT NULL",
			// Le lot qui a lancé ce chantier, quand il vient d'une sélection
			// multi-sites. Un jeton, non une séquence : il voyage dans l'adresse
			// de l'écran de résultat, et fermer l'onglet ne doit pas faire
			// perdre le lot de vue — les chantiers, eux, continuent.
			'lot'                   => "varchar(32) DEFAULT '' NOT NULL",
			'cible'                 => "varchar(255) DEFAULT '' NOT NULL",
			'etape'                 => "varchar(32) DEFAULT '' NOT NULL",
			'statut'                => "varchar(16) DEFAULT 'attente' NOT NULL",
			'rang'                  => 'int(11) DEFAULT 0 NOT NULL',
			'total'                 => 'int(11) DEFAULT 0 NOT NULL',
			'tentatives'            => 'int(11) DEFAULT 0 NOT NULL',
			'message'               => "text DEFAULT '' NOT NULL",
			'detail'                => "mediumtext DEFAULT '' NOT NULL",
			'reste'                 => "mediumtext DEFAULT '' NOT NULL",
			'date'                  => "datetime DEFAULT '0000-00-00 00:00:00' NOT NULL",
			'date_etape'            => "datetime DEFAULT '0000-00-00 00:00:00' NOT NULL",
			'date_fin'              => "datetime DEFAULT '0000-00-00 00:00:00' NOT NULL",
			'maj'                   => 'TIMESTAMP',
		],
		'key' => [
			'PRIMARY KEY'           => 'id_dashboard_chantier',
			'KEY id_dashboard_site' => 'id_dashboard_site',
			'KEY statut'            => 'statut',
			'KEY date'              => 'date',
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


	/* L'activité du WAF d'un site, un enregistrement par jour.
	 *
	 * Une table plutôt qu'une colonne JSON sur le site, pour deux raisons. La
	 * somme du parc entier devient un simple GROUP BY, là où il aurait fallu
	 * décoder puis additionner en PHP site par site. Et surtout, l'historique
	 * **survit à la rétention du WAF** : celui-ci purge ses événements au bout
	 * de quatre-vingt-dix jours, alors que la tendance sur un an est justement
	 * ce qu'on voudra lire dans un an.
	 *
	 * La clef unique sur (site, jour) fait de chaque synchronisation une simple
	 * réécriture des jours rapportés : relire deux fois la même journée ne la
	 * compte pas deux fois. */
	$tables['spip_dashboard_waf_jours'] = [
		'field' => [
			'id_dashboard_waf_jour' => 'bigint(21) NOT NULL',
			'id_dashboard_site'     => 'bigint(21) DEFAULT 0 NOT NULL',
			'jour'                  => "date DEFAULT '0000-00-00' NOT NULL",
			'requetes'              => 'bigint(21) DEFAULT 0 NOT NULL',
			'ips'                   => 'bigint(21) DEFAULT 0 NOT NULL',
			'bans'                  => 'bigint(21) DEFAULT 0 NOT NULL',
			'maj'                   => 'TIMESTAMP',
		],
		'key' => [
			'PRIMARY KEY'           => 'id_dashboard_waf_jour',
			'UNIQUE KEY site_jour'  => 'id_dashboard_site, jour',
			'KEY jour'              => 'jour',
		],
	];

	/*
	 * Les abonnements Web Push de l'espace privé.
	 *
	 * Un abonnement appartient à un **navigateur**, pas à une personne : le
	 * même webmestre en a un par poste et un par téléphone, et c'est voulu —
	 * c'est là que la notification doit arriver. D'où la clef unique sur
	 * l'adresse de distribution, que le navigateur regénère quand il révoque
	 * l'abonnement.
	 *
	 * `endpoint` est un `text` : les adresses de FCM dépassent les 255
	 * caractères, et un `varchar` les tronquerait sans rien dire — l'envoi
	 * partirait alors vers une adresse invalide pour un 404 incompréhensible.
	 * Un index sur un `text` demandant une longueur en MySQL, l'unicité se
	 * porte sur l'empreinte de l'adresse plutôt que sur l'adresse.
	 *
	 * `echecs` compte les silences consécutifs, `date_succes` date la dernière
	 * remise acceptée : un abonnement que le service refuse définitivement se
	 * supprime, mais un qui se tait ne se supprime pas sur un seul silence.
	 */
	$tables['spip_dashboard_push'] = [
		'field' => [
			'id_dashboard_push' => 'bigint(21) NOT NULL',
			'id_auteur'         => 'bigint(21) DEFAULT 0 NOT NULL',
			'empreinte'         => "char(64) DEFAULT '' NOT NULL",
			'endpoint'          => "text DEFAULT '' NOT NULL",
			'p256dh'            => "varchar(255) DEFAULT '' NOT NULL",
			'auth'              => "varchar(64) DEFAULT '' NOT NULL",
			'navigateur'        => "varchar(255) DEFAULT '' NOT NULL",
			'date'              => "datetime DEFAULT '0000-00-00 00:00:00' NOT NULL",
			'date_succes'       => "datetime DEFAULT '0000-00-00 00:00:00' NOT NULL",
			'echecs'            => 'int(11) DEFAULT 0 NOT NULL',
			'maj'               => 'TIMESTAMP',
		],
		'key' => [
			'PRIMARY KEY'            => 'id_dashboard_push',
			'UNIQUE KEY empreinte'   => 'empreinte',
			'KEY id_auteur'          => 'id_auteur',
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
function tourdecontrole_declarer_tables_interfaces($interfaces) {
	// L'objet « site géré » devrait se déclarer tout seul, mais le compilateur
	// ne retrouve pas toujours le nom de boucle d'un type composé : on lui donne
	// la correspondance explicitement. Redondant sur une installation saine,
	// déterminant sur les autres.
	$interfaces['table_des_tables']['dashboard_sites']      = 'dashboard_sites';
	$interfaces['table_des_tables']['dashboard_plugins']    = 'dashboard_plugins';
	$interfaces['table_des_tables']['dashboard_journal']    = 'dashboard_journal';
	$interfaces['table_des_tables']['dashboard_sauvegardes'] = 'dashboard_sauvegardes';
	$interfaces['table_des_tables']['dashboard_chantiers']  = 'dashboard_chantiers';
	$interfaces['table_des_tables']['dashboard_push']       = 'dashboard_push';

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
function tourdecontrole_descriptions_tables() {
	return array_merge(
		tourdecontrole_declarer_tables_objets_sql([]),
		tourdecontrole_declarer_tables_principales([])
	);
}
