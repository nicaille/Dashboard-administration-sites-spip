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

	'tourdecontrole_agent_description' => 'Installe sur ce site un point d’entrée JSON signé (HMAC-SHA256) permettant
		à un site « tour de contrôle » d’inventorier le site (version du core, plugins, versions), de vider
		les caches, de déclencher une sauvegarde de la base et de piloter les mises à jour des plugins et
		du core. Tant qu’aucun secret partagé n’est configuré, toutes les requêtes sont refusées, et chaque
		opération reste désactivable depuis ce site.',
	'tourdecontrole_agent_slogan'      => 'Expose ce site à un tableau de bord d’administration de parc SPIP',
];

if (!function_exists('lire_fichier_langue') && isset($GLOBALS['idx_lang'])) {
	$GLOBALS[$GLOBALS['idx_lang']] = $lang;
}

return $lang;
