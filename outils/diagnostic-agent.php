<?php
/**
 * Dit pourquoi un site géré répond « Fichier dashagent introuvable ».
 *
 * À déposer **sur le site géré**, pas sur la tour de contrôle.
 *
 * Le message vient du core, et il est précis : la tour appelle
 * `spip.php?action=dashagent`, SPIP fait `charger_fonction('dashagent',
 * 'action')`, et cet appel meurt quand `include_spip('action/dashagent')` ne
 * trouve rien (`ecrire/inc/utils.php`). Autrement dit : **le répertoire du
 * plugin n'est pas dans le chemin de SPIP**, ou le fichier n'y est pas.
 *
 * Trois familles de causes, que rien ne distingue vu de la tour :
 *
 * 1. **le plugin n'est pas actif.** Son répertoire n'est alors pas déclaré, et
 *    aucun de ses fichiers n'est atteignable. C'est le cas d'un plugin
 *    désactivé par son propre changement de dossier — voir la rubrique de
 *    `docs/exploitation.md` — ou d'un plugin que SPIP a refusé d'activer, et
 *    il dit alors pourquoi dans une meta que cette sonde relit ;
 * 2. **il est actif, mais les caches de chargement sont périmés** : ce sont des
 *    fichiers générés sous `tmp/cache/`, et SPIP ne peut pas les réécrire si ce
 *    répertoire n'est plus accessible en écriture ;
 * 3. **le dossier retenu est incomplet** — l'archive n'est pas arrivée
 *    entière, ou ce n'est pas le dossier qu'on croit.
 *
 * Elle ne modifie rien, n'envoie rien, et **n'affiche aucun secret** : ni le
 * secret partagé avec la tour, ni aucune valeur de configuration de l'agent.
 * Elle ne lit que ce qui concerne l'activation du plugin et ses fichiers.
 *
 * Usage, au choix :
 *
 *     php diagnostic-agent.php                   # déposé sous la racine du site
 *     php diagnostic-agent.php /chemin/du/site   # la racine nommée à la main
 *     https://<site-géré>/diagnostic-agent.php   # connecté en webmestre du site
 *
 * On la dépose, on lit, on la retire.
 *
 * @package SPIP\Dashboard\Outils
 */

$agent_web = PHP_SAPI !== 'cli';

/**
 * La racine SPIP, en remontant depuis le fichier, avec ce qui a été essayé.
 *
 * @param string $depart
 * @return array{racine: string, essais: array<string, string>}
 */
function agent_racine($depart) {
	$courant = rtrim((string) $depart, '/');
	$essais  = [];

	for ($i = 0; $i < 8 && $courant !== ''; $i++) {
		$cible = $courant . '/ecrire/inc_version.php';
		if (is_readable($cible)) {
			return ['racine' => $courant . '/', 'essais' => $essais];
		}
		if (!is_dir($courant)) {
			$essais[$courant] = 'répertoire absent';
		} elseif (!is_dir($courant . '/ecrire')) {
			$essais[$courant] = 'pas de ecrire/';
		} else {
			$essais[$courant] = 'ecrire/ sans inc_version.php lisible';
		}
		$parent = dirname($courant);
		if ($parent === $courant) {
			break;
		}
		$courant = $parent;
	}

	return ['racine' => '', 'essais' => $essais];
}

/**
 * Une ligne de rapport.
 *
 * @param string $texte
 * @return void
 */
function agent_dire($texte) {
	global $agent_web;

	echo $agent_web ? htmlspecialchars($texte) . "\n" : $texte . "\n";
	@flush();
}

/**
 * Un couple « question : réponse », aligné.
 *
 * @param string $quoi
 * @param mixed $valeur
 * @return void
 */
function agent_ligne($quoi, $valeur) {
	if ($valeur === true) {
		$valeur = 'oui';
	} elseif ($valeur === false) {
		$valeur = 'NON';
	} elseif ($valeur === '') {
		$valeur = '(vide)';
	} elseif ($valeur === null) {
		$valeur = '(absent)';
	}

	agent_dire(sprintf('%-48s %s', $quoi . ' :', (string) $valeur));
}

$impose = (!$agent_web && isset($argv[1])) ? rtrim((string) $argv[1], '/') : '';
$trouve = agent_racine($impose !== '' ? $impose : __DIR__);
$racine = $trouve['racine'];

if ($racine === '') {
	agent_dire('Racine SPIP introuvable' . ($impose !== '' ? ' sous ' . $impose : ' au-dessus de ' . __DIR__) . '.');
	agent_dire('');
	foreach ($trouve['essais'] as $chemin => $pourquoi) {
		agent_dire(sprintf('  %-52s %s', $chemin, $pourquoi));
	}
	agent_dire('');
	agent_dire('Déposer la sonde sous la racine du site géré, ou la nommer :');
	agent_dire('    php ' . __FILE__ . ' /chemin/du/site');
	exit(1);
}

chdir($racine);

$_SERVER += [
	'REQUEST_TIME'   => time(),
	'REQUEST_METHOD' => 'GET',
	'REQUEST_URI'    => '/',
	'SCRIPT_NAME'    => '/spip.php',
	'HTTP_HOST'      => 'localhost',
	'REMOTE_ADDR'    => '127.0.0.1',
];

// SPIP 4.4 revient en silence de `inc_version.php` quand l'autoload Composer
// n'est pas chargé : sans cette ligne, rien n'est défini et l'on croit à une
// installation cassée.
if (is_readable($racine . 'vendor/autoload.php')) {
	require_once $racine . 'vendor/autoload.php';
}
include_once $racine . 'ecrire/inc_version.php';

if (!function_exists('include_spip')) {
	agent_dire('SPIP n’a pas pu être amorcé depuis ' . $racine . ' — vendor/autoload.php absent ?');
	exit(1);
}

if ($agent_web) {
	header('Content-Type: text/html; charset=utf-8');
	echo "<!doctype html><meta charset=\"utf-8\"><title>Diagnostic de l’agent</title><pre>";

	include_spip('inc/autoriser');
	if (!autoriser('webmestre')) {
		http_response_code(403);
		agent_dire('Réservé aux webmestres de ce site. Connectez-vous à son espace privé, puis rechargez.');
		exit(1);
	}
}

include_spip('base/abstract_sql');
spip_connect();

$prefixe = 'TOURDECONTROLE_AGENT';

agent_dire('SAPI : ' . PHP_SAPI . '   racine : ' . $racine);
agent_dire('SPIP ' . ($GLOBALS['spip_version_branche'] ?? '?') . '   PHP ' . PHP_VERSION);
agent_dire(str_repeat('-', 78));

/* --- 1. Ce que SPIP tient pour actif ------------------------------------- */

agent_dire('');
agent_dire('CE QUE SPIP TIENT POUR ACTIF');
agent_dire('');

$actifs = $GLOBALS['meta']['plugin'] ?? '';
$actifs = is_string($actifs) ? (unserialize($actifs) ?: []) : [];
$fiche  = is_array($actifs) ? ($actifs[$prefixe] ?? null) : null;

agent_ligne('agent dans la liste des plugins actifs', $fiche ? 'oui' : 'NON — c’est la cause');
if ($fiche) {
	agent_ligne('  dossier déclaré', ($fiche['dir_type'] ?? '?') . ' / ' . ($fiche['dir'] ?? '?'));
	agent_ligne('  version déclarée', $fiche['version'] ?? null);
}

// Coché mais impossible à activer : SPIP le range ici, et dit pourquoi dans la
// meta suivante. C'est le cas qu'on ne devine pas.
$attente = $GLOBALS['meta']['plugin_attente'] ?? '';
$attente = is_string($attente) ? (unserialize($attente) ?: []) : [];
agent_ligne('agent en attente d’activation', is_array($attente) && isset($attente[$prefixe]) ? 'OUI' : 'non');

$erreurs = $GLOBALS['meta']['plugin_erreur_activation'] ?? '';
$erreurs = is_string($erreurs) ? (unserialize($erreurs) ?: []) : [];
if (is_array($erreurs) && $erreurs) {
	agent_dire('');
	agent_dire('  refus d’activation notés par SPIP :');
	foreach ($erreurs as $ou => $pourquoi) {
		$pourquoi = is_array($pourquoi) ? implode(' ; ', array_map('strval', $pourquoi)) : (string) $pourquoi;
		agent_dire('    ' . $ou . ' → ' . substr(trim(strip_tags($pourquoi)), 0, 160));
	}
} else {
	agent_ligne('refus d’activation notés par SPIP', '(aucun)');
}

/* --- 2. Ce que le disque porte ------------------------------------------- */

agent_dire('');
agent_dire('CE QUE LE DISQUE PORTE');
agent_dire('');

$dir_plugins = defined('_DIR_PLUGINS') ? _DIR_PLUGINS : 'plugins/';
agent_ligne('_DIR_PLUGINS', $dir_plugins);

$vus = 0;
foreach ([$dir_plugins . 'auto/*/*', $dir_plugins . '*'] as $motif) {
	foreach ((array) glob($motif, GLOB_ONLYDIR) as $chemin) {
		$paquet = $chemin . '/paquet.xml';
		if (!is_file($paquet)) {
			continue;
		}
		$xml = (string) @file_get_contents($paquet);
		if (!preg_match('/prefix\s*=\s*"([^"]+)"/i', $xml, $m) || strtoupper($m[1]) !== $prefixe) {
			continue;
		}
		$vus++;
		preg_match('/version\s*=\s*"([^"]+)"/i', $xml, $v);
		preg_match('/compatibilite\s*=\s*"([^"]+)"/i', $xml, $c);
		agent_dire('  ' . $chemin);
		agent_ligne('    version du paquet.xml', $v[1] ?? '(illisible)');
		agent_ligne('    compatibilité annoncée', $c[1] ?? '(non déclarée)');
		// Le fichier que la tour fait appeler, et lui seul : s'il manque,
		// l'activation n'y changera rien.
		agent_ligne('    action/dashagent.php présent', is_file($chemin . '/action/dashagent.php'));
		agent_ligne('    tourdecontrole_agent_fonctions.php présent',
			is_file($chemin . '/tourdecontrole_agent_fonctions.php'));
	}
}
agent_ligne('dossiers portant ce préfixe', $vus);

/* --- 3. Ce que SPIP sait atteindre --------------------------------------- */

agent_dire('');
agent_dire('CE QUE SPIP SAIT ATTEINDRE');
agent_dire('');

// La question exacte que pose la tour, posée ici.
$trouve_action = find_in_path('action/dashagent.php');
agent_ligne('find_in_path(action/dashagent.php)', $trouve_action ?: '(rien — c’est l’erreur 404)');
agent_ligne('_DIR_PLUGIN_TOURDECONTROLE_AGENT',
	defined('_DIR_PLUGIN_TOURDECONTROLE_AGENT') ? _DIR_PLUGIN_TOURDECONTROLE_AGENT : null);

$fonction = charger_fonction('dashagent', 'action', true);
agent_ligne('charger_fonction(dashagent, action)', $fonction ? 'trouvée : ' . $fonction : 'NON TROUVÉE');

/* --- 4. Les caches de chargement ----------------------------------------- */

agent_dire('');
agent_dire('LES CACHES DE CHARGEMENT');
agent_dire('');

$cache = defined('_DIR_CACHE') ? _DIR_CACHE : 'tmp/cache/';
agent_ligne('_DIR_CACHE', $cache);
agent_ligne('  accessible en écriture', is_dir($cache) && is_writable($cache));

foreach (['charger_plugins_chemins.php', 'charger_plugins_chemin.php', 'charger_plugins_options.php', 'charger_plugins_fonctions.php'] as $genere) {
	$chemin = $cache . $genere;
	if (!is_file($chemin)) {
		continue;
	}
	$contenu = (string) @file_get_contents($chemin);
	agent_ligne('  ' . $genere, date('Y-m-d H:i:s', (int) filemtime($chemin))
		. ' — mentionne l’agent : ' . (stripos($contenu, 'tourdecontrole_agent') !== false ? 'oui' : 'NON'));
}

/* --- 5. Où cette sonde tourne -------------------------------------------- */

agent_dire('');
agent_dire('OÙ CETTE SONDE TOURNE');
agent_dire('');

// À comparer avec `url_agent` sur la fiche du site, côté tour. Un hébergement
// multisite sert plusieurs répertoires depuis **une même base** : un SPIP
// atteint par un autre nom y lit le même `nom_site` et la même liste de
// plugins actifs, mais cherche les fichiers dans *son* répertoire. Il rend
// alors « Fichier dashagent introuvable » en affichant le bon nom de site —
// exactement ce qu'on voit, alors que tout est juste ici.
agent_ligne('racine absolue', realpath($racine) ?: $racine);
agent_ligne('_ROOT_RACINE', defined('_ROOT_RACINE') ? _ROOT_RACINE : null);
agent_ligne('DOCUMENT_ROOT du serveur', $_SERVER['DOCUMENT_ROOT'] ?? null);
agent_ligne('hôte par lequel on nous a joints', $_SERVER['HTTP_HOST'] ?? '(ligne de commande)');
agent_ligne('meta nom_site', $GLOBALS['meta']['nom_site'] ?? null);
agent_ligne('meta adresse_site', $GLOBALS['meta']['adresse_site'] ?? null);
agent_ligne('base de données', $GLOBALS['connexions'][0]['db'] ?? (defined('_INSTALL_NAME_DB') ? _INSTALL_NAME_DB : null));

/* --- 6. Le verdict ------------------------------------------------------- */

agent_dire('');
agent_dire('CE QU’IL FAUT EN FAIRE');
agent_dire('');

if (!$fiche) {
	agent_dire('Le plugin n’est pas actif : recocher « Tour de contrôle - agent » dans');
	agent_dire('Gestion des plugins. S’il refuse de s’activer, la raison est dans les');
	agent_dire('refus notés plus haut.');
} elseif (!$trouve_action) {
	agent_dire('Le plugin est actif mais SPIP n’atteint pas son fichier d’action.');
	agent_dire('Si un dossier ci-dessus porte bien action/dashagent.php, ce sont les');
	agent_dire('caches de chargement qui sont périmés : vider le cache depuis l’espace');
	agent_dire('privé, ou visiter Gestion des plugins, les régénère. S’ils ne peuvent');
	agent_dire('pas être réécrits, regarder les droits sur ' . $cache . '.');
} else {
	agent_dire('SPIP trouve le fichier d’action : vu d’ici, la tour devrait être reçue.');
	agent_dire('');
	agent_dire('Deux choses à vérifier dans cet ordre, côté tour :');
	agent_dire('');
	agent_dire('  1. le message affiché est-il **à jour** ? L’erreur d’une opération reste');
	agent_dire('     en base jusqu’à la synchronisation suivante : relancer une synchro sur');
	agent_dire('     ce site, et lire ce qu’elle rend, pas ce qui était écrit avant ;');
	agent_dire('  2. si la 404 revient : `url_agent` sur la fiche du site désigne-t-il bien');
	agent_dire('     le SPIP ci-dessus ? Comparer l’adresse avec l’hôte et la racine');
	agent_dire('     imprimés juste au-dessus. Un hébergement multisite sert plusieurs');
	agent_dire('     répertoires depuis une même base : un autre nom atteint un SPIP qui');
	agent_dire('     lit le même nom de site et la même liste de plugins actifs, mais');
	agent_dire('     cherche ses fichiers ailleurs — et rend cette 404 en affichant le bon');
	agent_dire('     nom de site.');
}

if ($agent_web) {
	echo '</pre>';
}
