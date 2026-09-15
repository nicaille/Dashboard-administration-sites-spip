<?php
// This is a SPIP language file  --  Ceci est un fichier langue de SPIP

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

$GLOBALS[$GLOBALS['idx_lang']] = [

	'dashagent_description' => 'Installs on this site a signed JSON entry point (HMAC-SHA256) letting a
		"control tower" site inventory the site (core version, plugins, versions), clear caches, trigger a
		database backup and drive plugin and core upgrades. As long as no shared secret is configured every
		request is rejected, and each operation can be switched off from this site.',
	'dashagent_slogan'      => 'Exposes this site to a SPIP fleet administration dashboard',
];
