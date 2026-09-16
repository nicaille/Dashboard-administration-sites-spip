<?php
// This is a SPIP language file  --  Ceci est un fichier langue de SPIP

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

$GLOBALS[$GLOBALS['idx_lang']] = [

	'dashagent_description' => 'Installe sur ce site un point d’entrée JSON signé (HMAC-SHA256) permettant
		à un site « tour de contrôle » d’inventorier le site (version du core, plugins, versions), de vider
		les caches, de déclencher une sauvegarde de la base et de piloter les mises à jour des plugins et
		du core. Tant qu’aucun secret partagé n’est configuré, toutes les requêtes sont refusées, et chaque
		opération reste désactivable depuis ce site.',
	'dashagent_slogan'      => 'Expose ce site à un tableau de bord d’administration de parc SPIP',
];
