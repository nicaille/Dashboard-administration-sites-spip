<?php
// Fichier de langue du plugin « Dashboard : agent » — français

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

$GLOBALS[$GLOBALS['idx_lang']] = [

	// A
	'action_supprimer_sauvegarde' => 'Supprimer',
	'alerte_autorites'            => 'Téléchargements https impossibles sur ce serveur :',

	// C
	'col_actions'        => 'Actions',
	'col_date'           => 'Date',
	'col_duree'          => 'Durée',
	'col_fichier'        => 'Fichier',
	'col_ip'             => 'Origine',
	'col_message'        => 'Message',
	'col_operation'      => 'Opération',
	'col_poids'          => 'Poids',
	'col_statut'         => 'Statut',
	'config_enregistree' => 'Configuration de l’agent enregistrée.',

	// E
	'confirmer_suppression_sauvegarde' => 'Supprimer définitivement cette sauvegarde de ce site ?',
	'erreur_ip'                        => 'Adresse ou plage invalide : @ip@',
	'erreur_non_autorise'              => 'Vous n’avez pas le droit d’effectuer cette opération.',
	'erreur_secret_court'              => 'Le secret doit faire au moins @min@ caractères.',
	'erreur_tolerance'                 => 'La tolérance doit être comprise entre 30 et 3600 secondes.',
	'explication_configurer'           => 'Cet agent expose ce site à un tableau de bord distant. Tant qu’aucun secret partagé n’est configuré, toutes les requêtes sont refusées.',
	'explication_generer'              => 'Le secret produit n’est affiché qu’une seule fois, juste après l’enregistrement : recopiez-le immédiatement dans la fiche du site sur le tableau de bord.',
	'explication_ips'                  => 'Facultatif. Une adresse ou une plage CIDR par ligne (ou séparées par des virgules). Vide = pas de filtrage par adresse, la signature restant la protection principale.',
	'explication_op_loader' => 'Autorise le remplacement du <code>spip_loader.php</code> déposé à la racine du site. Ce script installe ce qu’on lui dit d’installer : c’est le geste le plus lourd de conséquences qu’on puisse accorder à distance.',
	'explication_op_serveur'           => 'Donne au tableau de bord la lecture de phpinfo(), du contenu des tables et de trois fichiers de réglage (.htaccess, config/mes_options.php, squelettes/mes_fonctions.php). En lecture seule, et les valeurs qui ressemblent à des identifiants sont masquées avant de partir — mais cela reste la plus indiscrète des autorisations.',
	'explication_op_waf' => 'Autorise la tour de contrôle à lire les chiffres du pare-feu applicatif : requêtes bloquées, adresses bannies, motifs. Ce sont des données de sécurité, et elles nomment des visiteurs.',
	'explication_operations'           => 'Ce site garde le dernier mot : le tableau de bord ne peut déclencher que les opérations cochées ici.',
	'explication_sauvegardes'          => 'Les sauvegardes produites à la demande de la tour de contrôle sont déposées sous <code>tmp/dashagent/sauvegardes/</code>, hors de l’espace web. Elles portent la base entière de ce site : ce tableau permet de constater ce qui existe, et de le supprimer sans attendre l’expiration de la rétention. Une copie a normalement été rapatriée sur la tour de contrôle.',
	'explication_secret_clair'         => 'Si le secret a été généré sur le tableau de bord, collez-le ici. Sinon, laissez vide et cochez la case ci-dessus.',
	'explication_tables_absentes'      => 'Les tables de ce plugin n’existent pas en base : son installation ne s’est pas terminée. Désinstallez le plugin depuis « Configuration → Gestion des plugins » (désinstaller, pas seulement désactiver), puis réactivez-le : les tables seront créées.',

	// J
	'journal_caption' => 'Requêtes reçues par l’agent, de la plus récente à la plus ancienne.',
	'journal_vide'    => 'Aucune requête reçue pour l’instant.',

	// L
	'label_empreinte'         => 'Empreinte du secret actuel :',
	'label_generer'           => 'Générer un nouveau secret partagé',
	'label_ips'               => 'Adresses IP autorisées',
	'label_op_core_maj'       => 'Mettre à jour le core SPIP et migrer sa base',
	'label_op_infos'          => 'Communiquer l’inventaire du site (version, plugins, caches)',
	'label_op_loader' => 'Mettre à jour spip_loader.php',
	'label_op_plugin_maj'     => 'Mettre à jour les plugins',
	'label_op_purger'         => 'Vider les caches',
	'label_op_sauvegarde'     => 'Créer et transmettre une sauvegarde de la base',
	'label_op_serveur'        => 'Consulter l’état du serveur',
	'label_op_waf' => 'Consulter le tableau de bord SPIP WAF',
	'label_retention_backup'  => 'Conserver les sauvegardes locales (jours)',
	'label_retention_journal' => 'Conserver le journal (jours)',
	'label_secret_clair'      => 'Secret partagé fourni par le tableau de bord',
	'label_tolerance'         => 'Tolérance d’horloge (secondes)',
	'label_url_agent'         => 'URL de l’agent à déclarer sur le tableau de bord :',
	'legend_appairage'        => 'Appairage',
	'legend_operations'       => 'Opérations autorisées',
	'legend_reglages'         => 'Réglages',

	// S
	'sauvegarde_introuvable' => 'Sauvegarde introuvable : elle a peut-être déjà été supprimée.',
	'sauvegarde_supprimee'   => 'Sauvegarde supprimée.',
	'sauvegardes_caption'    => '@nb@ sauvegarde(s) sur ce site',
	'sauvegardes_vide'       => 'Aucune sauvegarde sur ce site.',
	'secret_a_copier'        => 'Nouveau secret partagé (affiché une seule fois) : @secret@',
	'secret_absent'          => 'Aucun secret partagé : l’agent refuse actuellement toutes les requêtes.',
	'secret_configure'       => 'Un secret partagé est configuré : l’agent est appairé.',

	// T
	'titre_configurer'  => 'Dashboard : agent',
	'titre_journal'     => 'Journal des requêtes',
	'titre_sauvegardes' => 'Sauvegardes présentes sur ce site',
];
