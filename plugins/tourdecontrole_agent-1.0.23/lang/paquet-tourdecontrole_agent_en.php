<?php
// This is a SPIP language file  --  Ceci est un fichier langue de SPIP

/* SPIP 4.4 attend qu'un fichier de langue **rende** son tableau, et déprécie
   le remplissage d'une globale — un avertissement par module et par langue dans
   les journaux de chaque site.

   Mais le paquet se déclare compatible depuis SPIP 4.1, dont le chargeur ne
   regarde que la globale : ne faire que rendre le tableau y effacerait toutes
   les chaînes du module, ce qui est bien pire que l'avertissement qu'on répare.

   On fait donc les deux, en choisissant sur la présence de la fonction qui a
   apporté la nouvelle forme : `lire_fichier_langue()` prend notre retour, les
   chargeurs plus anciens liront la globale. Rien ne traîne ainsi sur 4.4, où
   la clef d'`idx_lang` est temporaire et n'est jamais relue. */
$lang = [

	'tourdecontrole_agent_description' => 'Installs on this site a signed JSON entry point (HMAC-SHA256) letting a
		"control tower" site inventory the site (core version, plugins, versions), clear caches, trigger a
		database backup and drive plugin and core upgrades. As long as no shared secret is configured every
		request is rejected, and each operation can be switched off from this site.',
	'tourdecontrole_agent_slogan'      => 'Exposes this site to a SPIP fleet administration dashboard',
];

if (!function_exists('lire_fichier_langue') && isset($GLOBALS['idx_lang'])) {
	$GLOBALS[$GLOBALS['idx_lang']] = $lang;
}

return $lang;
