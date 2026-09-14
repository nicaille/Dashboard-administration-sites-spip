<?php
/**
 * Vérifications hors-SPIP du protocole et des garde-fous.
 *
 * Usage : php tests/test_protocole.php
 */

require_once __DIR__ . '/bootstrap.php';

$echecs = 0;
$total  = 0;

/**
 * @param string $titre
 * @param bool $condition
 * @param string $detail
 * @return void
 */
function verifier($titre, $condition, $detail = '') {
	global $echecs, $total;
	$total++;
	if ($condition) {
		echo "  ok   $titre\n";
	} else {
		$echecs++;
		echo "  ÉCHEC $titre" . ($detail ? " — $detail" : '') . "\n";
	}
}

echo "\n== Signature partagée entre le tableau de bord et l'agent ==\n";

$secret = str_repeat('a1b2', 16);
$ts     = 1700000000;
$nonce  = 'deadbeefdeadbeef';
$args   = json_encode(['cibles' => ['pages', 'images']], JSON_UNESCAPED_SLASHES);

$champs = dashboard_signer('purger', $args, $ts, $nonce, $secret);
$attendue = dashagent_signer('purger', $args, $ts, $nonce, $secret);

verifier('les deux extrémités produisent la même signature', hash_equals($attendue, $champs['sig']));
verifier('la signature fait 64 caractères hexadécimaux', (bool) preg_match('/^[a-f0-9]{64}$/', $champs['sig']));
verifier(
	'changer un seul argument change la signature',
	dashagent_signer('purger', $args . ' ', $ts, $nonce, $secret) !== $attendue
);
verifier(
	'changer l’opération change la signature',
	dashagent_signer('core_maj', $args, $ts, $nonce, $secret) !== $attendue
);
verifier(
	'changer le nonce change la signature',
	dashagent_signer('purger', $args, $ts, 'cafecafecafecafe', $secret) !== $attendue
);
verifier(
	'un secret voisin ne signe pas pareil',
	dashagent_signer('purger', $args, $ts, $nonce, $secret . 'x') !== $attendue
);

echo "\n== Liste blanche d'adresses IP ==\n";

$GLOBALS['dashagent_config_test']['ips_autorisees'] = '';
verifier('liste vide : tout passe', dashagent_ip_autorisee('203.0.113.9'));

$GLOBALS['dashagent_config_test']['ips_autorisees'] = '203.0.113.9, 198.51.100.0/24, 2001:db8::/32';
verifier('adresse exacte acceptée', dashagent_ip_autorisee('203.0.113.9'));
verifier('adresse hors liste refusée', !dashagent_ip_autorisee('203.0.113.10'));
verifier('CIDR v4 : intérieur accepté', dashagent_ip_autorisee('198.51.100.200'));
verifier('CIDR v4 : extérieur refusé', !dashagent_ip_autorisee('198.51.101.1'));
verifier('CIDR v6 : intérieur accepté', dashagent_ip_autorisee('2001:db8:1234::1'));
verifier('CIDR v6 : extérieur refusé', !dashagent_ip_autorisee('2001:db9::1'));
verifier('adresse vide refusée quand une liste existe', !dashagent_ip_autorisee(''));
verifier('adresse malformée refusée', !dashagent_ip_autorisee('pas-une-ip'));

$GLOBALS['dashagent_config_test']['ips_autorisees'] = '198.51.100.0/25';
verifier('masque non aligné sur un octet : intérieur', dashagent_ip_autorisee('198.51.100.127'));
verifier('masque non aligné sur un octet : extérieur', !dashagent_ip_autorisee('198.51.100.128'));

echo "\n== Secrets ==\n";

$s1 = dashagent_generer_secret();
$s2 = dashagent_generer_secret();
verifier('secret de 64 caractères hexadécimaux', (bool) preg_match('/^[a-f0-9]{64}$/', $s1));
verifier('deux secrets successifs diffèrent', $s1 !== $s2);
verifier('longueur au-dessus du minimum exigé', strlen($s1) >= _DASHAGENT_SECRET_LONGUEUR_MIN);
verifier('le tableau de bord génère le même format', (bool) preg_match('/^[a-f0-9]{64}$/', dashboard_generer_secret()));

echo "\n== Contrôle des archives ==\n";

$base = _DIR_TMP . 'archives/';
@mkdir($base . 'gis', 0777, true);
file_put_contents($base . 'gis/paquet.xml', '<paquet prefix="gis" version="5.2.1" etat="stable"></paquet>');

$controle = dashagent_verifier_archive_plugin($base . 'gis', 'GIS');
verifier('archive conforme acceptée', $controle['ok'], $controle['erreur']);
verifier('version extraite du paquet.xml', $controle['version'] === '5.2.1', $controle['version']);

$mauvais = dashagent_verifier_archive_plugin($base . 'gis', 'SAISIES');
verifier('archive d’un autre plugin refusée', !$mauvais['ok']);

@mkdir($base . 'vide', 0777, true);
verifier('archive sans paquet.xml refusée', !dashagent_verifier_archive_plugin($base . 'vide', 'GIS')['ok']);

@mkdir($base . 'core/ecrire', 0777, true);
file_put_contents($base . 'core/spip.php', '<?php');
file_put_contents($base . 'core/ecrire/inc_version.php', '<?php $spip_version_branche = "4.2.16";');
$core = dashagent_verifier_archive_core($base . 'core');
verifier('distribution SPIP reconnue', $core['ok'], $core['erreur']);
verifier('version du core lue', $core['version'] === '4.2.16', $core['version']);
verifier('version inattendue refusée', !dashagent_verifier_archive_core($base . 'core', '4.4.0')['ok']);
verifier('archive incomplète refusée', !dashagent_verifier_archive_core($base . 'vide')['ok']);

echo "\n== URL d'archive d'un dépôt SVP ==\n";

// Relevé dans plugins-dist/svp/inc/svp_actionner.php : l'URL téléchargeable est
// « url_archives du dépôt » + « nom_archive du paquet ». src_archive désigne
// selon les cas un chemin local ou l'adresse d'un dépôt git : jamais un zip.
$depot = ['url_archives' => 'https://files.spip.net/spip-zone/', 'nom_archive' => 'cextras.zip',
	'src_archive' => 'auto/cextras/v4.3.0/', 'type_depot' => 'http'];

verifier(
	'URL composée depuis url_archives et nom_archive',
	dashagent_url_archive_depot($depot) === 'https://files.spip.net/spip-zone/cextras.zip',
	dashagent_url_archive_depot($depot)
);
verifier(
	'src_archive n’est jamais utilisée comme URL',
	strpos(dashagent_url_archive_depot($depot), 'auto/') === false
);
verifier(
	'barre oblique finale non doublée',
	dashagent_url_archive_depot(['url_archives' => 'https://exemple.org/zips', 'nom_archive' => 'gis.zip'])
		=== 'https://exemple.org/zips/gis.zip'
);
verifier(
	'dépôt sans archive nommée : pas d’URL',
	dashagent_url_archive_depot(['url_archives' => 'https://exemple.org/zips', 'nom_archive' => '']) === ''
);
verifier(
	'dépôt sans conteneur d’archives : pas d’URL',
	dashagent_url_archive_depot(['url_archives' => '', 'nom_archive' => 'gis.zip']) === ''
);
// Le `type` du dépôt décrit ses sources, pas le transport de l'archive :
// choisir_teleporteur() retourne « http » par défaut, y compris pour un dépôt
// git ou svn. Filtrer là-dessus écarterait plugins.spip.net.
verifier(
	'dépôt git : archive tout de même servie en http',
	dashagent_url_archive_depot(['url_archives' => 'https://exemple.org/zips',
		'nom_archive' => 'gis.zip', 'type_depot' => 'git']) === 'https://exemple.org/zips/gis.zip'
);
verifier(
	'dépôt svn : archive tout de même servie en http',
	dashagent_url_archive_depot(['url_archives' => 'https://exemple.org/zips',
		'nom_archive' => 'gis.zip', 'type_depot' => 'svn']) === 'https://exemple.org/zips/gis.zip'
);
verifier(
	'type de dépôt non renseigné : sans effet',
	dashagent_url_archive_depot(['url_archives' => 'https://exemple.org/zips',
		'nom_archive' => 'gis.zip']) === 'https://exemple.org/zips/gis.zip'
);

echo "\n== Inventaire des plugins ==\n";

// SPIP range dans la meta « plugin » les capacités fournies — extensions PHP,
// bibliothèques — sous la forme procure:xxx. Ce ne sont pas des plugins.
verifier('plugin réel retenu', dashagent_est_un_plugin(
	['dir' => 'dashboard/', 'dir_type' => '_DIR_PLUGINS']));
verifier('plugin livré avec SPIP retenu', dashagent_est_un_plugin(
	['dir' => 'medias', 'dir_type' => '_DIR_PLUGINS_DIST']));
verifier('extension PHP écartée', !dashagent_est_un_plugin(
	['dir' => 'procure:php:gd', 'dir_type' => '_DIR_RESTREINT']));
verifier('capacité fournie par un plugin écartée', !dashagent_est_un_plugin(
	['dir' => 'compresseur/procure:csstidy', 'dir_type' => '_DIR_PLUGINS_DIST']));
verifier('core SPIP lui-même écarté', !dashagent_est_un_plugin(
	['dir' => '', 'dir_type' => '_DIR_RESTREINT']));

echo "\n== Versions normalisées par SVP ==\n";

verifier('004.003.003 redevient 4.3.3', dashagent_denormaliser_version('004.003.003') === '4.3.3',
	dashagent_denormaliser_version('004.003.003'));
verifier('001.002.000-dev redevient 1.2.0-dev',
	dashagent_denormaliser_version('001.002.000-dev') === '1.2.0-dev',
	dashagent_denormaliser_version('001.002.000-dev'));
verifier('une version déjà lisible est laissée telle quelle',
	dashagent_denormaliser_version('4.3.3') === '4.3.3');
verifier('version vide sans effet', dashagent_denormaliser_version('') === '');

echo "\n== Noms de plugins multilingues ==\n";

$multi = '[fr]Le Couteau Suisse[en]Swiss Knife[es]La Navaja Suiza';
verifier('langue du site retenue', dashagent_texte_multi($multi, 'fr') === 'Le Couteau Suisse',
	dashagent_texte_multi($multi, 'fr'));
verifier('autre langue retenue', dashagent_texte_multi($multi, 'es') === 'La Navaja Suiza');
verifier('repli sur le français si la langue manque',
	dashagent_texte_multi($multi, 'de') === 'Le Couteau Suisse');
verifier('nom simple laissé intact', dashagent_texte_multi('GIS') === 'GIS');
verifier('crochet isolé sans balise de langue laissé intact',
	dashagent_texte_multi('Plugin [beta]') === 'Plugin [beta]');

echo "\n== Extraction ZIP : refus des chemins hors répertoire ==\n";

$zip_ok = _DIR_TMP . 'sain.zip';
@unlink($zip_ok);
$z = new ZipArchive();
$z->open($zip_ok, ZipArchive::CREATE);
$z->addFromString('monplugin/paquet.xml', '<paquet prefix="monplugin" version="1.0.0"></paquet>');
$z->close();

$extrait = dashagent_dezipper($zip_ok, _DIR_TMP . 'extrait-sain/');
verifier('archive saine extraite', $extrait['ok'], $extrait['erreur']);
verifier('racine unique remontée', basename($extrait['racine']) === 'monplugin', $extrait['racine']);

$zip_ko = _DIR_TMP . 'traversant.zip';
@unlink($zip_ko);
$z = new ZipArchive();
$z->open($zip_ko, ZipArchive::CREATE);
$z->addFromString('../../evasion.php', '<?php');
$z->close();

$refus = dashagent_dezipper($zip_ko, _DIR_TMP . 'extrait-ko/');
verifier('chemin traversant refusé', !$refus['ok'], $refus['erreur']);
verifier('rien n’a été écrit hors du répertoire', !file_exists(_DIR_TMP . 'evasion.php'));

echo "\n== Téléchargements ==\n";

$http = dashagent_telecharger('http://exemple.test/paquet.zip', _DIR_TMP . 'refus.zip');
verifier('téléchargement en clair refusé', !$http['ok'], $http['erreur']);
verifier('aucun fichier créé pour un refus', !file_exists(_DIR_TMP . 'refus.zip'));

echo "\n== Répertoires du core protégés ==\n";

$intouchables = dashagent_core_intouchables();
foreach (['config', 'IMG', 'local', 'tmp', 'plugins', 'squelettes'] as $dir) {
	verifier("« $dir » n’est jamais remplacé par une mise à jour", in_array($dir, $intouchables, true));
}
verifier(
	'aucun répertoire remplaçable n’est aussi intouchable',
	!array_intersect(dashagent_core_remplacables(), $intouchables)
);

echo "\n== URL d'agent acceptées ==\n";

$GLOBALS['dashboard_config_test'] = [];
verifier('https accepté', dashboard_url_acceptable('https://exemple.org/spip.php?action=dashagent'));
verifier('http refusé par défaut', !dashboard_url_acceptable('http://exemple.org/spip.php?action=dashagent'));
verifier('schéma exotique refusé', !dashboard_url_acceptable('ftp://exemple.org/'));
$GLOBALS['dashboard_config_test']['autoriser_http'] = 'on';
verifier('http accepté une fois autorisé explicitement', dashboard_url_acceptable('http://exemple.org/'));

echo "\n== URL des archives SPIP ==\n";

$GLOBALS['dashboard_config_test'] = ['url_archives_spip' => 'https://files.spip.net/spip/archives/'];
require_once __DIR__ . '/../plugins/dashboard/inc/dashboard_versions.php';
verifier(
	'URL construite pour une version valide',
	dashboard_url_archive_spip('4.2.16') === 'https://files.spip.net/spip/archives/SPIP-v4.2.16.zip',
	dashboard_url_archive_spip('4.2.16')
);
verifier('version fantaisiste refusée', dashboard_url_archive_spip('../../etc/passwd') === '');
verifier('version vide refusée', dashboard_url_archive_spip('') === '');

echo "\n== Versions cibles ==\n";

$GLOBALS['dashboard_config_test']['versions_manuelles'] = "4.2 = 4.2.16\n4.4 = 4.4.2\nligne ignorée";
$manuelles = dashboard_versions_manuelles();
verifier('deux branches lues', count($manuelles) === 2, json_encode($manuelles));
verifier('branche 4.2 correcte', ($manuelles['4.2'] ?? '') === '4.2.16');
verifier('ligne invalide ignorée', !isset($manuelles['ligne']));

echo "\n== Ce qui ne doit pas quitter un site géré ==\n";

/* Le masquage tient à un seul prédicat : s'il se trompe, des empreintes de
   mots de passe et des clés d'API traversent le réseau. */
$sensibles = ['pass', 'htpass', 'password', 'DB_PASSWORD', 'low_sec', 'alea_actuel',
	'alea_futur', 'cookie_oubli', 'backup_cles', 'HTTP_COOKIE', 'GITHUB_TOKEN',
	'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'API_KEY', 'api-key', 'MA_CLE_API',
	'apikey', 'jeton_secret', 'DATABASE_DSN', 'private_key', 'session_salt', 'prefs'];
$anodins = ['nom', 'titre', 'version', 'descriptif', 'email', 'login', 'statut',
	'date', 'maj', 'id_article', 'monkey_cache', 'keyboard_layout', 'HTTP_HOST',
	'memory_limit', 'url_site'];

$rates = array_values(array_filter($sensibles, function ($n) { return !dashagent_nom_sensible($n); }));
verifier('tous les noms parlants sont reconnus', $rates === [], implode(', ', $rates));
$faux = array_values(array_filter($anodins, function ($n) { return dashagent_nom_sensible($n); }));
verifier('aucun nom anodin n’est masqué à tort', $faux === [], implode(', ', $faux));

/* Le nom ne dit pas tout : une URL peut porter ses identifiants. */
verifier('URL à identifiants reconnue', dashagent_valeur_sensible('https://bob:hunter2@miroir.test/'));
verifier('URL ordinaire épargnée', !dashagent_valeur_sensible('https://exemple.org/archives.zip'));
verifier('valeur non textuelle ignorée', !dashagent_valeur_sensible(42));

verifier('la marque ne laisse rien filtrer',
	strpos(dashagent_masquer('hunter2'), 'hunter2') === false, dashagent_masquer('hunter2'));
verifier('la marque dit la longueur',
	strpos(dashagent_masquer('hunter2'), '7 caractères') !== false, dashagent_masquer('hunter2'));
verifier('une valeur vide reste vide', dashagent_masquer('') === '');

echo "\n== Masquage d’un fichier de configuration ==\n";

$options = <<<'PHP'
<?php
define('_DASHAGENT_ARCHIVES_HTTP', true);
define('_MON_API_KEY', 'sk-live-3f9a2b7c4d1e');
define('_SMTP_PASSWORD', "correct horse battery");
define('_SMTP_HOST', 'smtp.exemple.org');
const STRIPE_SECRET = 'sk_test_51Hxxxx';
$GLOBALS['ldap_password'] = 'tr3sSecret';
$reglages = ['token' => 'abcdef123456', 'delai' => '30'];
$miroir = 'https://depot:s3cr3t@archives.test/';
define('_DEBUG', false);
PHP;
$masque = dashagent_masquer_texte($options);

foreach (['sk-live-3f9a2b7c4d1e', 'correct horse battery', 'sk_test_51Hxxxx',
	'tr3sSecret', 'abcdef123456', 's3cr3t'] as $secret) {
	verifier('« ' . substr($secret, 0, 14) . ' » ne sort pas', strpos($masque, $secret) === false);
}
foreach (['_DASHAGENT_ARCHIVES_HTTP', 'smtp.exemple.org', "'delai' => '30'", '_DEBUG'] as $utile) {
	verifier('« ' . $utile . ' » reste lisible', strpos($masque, $utile) !== false);
}
verifier('les noms des constantes restent visibles',
	strpos($masque, '_SMTP_PASSWORD') !== false && strpos($masque, 'STRIPE_SECRET') !== false);
verifier('l’hôte du miroir reste lisible', strpos($masque, 'archives.test') !== false, $masque);

echo "\n== Fichiers consultables ==\n";

$lisibles = dashagent_serveur_fichiers_lisibles();
verifier('trois fichiers, et trois seulement', count($lisibles) === 3, implode(', ', $lisibles));
verifier('config/connect.php n’en fait pas partie',
	!in_array('config/connect.php', $lisibles, true));
foreach (['../config/connect.php', '/etc/passwd', 'connect', ''] as $fabrique) {
	$reponse = dashagent_serveur_fichier(['fichier' => $fabrique]);
	verifier('chemin refusé : ' . ($fabrique ?: '(vide)'), $reponse['ok'] === false);
}

echo "\n== Colonnes et tri d’un parcours de table ==\n";

$colonnes = ['id_auteur', 'nom', 'login', 'pass'];
verifier('une colonne réelle est retrouvée',
	dashagent_serveur_colonne_connue('LOGIN', $colonnes) === 'login');
verifier('une colonne inventée est écartée',
	dashagent_serveur_colonne_connue('id_auteur; DROP TABLE x', $colonnes) === '');
verifier('une colonne absente est écartée',
	dashagent_serveur_colonne_connue('nimporte', $colonnes) === '');

verifier('filtrer sur une colonne absente est refusé',
	dashagent_serveur_filtre(['filtre_colonne' => 'x', 'filtre_valeur' => 'a'], $colonnes) === false);
verifier('filtrer sur une colonne masquée est refusé',
	dashagent_serveur_filtre(['filtre_colonne' => 'pass', 'filtre_valeur' => 'a'], $colonnes) === false,
	'sinon le masque se devine caractère par caractère');
verifier('sans filtre, pas de clause',
	dashagent_serveur_filtre([], $colonnes) === '');

/* Le masquage des lignes : c'est la colonne qui décide, sauf pour spip_meta. */
$description = [
	['nom' => 'id_auteur', 'masquee' => false],
	['nom' => 'login', 'masquee' => false],
	['nom' => 'pass', 'masquee' => true],
];
$rendu = dashagent_serveur_masquer_lignes(
	[['id_auteur' => 1, 'login' => 'admin', 'pass' => '$2y$10$abcdefghijklmnop']],
	$description
);
verifier('la colonne masquée l’est', strpos($rendu[0]['pass'], '$2y$') === false, $rendu[0]['pass']);
verifier('les autres colonnes passent', $rendu[0]['login'] === 'admin');

$metas = dashagent_serveur_masquer_lignes(
	[['nom' => 'dashagent', 'valeur' => 'c2:secret'], ['nom' => 'charset', 'valeur' => 'utf-8']],
	[['nom' => 'nom', 'masquee' => false], ['nom' => 'valeur', 'masquee' => false]]
);
verifier('le secret de l’agent est masqué dans spip_meta',
	strpos($metas[0]['valeur'], 'secret') === false, $metas[0]['valeur']);
verifier('une meta anodine reste lisible', $metas[1]['valeur'] === 'utf-8');

echo "\n== Schéma de base d’un site géré ==\n";

/* `dashagent_base_etat()` lit deux globales de SPIP : ce que les fichiers
   attendent, et ce que la base contient. */
$etat_base = function ($attendue, $installee) {
	$GLOBALS['spip_version_base'] = $attendue;
	$GLOBALS['meta']['version_installee'] = $installee;

	return dashagent_base_etat();
};

$a_jour = $etat_base('2026080300', '2026080300');
verifier('schéma à jour : rien à faire', $a_jour['maj_requise'] === false);
verifier('schéma à jour : base pas en avance', $a_jour['base_plus_recente'] === false);

$retard = $etat_base('2026080300', '2026010100');
verifier('schéma en retard : migration due', $retard['maj_requise'] === true);
verifier('schéma en retard : versions rendues', $retard['version_base'] === '2026010100'
	&& $retard['version_base_attendue'] === '2026080300');

/* Une base plus récente que les fichiers signale une double installation ou un
   retour arrière : migrer aggraverait les choses. */
$avance = $etat_base('2026010100', '2026080300');
verifier('base plus récente : signalée', $avance['base_plus_recente'] === true);
verifier('base plus récente : préflight bloquant', dashagent_base_preflight()['ok'] === false);
verifier('base plus récente : refus expliqué',
	strpos(dashagent_base_preflight()['erreur'], 'plus récente') !== false,
	dashagent_base_preflight()['erreur']);

/* Certains hébergeurs enregistrent la version avec une virgule décimale. */
$virgule = $etat_base('2026080300', '2026080300');
$GLOBALS['meta']['version_installee'] = '2026080300';
verifier('version à virgule tolérée', dashagent_base_etat()['maj_requise'] === false);

echo "\n== Journal de migration ==\n";

$brut = "<div>MAJ 2026080300 <span title='0'>.</span></div><br>MAJ 2026080400 .<br>"
	. 'HTTP 302<br>Si votre navigateur n’est pas redirigé, cliquez ici pour continuer.';
$lisible = dashagent_base_journal_lisible($brut);
verifier('les paliers sont conservés', strpos($lisible, 'MAJ 2026080300') !== false, $lisible);
verifier('le second palier aussi', strpos($lisible, 'MAJ 2026080400') !== false, $lisible);
verifier('la redirection est coupée', strpos($lisible, 'cliquez ici') === false, $lisible);
verifier('le balisage a disparu', strpos($lisible, '<span') === false);

echo "\n== Étapes d’un chantier ==\n";

foreach (['plugin_maj', 'plugin_maj_tous', 'core_maj', 'base_maj'] as $operation) {
	$etapes = dashboard_chantier_etapes($operation);
	verifier("$operation : commence par une sauvegarde", ($etapes[0] ?? '') === 'sauvegarde',
		implode(' → ', $etapes));
	verifier("$operation : finit par une synchronisation", end($etapes) === 'sync');
}
verifier('la mise à jour du core migre aussi le schéma',
	in_array('base', dashboard_chantier_etapes('core_maj'), true),
	implode(' → ', dashboard_chantier_etapes('core_maj')));
verifier('les plugins sont relistés juste avant d’être mis à jour',
	dashboard_chantier_etapes('plugin_maj_tous') === ['sauvegarde', 'sync', 'plugins', 'sync']);
verifier('opération inconnue : aucune étape', dashboard_chantier_etapes('rm_rf') === []);
verifier('opération inconnue : refusée', dashboard_chantier_operation_connue('rm_rf') === false);
verifier('opération connue : acceptée', dashboard_chantier_operation_connue('core_maj') === true);

echo "\n== État d’un chantier ==\n";

$fabriquer = function ($champs = []) {
	return array_merge([
		'id_dashboard_chantier' => 7, 'id_dashboard_site' => 1, 'operation' => 'core_maj',
		'cible' => '', 'etape' => 'sauvegarde', 'statut' => 'encours', 'rang' => 0,
		'total' => 5, 'tentatives' => 1, 'message' => '', 'detail' => '', 'reste' => '',
	], $champs);
};

verifier('un chantier en cours n’est pas fini', dashboard_chantier_fini($fabriquer()) === false);
verifier('un chantier réussi est fini', dashboard_chantier_fini($fabriquer(['statut' => 'ok'])) === true);
verifier('un chantier en échec est fini', dashboard_chantier_fini($fabriquer(['statut' => 'erreur'])) === true);
verifier('un chantier absent est fini', dashboard_chantier_fini(null) === true);

verifier('le résumé situe l’étape',
	dashboard_chantier_resume($fabriquer(['rang' => 2])) === 'Mise à jour du core SPIP (étape 3/5)',
	dashboard_chantier_resume($fabriquer(['rang' => 2])));
verifier('le libellé nomme le plugin visé',
	dashboard_chantier_libelle('plugin_maj', 'CEXTRAS') === 'Mise à jour du plugin CEXTRAS');

verifier('aucun échec retenu au départ', dashboard_chantier_echecs($fabriquer()) === []);
$avec_echec = $fabriquer(['detail' => json_encode(['echecs' => ['CEXTRAS' => 'HTTP 404']])]);
verifier('les échecs sont relus', array_keys(dashboard_chantier_echecs($avec_echec)) === ['CEXTRAS']);
verifier('un détail illisible ne casse rien',
	dashboard_chantier_echecs($fabriquer(['detail' => 'pas du json'])) === []);

echo "\n== Magasin d’autorités de certification ==\n";

/* Reproduit une pile locale pour Windows : OpenSSL annonce des chemins compilés
   qui n'existent pas sur la machine, et alors aucun https ne passe. */
$wamp = dashagent_magasin_autorites([
	'default_cert_file' => 'C:\\ci\\ca-bundle.crt',
	'default_cert_dir'  => 'C:\\ci\\certs',
]);
verifier('magasin annoncé mais absent : refusé', $wamp['ok'] === false);
verifier('le chemin fautif est nommé', $wamp['chemin'] === 'C:\\ci\\ca-bundle.crt', $wamp['chemin']);

$aucun = dashagent_magasin_autorites([]);
verifier('aucun emplacement annoncé : refusé', $aucun['ok'] === false);
verifier('aucun chemin à montrer', $aucun['chemin'] === '');

$systeme = dashagent_magasin_autorites(['default_cert_dir' => '/usr/lib/ssl/certs']);
verifier('répertoire système peuplé : accepté', $systeme['ok'] === true, json_encode($systeme));

$vide = sys_get_temp_dir() . '/dashagent-ca-vide';
@mkdir($vide);
verifier('répertoire vide : refusé', dashagent_magasin_autorites(['default_cert_dir' => $vide])['ok'] === false);
@rmdir($vide);

$conseil = dashagent_conseil_autorites();
verifier('le conseil est renseigné', is_string($conseil) && $conseil !== '');

verifier('URL sans hôte diagnostiquée', dashagent_cause_echec_reseau('pas-une-url') === 'URL illisible',
	dashagent_cause_echec_reseau('pas-une-url'));
verifier('hôte inexistant diagnostiqué',
	strpos(dashagent_cause_echec_reseau('https://hote-absent.invalid/x.zip'), 'DNS') !== false,
	dashagent_cause_echec_reseau('https://hote-absent.invalid/x.zip'));

echo "\n== Capacités PHP et bibliothèques ==\n";

$inventaire = json_encode(['procures' => [
	['prefixe' => 'PHP', 'nom' => 'php', 'version' => '8.2.6', 'fournie_par' => '', 'php' => true],
	['prefixe' => 'PHP:CURL', 'nom' => 'php:curl', 'version' => '8.2.6', 'fournie_par' => '', 'php' => true],
	['prefixe' => 'MEJS', 'nom' => 'mejs', 'version' => '4.2.7', 'fournie_par' => 'medias', 'php' => false],
	['prefixe' => 'JQUERY', 'nom' => 'jquery', 'version' => '3.6.4', 'fournie_par' => '', 'php' => false],
]]);
$procures = dashboard_procures($inventaire);
verifier('quatre capacités relues', count($procures) === 4, count($procures));
verifier('capacités comptées', dashboard_compter_procures($inventaire) === 4);
verifier('extension PHP rattachée à l’hébergement', ($procures[1]['origine'] ?? '') === 'php', $procures[1]['origine'] ?? '');
verifier('bibliothèque rattachée à son plugin', ($procures[2]['origine'] ?? '') === 'plugin', $procures[2]['origine'] ?? '');
verifier('bibliothèque sans fournisseur rattachée à SPIP', ($procures[3]['origine'] ?? '') === 'spip', $procures[3]['origine'] ?? '');

/* Un agent d'une version antérieure n'envoie pas le drapeau « php ». */
$ancien = json_encode(['procures' => [
	['nom' => 'php:gd', 'version' => '8.2.6', 'fournie_par' => ''],
	['nom' => 'phpmailer', 'version' => '6.9.1', 'fournie_par' => ''],
]]);
$anciens = dashboard_procures($ancien);
verifier('agent ancien : extension PHP reconnue au nom', ($anciens[0]['origine'] ?? '') === 'php', $anciens[0]['origine'] ?? '');
verifier('agent ancien : phpmailer n’est pas une extension', ($anciens[1]['origine'] ?? '') === 'spip', $anciens[1]['origine'] ?? '');

verifier('inventaire sans capacités', dashboard_procures(json_encode(['plugins' => []])) === []);
verifier('inventaire illisible', dashboard_procures('pas du json') === []);

echo "\n== Formatage des tailles ==\n";

verifier('octets bruts', dashboard_octets(512) === '512 o', dashboard_octets(512));
verifier('kilo-octets', dashboard_octets(2048) === '2 Ko', dashboard_octets(2048));
verifier('méga-octets', dashboard_octets(5 * 1024 * 1024) === '5 Mo', dashboard_octets(5 * 1024 * 1024));
verifier('zéro', dashboard_octets(0) === '0 o', dashboard_octets(0));

echo "\n----------------------------------------\n";
echo ($total - $echecs) . " / $total vérifications passées\n";

exit($echecs === 0 ? 0 : 1);
