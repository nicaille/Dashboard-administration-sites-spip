<?php
// Language file for the "SPIP sites administration dashboard" plugin — English

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

$GLOBALS[$GLOBALS['idx_lang']] = [

	// A
	'a_mettre_a_jour'          => 'to upgrade',
	'action_core_maj'          => 'Upgrade SPIP now',
	'action_maj'               => 'Upgrade',
	'action_maj_tous'          => 'Upgrade all',
	'action_purger'            => 'Clear',
	'action_sauvegarder'       => 'Back up the database',
	'action_sauvegarder_legere' => 'Back up without statistics',
	'action_supprimer_site'    => 'Remove this site from the fleet',
	'action_sync'              => 'Synchronise',
	'action_telecharger'       => 'Download',
	'articles'                 => 'articles',

	// C
	'col_actions'    => 'Actions',
	'col_cache'      => 'Cache',
	'col_date'       => 'Date',
	'col_disponible' => 'Available',
	'col_etat'       => 'State',
	'col_fichiers'   => 'Files',
	'col_message'    => 'Message',
	'col_operation'  => 'Operation',
	'col_plugin'     => 'Plugin',
	'col_plugins'    => 'Plugins',
	'col_site'       => 'Site',
	'col_source'     => 'Source',
	'col_spip'       => 'SPIP',
	'col_statut'     => 'Status',
	'col_sync'       => 'Last sync',
	'col_taille'     => 'Size',
	'col_version'    => 'Version',
	'config_enregistree' => 'Settings saved.',
	'confirmer_suppression' => 'Remove this site from the fleet? Its inventory and log are kept until the next cleanup.',
	'core_maj_avertissement' => 'A core upgrade replaces SPIP files on the remote site. The agent keeps the previous version in place so you can roll back, but check the site right after the operation.',
	'core_maj_confirmation'  => 'Confirm the SPIP core upgrade on this site?',
	'core_maj_disponible'    => 'A newer SPIP release is available',
	'core_maj_texte'         => 'This site runs SPIP @version_actuelle@ while @version_cible@ is available in the same branch.',

	// D
	'derniere_erreur' => 'Last error:',

	// E
	'erreur_secret_court'      => 'The shared secret must be at least @min@ characters long.',
	'erreur_secret_obligatoire' => 'Provide the agent secret, or ask for a new one to be generated.',
	'erreur_timeout'           => 'The timeout must be between 5 and 300 seconds.',
	'erreur_timeout_long'      => 'The long-operation timeout must be between 30 and 900 seconds.',
	'erreur_url_agent'         => 'The agent URL must start with https:// (or plain http must be explicitly allowed in the settings).',
	'erreur_url_agent_endpoint' => 'The URL must point at the agent entry point, e.g. https://example.org/spip.php?action=dashagent',
	'erreur_url_archives'      => 'The archive index URL must use https.',
	'erreur_url_site'          => 'The public address must start with http:// or https://',
	'erreur_versions_manuelles' => 'Invalid line: @ligne@ (expected format: 4.2 = 4.2.16)',
	'explication_autoriser_http' => 'For local development only: over plain http the shared secret travels in the clear.',
	'explication_generer_secret' => 'The secret is displayed once after saving: copy it straight into the agent settings on the managed site.',
	'explication_groupe'       => 'Optional: groups sites belonging to the same client or the same hosting.',
	'explication_sauvegarder_avant_maj' => 'A downloaded backup is required before any core upgrade. Unticking this removes the only safety net for the data.',
	'explication_secret_clair' => 'Paste here the secret generated on the managed site. It is never displayed again.',
	'explication_sync_lot'     => 'How many sites are polled on each cron pass. Least recently synchronised sites go first.',
	'explication_timeout_long' => 'Applies to backups and upgrades, which can take several minutes.',
	'explication_url_agent'    => 'Entry point of the "Dashboard: agent" plugin on the managed site, shown on its settings page.',
	'explication_versions_manuelles' => 'One line per branch, formatted <code>4.2 = 4.2.16</code>. These values take precedence over the official archive index.',

	// I
	'icone_creer_site'    => 'Add a site to the fleet',
	'icone_modifier_site' => 'Edit this site',
	'info_1_site'         => 'One managed site',
	'info_aucun_site'     => 'No managed site',
	'info_nb_sites'       => '@nb@ managed sites',

	// J
	'jamais'        => 'never',
	'journal_vide'  => 'No operation recorded yet.',

	// L
	'label_agent'              => 'Agent version',
	'label_autoriser_http'     => 'Allow agents over plain http',
	'label_base'               => 'Database',
	'label_empreinte'          => 'Fingerprint of the stored secret:',
	'label_generer_secret'     => 'Generate a new shared secret',
	'label_groupe'             => 'Group',
	'label_notes'              => 'Notes',
	'label_retention_journal'  => 'Keep the log (days)',
	'label_retention_sauvegardes' => 'Keep downloaded backups (days)',
	'label_sauvegarder_avant_maj' => 'Back up the database before every core upgrade',
	'label_secret_clair'       => 'Shared secret',
	'label_statut'             => 'Supervision',
	'label_sync_auto'          => 'Synchronise the fleet automatically',
	'label_sync_frequence'     => 'Synchronisation interval (hours)',
	'label_sync_lot'           => 'Sites per pass',
	'label_timeout'            => 'Standard timeout (seconds)',
	'label_timeout_long'       => 'Long-operation timeout (seconds)',
	'label_titre'              => 'Site name',
	'label_url_agent'          => 'Agent URL',
	'label_url_archives'       => 'SPIP archive index',
	'label_url_site'           => 'Public address',
	'label_versions_manuelles' => 'Pinned target versions',
	'legend_identite'          => 'Identity',
	'legend_reseau'            => 'Network',
	'legend_securite_maj'      => 'Upgrade safety',
	'legend_secret'            => 'Shared secret',
	'legend_supervision'       => 'Supervision',
	'legend_synchronisation'   => 'Synchronisation',
	'legend_versions'          => 'SPIP releases',

	// M
	'memoire' => 'of memory',

	// N
	'nav_parc'            => 'Fleet overview',
	'nav_retour_parc'     => 'Back to the fleet',
	'nav_sites_en_erreur' => 'Unreachable sites',

	// O
	'operation_core_maj'        => 'Core upgrade',
	'operation_plugin_maj'      => 'Plugin upgrade',
	'operation_plugin_maj_tous' => 'Plugins upgrade',
	'operation_purger'          => 'Cache purge',
	'operation_sauvegarde'      => 'Backup',
	'operation_sync'            => 'Synchronisation',

	// P
	'parc_caption'      => 'Sites in the fleet, grouped then sorted by name.',
	'parc_vide'         => 'No site in the fleet yet.',
	'plugin_distribue'  => 'shipped with SPIP',
	'plugins_vide'      => 'No plugin inventoried: synchronise the site.',
	'purge_css_js'      => 'Minified CSS and JS',
	'purge_images'      => 'Computed images',
	'purge_pages'       => 'Computed pages',
	'purge_sessions'    => 'Visitor sessions',
	'purge_squelettes'  => 'Compiled templates',
	'purge_tout'        => 'All caches',
	'purge_tout_explication' => 'Pages, templates, computed images and CSS/JS. Sessions are left alone.',

	// S
	'sauvegarde_distante' => 'on the site',
	'sauvegarde_locale'   => 'downloaded',
	'sauvegardes_vide'    => 'No backup for this site.',
	'secret_a_copier'     => 'New shared secret (shown only once): @secret@',
	'secret_absent'       => 'No secret stored: this site cannot be polled.',
	'secret_configure'    => 'A secret is stored for this site.',
	'statut_pause'        => 'Paused',
	'statut_poubelle'     => 'Removed from the fleet',
	'statut_supervise'    => 'Supervised',
	'synthese_core_retard' => 'cores to upgrade',
	'synthese_erreurs'    => 'unreachable',
	'synthese_plugins_maj' => 'plugins to upgrade',
	'synthese_supervises' => 'supervised sites',

	// T
	'titre_configurer'   => 'Dashboard: settings',
	'titre_caches'       => 'Caches',
	'titre_core_maj'     => 'SPIP core upgrade',
	'titre_dashboard'    => 'SPIP site fleet',
	'titre_etat'         => 'Site state',
	'titre_journal'      => 'Operations log',
	'titre_plugins'      => 'Plugins',
	'titre_sauvegardes'  => 'Backups',
	'titre_site'         => 'Managed site',
	'titre_sites'        => 'Managed sites',
];
