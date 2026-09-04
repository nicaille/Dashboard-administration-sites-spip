<?php
// Language file for the "Dashboard: agent" plugin — English

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

$GLOBALS[$GLOBALS['idx_lang']] = [

	// C
	'col_date'      => 'Date',
	'col_duree'     => 'Duration',
	'col_ip'        => 'Origin',
	'col_message'   => 'Message',
	'col_operation' => 'Operation',
	'col_statut'    => 'Status',
	'config_enregistree' => 'Agent configuration saved.',

	// E
	'erreur_ip'           => 'Invalid address or range: @ip@',
	'erreur_secret_court' => 'The shared secret must be at least @min@ characters long.',
	'erreur_tolerance'    => 'Clock tolerance must be between 30 and 3600 seconds.',
	'explication_configurer' => 'This agent exposes the site to a remote dashboard. As long as no shared secret is set, every request is rejected.',
	'explication_generer'    => 'The generated secret is shown only once, right after saving: copy it straight into the site record on the dashboard.',
	'explication_ips'        => 'Optional. One address or CIDR range per line (or comma separated). Empty means no address filtering — the signature remains the primary protection.',
	'explication_operations' => 'This site has the final say: the dashboard can only trigger the operations ticked here.',
	'explication_secret_clair' => 'If the secret was generated on the dashboard, paste it here. Otherwise leave empty and tick the box above.',

	// J
	'journal_caption' => 'Requests received by the agent, most recent first.',
	'journal_vide'    => 'No request received yet.',

	// L
	'label_empreinte'         => 'Fingerprint of the current secret:',
	'label_generer'           => 'Generate a new shared secret',
	'label_ips'               => 'Allowed IP addresses',
	'label_op_core_maj'       => 'Upgrade the SPIP core',
	'label_op_infos'          => 'Report the site inventory (version, plugins, caches)',
	'label_op_plugin_maj'     => 'Upgrade plugins',
	'label_op_purger'         => 'Clear caches',
	'label_op_sauvegarde'     => 'Create and hand over a database backup',
	'label_retention_backup'  => 'Keep local backups (days)',
	'label_retention_journal' => 'Keep the log (days)',
	'label_secret_clair'      => 'Shared secret provided by the dashboard',
	'label_tolerance'         => 'Clock tolerance (seconds)',
	'label_url_agent'         => 'Agent URL to declare on the dashboard:',
	'legend_appairage'        => 'Pairing',
	'legend_operations'       => 'Allowed operations',
	'legend_reglages'         => 'Settings',

	// S
	'secret_a_copier' => 'New shared secret (shown only once): @secret@',
	'secret_absent'   => 'No shared secret: the agent currently rejects every request.',
	'secret_configure' => 'A shared secret is configured: the agent is paired.',

	// T
	'titre_configurer' => 'Dashboard: agent',
	'titre_journal'    => 'Request log',
];
