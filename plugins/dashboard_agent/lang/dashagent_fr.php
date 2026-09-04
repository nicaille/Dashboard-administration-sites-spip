<?php
// Fichier de langue du plugin « Dashboard : agent » — français

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

$GLOBALS[$GLOBALS['idx_lang']] = [

	// C
	'col_date'      => 'Date',
	'col_duree'     => 'Durée',
	'col_ip'        => 'Origine',
	'col_message'   => 'Message',
	'col_operation' => 'Opération',
	'col_statut'    => 'Statut',
	'config_enregistree' => 'Configuration de l’agent enregistrée.',

	// E
	'erreur_ip'           => 'Adresse ou plage invalide : @ip@',
	'erreur_secret_court' => 'Le secret doit faire au moins @min@ caractères.',
	'erreur_tolerance'    => 'La tolérance doit être comprise entre 30 et 3600 secondes.',
	'explication_configurer' => 'Cet agent expose ce site à un tableau de bord distant. Tant qu’aucun secret partagé n’est configuré, toutes les requêtes sont refusées.',
	'explication_generer'    => 'Le secret produit n’est affiché qu’une seule fois, juste après l’enregistrement : recopiez-le immédiatement dans la fiche du site sur le tableau de bord.',
	'explication_ips'        => 'Facultatif. Une adresse ou une plage CIDR par ligne (ou séparées par des virgules). Vide = pas de filtrage par adresse, la signature restant la protection principale.',
	'explication_operations' => 'Ce site garde le dernier mot : le tableau de bord ne peut déclencher que les opérations cochées ici.',
	'explication_secret_clair' => 'Si le secret a été généré sur le tableau de bord, collez-le ici. Sinon, laissez vide et cochez la case ci-dessus.',

	// J
	'journal_caption' => 'Requêtes reçues par l’agent, de la plus récente à la plus ancienne.',
	'journal_vide'    => 'Aucune requête reçue pour l’instant.',

	// L
	'label_empreinte'         => 'Empreinte du secret actuel :',
	'label_generer'           => 'Générer un nouveau secret partagé',
	'label_ips'               => 'Adresses IP autorisées',
	'label_op_core_maj'       => 'Mettre à jour le core SPIP',
	'label_op_infos'          => 'Communiquer l’inventaire du site (version, plugins, caches)',
	'label_op_plugin_maj'     => 'Mettre à jour les plugins',
	'label_op_purger'         => 'Vider les caches',
	'label_op_sauvegarde'     => 'Créer et transmettre une sauvegarde de la base',
	'label_retention_backup'  => 'Conserver les sauvegardes locales (jours)',
	'label_retention_journal' => 'Conserver le journal (jours)',
	'label_secret_clair'      => 'Secret partagé fourni par le tableau de bord',
	'label_tolerance'         => 'Tolérance d’horloge (secondes)',
	'label_url_agent'         => 'URL de l’agent à déclarer sur le tableau de bord :',
	'legend_appairage'        => 'Appairage',
	'legend_operations'       => 'Opérations autorisées',
	'legend_reglages'         => 'Réglages',

	// S
	'secret_a_copier' => 'Nouveau secret partagé (affiché une seule fois) : @secret@',
	'secret_absent'   => 'Aucun secret partagé : l’agent refuse actuellement toutes les requêtes.',
	'secret_configure' => 'Un secret partagé est configuré : l’agent est appairé.',

	// T
	'titre_configurer' => 'Dashboard : agent',
	'titre_journal'    => 'Journal des requêtes',
];
