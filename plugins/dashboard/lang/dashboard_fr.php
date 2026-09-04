<?php
// Fichier de langue du plugin « Dashboard d'administration de sites SPIP » — français

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

$GLOBALS[$GLOBALS['idx_lang']] = [

	// A
	'a_mettre_a_jour'          => 'à mettre à jour',
	'action_core_maj'          => 'Mettre à jour SPIP maintenant',
	'action_maj'               => 'Mettre à jour',
	'action_maj_tous'          => 'Tout mettre à jour',
	'action_purger'            => 'Vider',
	'action_sauvegarder'       => 'Sauvegarder la base',
	'action_sauvegarder_legere' => 'Sauvegarder sans les statistiques',
	'action_supprimer_site'    => 'Retirer ce site du parc',
	'action_sync'              => 'Synchroniser',
	'action_telecharger'       => 'Télécharger',
	'articles'                 => 'articles',

	// C
	'col_actions'    => 'Actions',
	'col_cache'      => 'Cache',
	'col_date'       => 'Date',
	'col_disponible' => 'Disponible',
	'col_etat'       => 'État',
	'col_fichiers'   => 'Fichiers',
	'col_message'    => 'Message',
	'col_operation'  => 'Opération',
	'col_plugin'     => 'Plugin',
	'col_plugins'    => 'Plugins',
	'col_site'       => 'Site',
	'col_source'     => 'Source',
	'col_spip'       => 'SPIP',
	'col_statut'     => 'Statut',
	'col_sync'       => 'Dernière synchro.',
	'col_taille'     => 'Taille',
	'col_version'    => 'Version',
	'config_enregistree' => 'Configuration enregistrée.',
	'confirmer_suppression' => 'Retirer ce site du parc ? Son inventaire et son journal seront conservés jusqu’à la prochaine purge.',
	'core_maj_avertissement' => 'Une mise à jour du core remplace les fichiers de SPIP sur le site distant. L’agent conserve l’ancienne version sur place pour permettre un retour arrière, mais vérifiez le site immédiatement après l’opération.',
	'core_maj_confirmation'  => 'Confirmer la mise à jour du core SPIP sur ce site ?',
	'core_maj_disponible'    => 'Une nouvelle version de SPIP est disponible',
	'core_maj_texte'         => 'Ce site tourne en SPIP @version_actuelle@ alors que la version @version_cible@ est disponible dans la même branche.',

	// D
	'derniere_erreur' => 'Dernière erreur :',

	// E
	'erreur_secret_court'      => 'Le secret doit faire au moins @min@ caractères.',
	'erreur_secret_obligatoire' => 'Renseignez le secret de l’agent, ou demandez la génération d’un nouveau secret.',
	'erreur_timeout'           => 'Le délai doit être compris entre 5 et 300 secondes.',
	'erreur_timeout_long'      => 'Le délai des opérations longues doit être compris entre 30 et 900 secondes.',
	'erreur_url_agent'         => 'L’URL de l’agent doit commencer par https:// (ou autoriser explicitement le http en configuration).',
	'erreur_url_agent_endpoint' => 'L’URL doit pointer sur le point d’entrée de l’agent, du type https://exemple.org/spip.php?action=dashagent',
	'erreur_url_archives'      => 'L’URL des archives doit être en https.',
	'erreur_url_site'          => 'L’adresse publique doit commencer par http:// ou https://',
	'erreur_versions_manuelles' => 'Ligne invalide : @ligne@ (format attendu : 4.2 = 4.2.16)',
	'explication_autoriser_http' => 'À réserver au développement local : en clair, le secret partagé circule en clair.',
	'explication_generer_secret' => 'Le secret est affiché une seule fois après enregistrement : recopiez-le aussitôt dans la configuration de l’agent, sur le site géré.',
	'explication_groupe'       => 'Facultatif : sert à regrouper les sites d’un même client ou d’un même hébergement.',
	'explication_sauvegarder_avant_maj' => 'Une sauvegarde rapatriée est exigée avant toute mise à jour du core. Décocher fait perdre le seul filet de sécurité sur les données.',
	'explication_secret_clair' => 'Collez ici le secret généré sur le site géré. Il n’est jamais réaffiché ensuite.',
	'explication_sync_lot'     => 'Nombre de sites interrogés à chaque passage du cron. Les sites les moins récemment synchronisés passent en premier.',
	'explication_timeout_long' => 'S’applique aux sauvegardes et aux mises à jour, qui peuvent durer plusieurs minutes.',
	'explication_url_agent'    => 'Point d’entrée du plugin « Dashboard : agent » sur le site géré, affiché sur sa page de configuration.',
	'explication_versions_manuelles' => 'Une ligne par branche, au format <code>4.2 = 4.2.16</code>. Ces valeurs priment sur l’index des archives officielles.',

	// I
	'icone_creer_site'    => 'Ajouter un site au parc',
	'icone_modifier_site' => 'Modifier ce site',
	'info_1_site'         => 'Un site géré',
	'info_aucun_site'     => 'Aucun site géré',
	'info_nb_sites'       => '@nb@ sites gérés',

	// J
	'jamais'        => 'jamais',
	'journal_vide'  => 'Aucune opération enregistrée.',

	// L
	'label_agent'              => 'Version de l’agent',
	'label_autoriser_http'     => 'Autoriser les agents en http non chiffré',
	'label_base'               => 'Base de données',
	'label_empreinte'          => 'Empreinte du secret enregistré :',
	'label_generer_secret'     => 'Générer un nouveau secret partagé',
	'label_groupe'             => 'Groupe',
	'label_notes'              => 'Notes',
	'label_retention_journal'  => 'Conserver le journal (jours)',
	'label_retention_sauvegardes' => 'Conserver les sauvegardes rapatriées (jours)',
	'label_sauvegarder_avant_maj' => 'Sauvegarder la base avant toute mise à jour du core',
	'label_secret_clair'       => 'Secret partagé',
	'label_statut'             => 'Supervision',
	'label_sync_auto'          => 'Synchroniser automatiquement le parc',
	'label_sync_frequence'     => 'Fréquence de synchronisation (heures)',
	'label_sync_lot'           => 'Sites par passage',
	'label_timeout'            => 'Délai d’attente courant (secondes)',
	'label_timeout_long'       => 'Délai des opérations longues (secondes)',
	'label_titre'              => 'Nom du site',
	'label_url_agent'          => 'URL de l’agent',
	'label_url_archives'       => 'Index des archives SPIP',
	'label_url_site'           => 'Adresse publique',
	'label_versions_manuelles' => 'Versions cibles imposées',
	'legend_identite'          => 'Identité',
	'legend_reseau'            => 'Réseau',
	'legend_securite_maj'      => 'Sécurité des mises à jour',
	'legend_secret'            => 'Secret partagé',
	'legend_supervision'       => 'Supervision',
	'legend_synchronisation'   => 'Synchronisation',
	'legend_versions'          => 'Versions de SPIP',

	// M
	'memoire' => 'de mémoire',

	// N
	'nav_parc'            => 'Vue d’ensemble du parc',
	'nav_retour_parc'     => 'Retour au parc',
	'nav_sites_en_erreur' => 'Sites injoignables',

	// O
	'operation_core_maj'        => 'Mise à jour du core',
	'operation_plugin_maj'      => 'Mise à jour de plugin',
	'operation_plugin_maj_tous' => 'Mise à jour des plugins',
	'operation_purger'          => 'Purge de cache',
	'operation_sauvegarde'      => 'Sauvegarde',
	'operation_sync'            => 'Synchronisation',

	// P
	'parc_caption'      => 'Sites du parc, regroupés puis triés par nom.',
	'parc_vide'         => 'Aucun site dans le parc pour l’instant.',
	'plugin_distribue'  => 'livré avec SPIP',
	'plugins_vide'      => 'Aucun plugin inventorié : synchronisez le site.',
	'purge_css_js'      => 'CSS et JS compactés',
	'purge_images'      => 'Images calculées',
	'purge_pages'       => 'Pages calculées',
	'purge_sessions'    => 'Sessions des visiteurs',
	'purge_squelettes'  => 'Squelettes compilés',
	'purge_tout'        => 'Tous les caches',
	'purge_tout_explication' => 'Pages, squelettes, images calculées et CSS/JS. Les sessions ne sont pas touchées.',

	// S
	'sauvegarde_distante' => 'sur le site',
	'sauvegarde_locale'   => 'rapatriée',
	'sauvegardes_vide'    => 'Aucune sauvegarde pour ce site.',
	'secret_a_copier'     => 'Nouveau secret partagé (affiché une seule fois) : @secret@',
	'secret_absent'       => 'Aucun secret enregistré : ce site ne peut pas être interrogé.',
	'secret_configure'    => 'Un secret est enregistré pour ce site.',
	'statut_pause'        => 'En pause',
	'statut_poubelle'     => 'Retiré du parc',
	'statut_supervise'    => 'Supervisé',
	'synthese_core_retard' => 'core à mettre à jour',
	'synthese_erreurs'    => 'injoignables',
	'synthese_plugins_maj' => 'plugins à mettre à jour',
	'synthese_supervises' => 'sites supervisés',

	// T
	'titre_configurer'   => 'Dashboard : configuration',
	'titre_caches'       => 'Caches',
	'titre_core_maj'     => 'Mise à jour du core SPIP',
	'titre_dashboard'    => 'Parc de sites SPIP',
	'titre_etat'         => 'État du site',
	'titre_journal'      => 'Journal des opérations',
	'titre_plugins'      => 'Plugins',
	'titre_sauvegardes'  => 'Sauvegardes',
	'titre_site'         => 'Site géré',
	'titre_sites'        => 'Sites gérés',
];
