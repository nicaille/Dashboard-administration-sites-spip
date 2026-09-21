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

echo "\n== Adresse du spip_loader d’un site ==\n";

/* Le script est à la racine web, là où pointe l'URL publique. Reste à joindre
   les deux morceaux sans supposer qu'elle finit par une barre. */
verifier('une adresse sans barre finale',
	dashboard_url_loader('https://exemple.org') === 'https://exemple.org/spip_loader.php',
	dashboard_url_loader('https://exemple.org'));
verifier('une adresse avec barre finale',
	dashboard_url_loader('https://exemple.org/') === 'https://exemple.org/spip_loader.php');
verifier('un site dans un sous-répertoire',
	dashboard_url_loader('https://exemple.org/dev/') === 'https://exemple.org/dev/spip_loader.php');
verifier('la requête et l’ancre sont écartées',
	dashboard_url_loader('https://exemple.org/dev/?page=x#ancre') === 'https://exemple.org/dev/spip_loader.php',
	dashboard_url_loader('https://exemple.org/dev/?page=x#ancre'));
/* Ce lien s'ouvre dans un nouvel onglet : il n'a pas à emmener ailleurs que sur
   le site, ni à porter un pseudo-protocole. */
verifier('une adresse vide ne donne pas de lien', dashboard_url_loader('') === '');
verifier('javascript: est refusé', dashboard_url_loader('javascript:alert(1)') === '');
verifier('un chemin relatif est refusé', dashboard_url_loader('/afcf/dev/') === '');

echo "\n== Adresse du spip_check d’un site ==\n";

/* Même fabrication que pour le spip_loader, et même réserve : c'est un lien de
   navigation vers le site géré, jamais une adresse d'action. */
verifier('l’adresse se déduit de celle du site',
	dashboard_url_check('https://exemple.org') === 'https://exemple.org/spip_check.php',
	dashboard_url_check('https://exemple.org'));
verifier('un site dans un sous-répertoire est suivi',
	dashboard_url_check('https://exemple.org/dev/') === 'https://exemple.org/dev/spip_check.php');
verifier('la requête et l’ancre sont écartées',
	dashboard_url_check('https://exemple.org/dev/?page=x#ancre') === 'https://exemple.org/dev/spip_check.php');
verifier('une adresse vide ne donne pas de lien', dashboard_url_check('') === '');
verifier('javascript: est refusé', dashboard_url_check('javascript:alert(1)') === '');

echo "\n== D’où vient l’adresse de SPIP Check ==\n";

/* Le seul réglage du parc qui distingue « jamais réglé » de « vidé exprès ».

   `dashboard_config()` traite une valeur vide comme absente et rend le défaut —
   le bon comportement partout ailleurs, un délai vide n'étant pas un délai de
   zéro. Mais ici il rendrait le champ impossible à vider, alors que l'encadré
   promet qu'un champ vide ne propose aucun dépôt. */
$GLOBALS['dashboard_config_test'] = [];
verifier('sans réglage, c’est l’adresse par défaut',
	dashboard_url_check_source() === _DASHBOARD_CHECK_URL, dashboard_url_check_source());

$GLOBALS['dashboard_config_test'] = ['url_spip_check' => 'https://miroir.interne/spip_check.php'];
verifier('un miroir déclaré l’emporte',
	dashboard_url_check_source() === 'https://miroir.interne/spip_check.php',
	dashboard_url_check_source());

$GLOBALS['dashboard_config_test'] = ['url_spip_check' => ''];
verifier('un champ vidé éteint la fonction', dashboard_url_check_source() === '',
	dashboard_url_check_source() === '' ? 'éteint' : dashboard_url_check_source());

$GLOBALS['dashboard_config_test'] = ['url_spip_check' => '  https://miroir.interne/x.php  '];
verifier('les espaces autour de l’adresse sont retirés',
	dashboard_url_check_source() === 'https://miroir.interne/x.php', dashboard_url_check_source());

/* Le défaut pointe la forge, en https, sur le livrable — pas sur le dépôt. */
verifier('le défaut est une adresse https', strncmp(_DASHBOARD_CHECK_URL, 'https://', 8) === 0);
verifier('le défaut désigne un fichier PHP, pas un dépôt git',
	strpos(_DASHBOARD_CHECK_URL, '.php') !== false && substr(_DASHBOARD_CHECK_URL, -4) !== '.git',
	_DASHBOARD_CHECK_URL);
$GLOBALS['dashboard_config_test'] = [];


echo "\n== Ce qu’on accepte de déposer comme spip_check ==\n";

/* Le livrable est engendré depuis un gabarit qui pose la version et l'édition
   en dur, tout à la fin d'un fichier d'un mégaoctet — les bibliothèques
   embarquées occupent tout le début. C'est donc la **queue** qu'on relit, à
   l'inverse du spip_loader dont l'en-tête porte les constantes. */
$queue_check = "function spipCheckToolVersion(): string {\n\treturn '2.4.0';\n}\n\n"
	. "function spipCheckEdition(): string {\n\treturn 'complete';\n}\n";
$check = "<?php\n\nnamespace Jfcherng\\Utility {\n\tclass MbString {}\n}\n\n" . $queue_check;

verifier('un spip_check est accepté', dashagent_check_conforme($check)['ok'],
	dashagent_check_conforme($check)['erreur']);
verifier('sa version est relevée', dashagent_check_version($queue_check) === '2.4.0',
	dashagent_check_version($queue_check));
verifier('son édition est relevée', dashagent_check_edition($queue_check) === 'complete',
	dashagent_check_edition($queue_check));
verifier('l’édition lite est reconnue',
	dashagent_check_edition("function spipCheckEdition(): string {\n\treturn 'lite';\n}") === 'lite');

/* Un mégaoctet de code contient quantité de nombres à points : c'est le corps
   de la fonction qu'on lit, jamais une occurrence isolée du numéro. */
verifier('un numéro de version isolé n’est pas pris pour celui de l’outil',
	dashagent_check_version("<?php\n// voir la version 9.9.9 du protocole\n\$x = '1.2.3';\n") === '');
verifier('un fichier sans la fonction n’annonce pas de version',
	dashagent_check_version("<?php\nclass S { public const VERSION = '8.0.5'; }\n") === '');

verifier('un fichier vide est refusé', !dashagent_check_conforme('')['ok']);
verifier('du HTML est refusé', !dashagent_check_conforme('<html><body>404</body></html>')['ok']);
/* Le cas qui compte : une erreur d'adresse ou un miroir détourné servant un
   PHP quelconque. Sans la marque du gabarit, il n'entre pas. */
verifier('du PHP qui n’est pas un spip_check est refusé',
	!dashagent_check_conforme("<?php\nunlink(__FILE__);\n")['ok'],
	dashagent_check_conforme("<?php\nunlink(__FILE__);\n")['erreur']);
/* Un spip_loader est du PHP, il parle de SPIP, et il n'est pas un spip_check :
   les deux contrôles ne se confondent pas. */
verifier('un spip_loader n’est pas accepté comme spip_check',
	!dashagent_check_conforme("<?php\n// spip_loader.php\n\$spip_loader_version = '3.1.2';\necho 'SPIP';\n")['ok']);
verifier('un PHP démesuré est refusé',
	!dashagent_check_conforme("<?php\n" . str_repeat('x', _DASHAGENT_CHECK_TAILLE_MAX) . $queue_check)['ok']);
/* La borne de relecture doit couvrir le livrable réel : en 2.4.0, la fonction
   est au 854 687ᵉ octet sur 953 235, soit à 98 548 octets de la fin. */
verifier('la queue relue couvre largement le livrable réel',
	_DASHAGENT_CHECK_QUEUE > 98548 * 2, _DASHAGENT_CHECK_QUEUE);
verifier('un livrable de la taille du vrai fichier passe',
	dashagent_check_conforme("<?php\n" . str_repeat('x', 950 * 1024) . "\n" . $queue_check)['ok']);

/* Trois opérations, une seule autorisation, refusée par défaut. Déposer cet
   outil rend appelable à la racine web un lecteur de tout le disque. */
$GLOBALS['dashagent_config_test'] = [];
foreach (['check_etat', 'check_maj', 'check_retirer'] as $op) {
	verifier("$op est refusée par défaut", !dashagent_operation_autorisee($op));
}
$GLOBALS['dashagent_config_test'] = ['op_loader' => 'on'];
foreach (['check_etat', 'check_maj', 'check_retirer'] as $op) {
	verifier("op_loader n’ouvre pas $op", !dashagent_operation_autorisee($op));
}
$GLOBALS['dashagent_config_test'] = ['op_check' => 'on'];
foreach (['check_etat', 'check_maj', 'check_retirer'] as $op) {
	verifier("$op s’ouvre avec op_check", dashagent_operation_autorisee($op));
}
/* Et réciproquement : op_check n'ouvre rien d'autre. */
verifier('op_check n’ouvre pas le spip_loader', !dashagent_operation_autorisee('loader_maj'));
verifier('op_check n’ouvre pas la sauvegarde', !dashagent_operation_autorisee('sauvegarde_creer'));
$GLOBALS['dashagent_config_test'] = [];


echo "\n== Ce qu’on accepte de déposer comme spip_loader ==\n";

/* Le fichier atterrit à la racine web, là où n'importe qui peut l'appeler, et
   il sait installer SPIP. Une erreur d'adresse ou un miroir détourné ne doit pas
   pouvoir y déposer un PHP quelconque. */
$loader = "<?php\n// spip_loader.php\n\$spip_loader_version = '3.1.2';\necho 'SPIP';\n";
verifier('un spip_loader ordinaire est accepté', dashagent_loader_conforme($loader)['ok']);
verifier('sa version est relevée', dashagent_loader_version($loader) === '3.1.2',
	dashagent_loader_version($loader));

/* Le script distribué aujourd'hui est un stub de PHAR : en-tête PHP lisible,
   archive binaire à la suite. Ce sont ses constantes de classe qui le datent. */
$stub = "<?php\n\nnamespace Spip\\Loader;\n\nuse Phar;\n\nfinal class Stub\n{\n"
	. "    public const VERSION = '8.0.5';\n\n"
	. "    public const FULL_VERSION = '8.0.5';\n\n"
	. "    public const DATE = '2026-09-04 06:44:10';\n\n"
	. "    public const NAME = 'spip_loader.phar';\n}\n";
verifier('un stub de PHAR est accepté', dashagent_loader_conforme($stub)['ok'],
	dashagent_loader_conforme($stub)['erreur']);
verifier('la version du stub est relevée', dashagent_loader_version($stub) === '8.0.5',
	dashagent_loader_version($stub));
verifier('FULL_VERSION n’est pas prise pour VERSION',
	dashagent_loader_version("<?php class S { public const FULL_VERSION = '9.9.9'; public const VERSION = '8.0.5'; }") === '8.0.5');
verifier('à défaut de VERSION, FULL_VERSION fait l’affaire',
	dashagent_loader_version("<?php class S { public const FULL_VERSION = '9.9.9'; }") === '9.9.9');
verifier('la date de publication est relevée',
	dashagent_loader_date($stub) === '2026-09-04 06:44:10', dashagent_loader_date($stub));
verifier('un script sans date n’en invente pas', dashagent_loader_date($loader) === '');
verifier('un fichier vide est refusé', !dashagent_loader_conforme('')['ok']);
verifier('du HTML est refusé', !dashagent_loader_conforme('<html><body>404</body></html>')['ok']);
verifier('du PHP qui ne parle pas de SPIP est refusé',
	!dashagent_loader_conforme("<?php\nunlink(__FILE__);\n")['ok'],
	dashagent_loader_conforme("<?php\nunlink(__FILE__);\n")['erreur']);
/* La borne est calculée depuis la constante : le stub de PHAR pèse déjà deux
   cents kilo-octets et grossira, la valeur bougera avec lui. */
verifier('un PHP démesuré est refusé',
	!dashagent_loader_conforme("<?php // spip_loader SPIP\n"
		. str_repeat('x', _DASHAGENT_LOADER_TAILLE_MAX))['ok']);
verifier('un stub de la taille du vrai script passe',
	dashagent_loader_conforme("<?php // spip_loader SPIP\n" . str_repeat('x', 210 * 1024))['ok']);
/* Le script officiel n'annonce pas toujours sa version ; cela ne le disqualifie
   pas, l'absence se dit simplement à l'écran. */
verifier('un spip_loader sans version reste acceptable',
	dashagent_loader_conforme("<?php\n// spip_loader pour SPIP\n")['ok']);
verifier('une version absente rend une chaîne vide',
	dashagent_loader_version("<?php\n// spip_loader pour SPIP\n") === '');

echo "\n== Adresse de l'appelant ==\n";

/* Cette valeur est enregistrée au journal d'une requête *refusée*, donc non
   authentifiée, et le journal s'affiche dans l'espace privé du site géré.
   Derrière un proxy déclaré de confiance, elle vient d'un en-tête que le client
   écrit lui-même : sans contrôle, n'importe qui y déposerait son balisage. */
$serveur = ['REMOTE_ADDR' => '203.0.113.7'];
verifier('sans proxy, l’adresse du socket', dashagent_ip_client($serveur) === '203.0.113.7');
verifier('un REMOTE_ADDR qui n’est pas une IP est écarté',
	dashagent_ip_client(['REMOTE_ADDR' => '<svg onload=alert(1)>']) === '');
verifier('sans proxy de confiance, l’en-tête est ignoré',
	dashagent_ip_client(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.4'])
		=== '203.0.113.7');

define('_DASHAGENT_PROXY_DE_CONFIANCE', true);
verifier('proxy de confiance : l’en-tête fait foi',
	dashagent_ip_client(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.4, 10.0.0.1'])
		=== '198.51.100.4');
verifier('un en-tête qui n’est pas une IP est refusé, pas recopié',
	dashagent_ip_client(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '<svg onload=alert(1)>'])
		=== '10.0.0.1',
	dashagent_ip_client(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '<svg onload=alert(1)>']));
verifier('X-Real-IP sert de second recours',
	dashagent_ip_client(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_REAL_IP' => '2001:db8::5']) === '2001:db8::5');

echo "\n== Ce qu’un site géré nous répond n’est jamais du balisage ==\n";

/* Le tableau de bord affiche l'inventaire d'un site dans son espace privé, à un
   webmestre qui détient les secrets de tout le parc. L'échappement de SPIP ne
   couvre pas ce cas : `interdire_scripts()` laisse passer `<svg onload>`. Un
   site compromis qui répondrait cela comme numéro de version exécuterait donc
   son code sur la tour de contrôle — soit la prise du parc entier depuis un
   seul de ses sites. */
verifier('un gestionnaire d’événement ne survit pas',
	dashboard_inerte('<svg onload="alert(1)">4.4.23') === '4.4.23',
	dashboard_inerte('<svg onload="alert(1)">4.4.23'));
verifier('aucun chevron ne subsiste',
	strpbrk((string) dashboard_inerte('<b>a</b> > b < c'), '<>') === false,
	dashboard_inerte('<b>a</b> > b < c'));
verifier('une balise ouverte non refermée emporte sa suite',
	strpbrk((string) dashboard_inerte('4.4 <script>alert(1)'), '<>') === false);
verifier('un numéro de version normal traverse intact',
	dashboard_inerte('4.4.23') === '4.4.23');
verifier('un nom de plugin normal traverse intact',
	dashboard_inerte('Saisies & compagnie') === 'Saisies & compagnie');
verifier('les caractères de contrôle partent',
	dashboard_inerte("4.4\x00.23\x07") === '4.4.23');
verifier('les entiers restent des entiers', dashboard_inerte(42) === 42);
verifier('les booléens restent des booléens', dashboard_inerte(false) === false);
verifier('null reste null', dashboard_inerte(null) === null);

/* L'inventaire est un arbre : le filtre doit le parcourir en entier, clefs
   comprises — une clef est affichée comme le reste. */
$inventaire = dashboard_inerte([
	'spip' => ['version' => '<svg onload=x>4.4.23'],
	'plugins' => [['nom' => 'Ok', 'version' => '<img src=x onerror=y>1.0']],
	'<b>clef</b>' => 'valeur',
]);
verifier('le filtre descend dans les sous-tableaux',
	$inventaire['spip']['version'] === '4.4.23', $inventaire['spip']['version']);
verifier('le filtre descend dans les listes',
	$inventaire['plugins'][0]['version'] === '1.0', $inventaire['plugins'][0]['version']);
verifier('les clefs sont traitées comme les valeurs',
	array_key_exists('clef', $inventaire), implode(',', array_keys($inventaire)));
verifier('ce qui était propre n’est pas abîmé', $inventaire['plugins'][0]['nom'] === 'Ok');

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

echo "\n== Ce qu’on annonce à mettre à jour ==\n";

/* Le décompte est celui des mises à jour qu'on peut faire. Un plugin livré avec
   SPIP suit le core : pas de bouton sur sa ligne, et « Tout mettre à jour » ne
   le prend pas. Le compter laissait la pastille allumée après une mise à jour
   réussie, et le bilan annoncer « 1 plugin encore à mettre à jour » sans que
   rien, sur la page, ne permette d'y remédier. */
$inventaire = [
	['prefixe' => 'SAISIES', 'maj_disponible' => true,  'distribue' => false],
	['prefixe' => 'MOTS',    'maj_disponible' => true,  'distribue' => true],
	['prefixe' => 'CFG',     'maj_disponible' => false, 'distribue' => false],
];
verifier('seules les mises à jour réalisables sont comptées',
	dashboard_compter_maj($inventaire) === 1, dashboard_compter_maj($inventaire));
verifier('un plugin livré avec SPIP ne compte pas',
	dashboard_compter_maj([['prefixe' => 'MOTS', 'maj_disponible' => true, 'distribue' => true]]) === 0);
verifier('un inventaire vide ne compte rien', dashboard_compter_maj([]) === 0);
verifier('une entrée illisible est ignorée', dashboard_compter_maj(['n’importe quoi']) === 0);

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
require_once chemin_plugin('tourdecontrole') . '/inc/dashboard_versions.php';

/* L'index tel que le publie files.spip.net : le nom est en minuscules. Le
   déduire d'une convention supposée valait un « HTTP 404 » après la sauvegarde,
   au pire moment. */
$index = dashboard_archives_analyser(
	'<a href="spip-v4.2.16.zip">spip-v4.2.16.zip</a>' . "\n"
	. '<a href="spip-v4.4.23.zip">spip-v4.4.23.zip</a>' . "\n"
	. '<a href="spip-v4.4.16.zip">spip-v4.4.16.zip</a>' . "\n"
	. '<a href="spip-v4.4.23.zip.md5">empreinte</a>'
);
verifier('deux branches relevées dans l’index', count($index['versions']) === 2, json_encode($index['versions']));
verifier('la plus haute version de la branche est retenue',
	($index['versions']['4.4'] ?? '') === '4.4.23', json_encode($index['versions']));
verifier('le nom de fichier est relevé tel quel',
	($index['fichiers']['4.4.23'] ?? '') === 'spip-v4.4.23.zip', json_encode($index['fichiers']));

/* Certains miroirs emploient la capitale : c'est leur nom qui fait foi, pas
   le nôtre. */
$capitale = dashboard_archives_analyser('SPIP-v4.4.99.zip');
verifier('la casse du miroir est conservée',
	($capitale['fichiers']['4.4.99'] ?? '') === 'SPIP-v4.4.99.zip', json_encode($capitale['fichiers']));

verifier('un index vide ne donne rien',
	dashboard_archives_analyser('<html><body>rien ici</body></html>')['fichiers'] === []);

/* L'index effectivement consulté est simulé par le stub de recuperer_url. */
$GLOBALS['dashboard_index_archives_test'] = '<a href="spip-v4.2.16.zip">spip-v4.2.16.zip</a>';
verifier(
	'URL prise sur le nom publié par l’index',
	dashboard_url_archive_spip('4.2.16') === 'https://files.spip.net/spip/archives/spip-v4.2.16.zip',
	dashboard_url_archive_spip('4.2.16')
);

/* Dépôt sans index — un miroir privé, un répertoire sans listing : on demande
   au serveur lequel des noms d'usage existe, plutôt que de parier. */
$GLOBALS['dashboard_index_archives_test'] = null;
$GLOBALS['dashboard_archives_servies_test'] = ['https://files.spip.net/spip/archives/spip-v4.4.23.zip'];
verifier(
	'sans index, le nom d’usage est vérifié auprès du serveur',
	dashboard_url_archive_spip('4.4.23') === 'https://files.spip.net/spip/archives/spip-v4.4.23.zip',
	dashboard_url_archive_spip('4.4.23')
);

/* Un miroir qui n'a que la capitale : c'est lui qui a raison. */
$GLOBALS['dashboard_archives_servies_test'] = ['https://files.spip.net/spip/archives/SPIP-v4.4.99.zip'];
verifier(
	'un miroir en capitales est trouvé malgré tout',
	dashboard_url_archive_spip('4.4.99') === 'https://files.spip.net/spip/archives/SPIP-v4.4.99.zip',
	dashboard_url_archive_spip('4.4.99')
);

/* Rien ne répond : une adresse est tout de même rendue, pour que le message
   d'erreur en nomme une. */
$GLOBALS['dashboard_archives_servies_test'] = [];
verifier(
	'aucun nom ne répond : l’adresse la plus probable est rendue',
	dashboard_url_archive_spip('4.4.23') === 'https://files.spip.net/spip/archives/spip-v4.4.23.zip',
	dashboard_url_archive_spip('4.4.23')
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

echo "\n== Autorisations des nouvelles opérations ==\n";

/* Lire le pare-feu, c'est lire qui a été bloqué : une donnée de sécurité qui
   nomme des visiteurs. Remplacer le spip_loader, c'est déposer à la racine web
   un script qui installe ce qu'on lui dit. Ni l'une ni l'autre ne se déduit
   d'une autorisation déjà accordée. */
$GLOBALS['dashagent_config_test'] = [];
verifier('waf_resume refusé par défaut', !dashagent_operation_autorisee('waf_resume'));
verifier('loader_etat refusé par défaut', !dashagent_operation_autorisee('loader_etat'));
verifier('loader_maj refusé par défaut', !dashagent_operation_autorisee('loader_maj'));

$GLOBALS['dashagent_config_test'] = ['op_serveur' => 'on', 'op_core_maj' => 'on', 'op_plugin_maj' => 'on'];
verifier('consulter le serveur n’ouvre pas le pare-feu', !dashagent_operation_autorisee('waf_resume'));
verifier('mettre à jour le core n’ouvre pas le spip_loader', !dashagent_operation_autorisee('loader_maj'));

$GLOBALS['dashagent_config_test'] = ['op_waf' => 'on'];
verifier('waf_resume s’accorde à part', dashagent_operation_autorisee('waf_resume'));
verifier('et n’ouvre rien d’autre', !dashagent_operation_autorisee('loader_maj')
	&& !dashagent_operation_autorisee('serveur_table'));

$GLOBALS['dashagent_config_test'] = ['op_loader' => 'on'];
verifier('les deux opérations du loader vont ensemble',
	dashagent_operation_autorisee('loader_etat') && dashagent_operation_autorisee('loader_maj'));
$GLOBALS['dashagent_config_test'] = [];

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

/* L'invariant n'est pas « la sauvegarde d'abord », mais « rien qui touche au
   site avant elle ». Relire le catalogue des dépôts ne modifie que l'inventaire
   de SVP, qu'il régénère à volonté : cette étape-là peut la précéder, et doit
   même le faire — mieux vaut savoir ce qu'il y a à faire avant de sauvegarder. */
$etapes_lecture = ['depots', 'sync'];
$etapes_ecriture = ['plugin', 'plugins', 'core', 'base'];

foreach (['plugin_maj', 'plugin_maj_tous', 'core_maj', 'base_maj', 'depots_maj'] as $operation) {
	$etapes = dashboard_chantier_etapes($operation);
	$rang_sauvegarde = array_search('sauvegarde', $etapes, true);

	$avant = $rang_sauvegarde === false ? $etapes : array_slice($etapes, 0, $rang_sauvegarde);
	$intrus = array_intersect($avant, $etapes_ecriture);
	verifier("$operation : rien ne touche au site avant la sauvegarde",
		$intrus === [], implode(' → ', $etapes));

	$ecrit = array_intersect($etapes, $etapes_ecriture);
	verifier("$operation : une sauvegarde dès qu'une étape modifie le site",
		!$ecrit || $rang_sauvegarde !== false, implode(' → ', $etapes));

	verifier("$operation : finit par une synchronisation", end($etapes) === 'sync');
}
verifier('la mise à jour du core migre aussi le schéma',
	in_array('base', dashboard_chantier_etapes('core_maj'), true),
	implode(' → ', dashboard_chantier_etapes('core_maj')));
verifier('les plugins sont relistés juste avant d’être mis à jour',
	dashboard_chantier_etapes('plugin_maj_tous') === ['depots', 'sauvegarde', 'sync', 'plugins', 'sync'],
	implode(' → ', dashboard_chantier_etapes('plugin_maj_tous')));

/* Le catalogue est relu avant toute décision de mise à jour : c'est lui qui dit
   quelles versions existent, et il ne se rafraîchit pas tout seul. */
foreach (['plugin_maj', 'plugin_maj_tous'] as $operation) {
	verifier("$operation : les dépôts sont relus en premier",
		(dashboard_chantier_etapes($operation)[0] ?? '') === 'depots',
		implode(' → ', dashboard_chantier_etapes($operation)));
}
verifier('relire les dépôts est une opération à soi seule',
	dashboard_chantier_operation_connue('depots_maj') === true);
verifier('relire les dépôts ne sauvegarde pas pour rien',
	!in_array('sauvegarde', dashboard_chantier_etapes('depots_maj'), true),
	implode(' → ', dashboard_chantier_etapes('depots_maj')));
verifier('opération inconnue : aucune étape', dashboard_chantier_etapes('rm_rf') === []);
verifier('opération inconnue : refusée', dashboard_chantier_operation_connue('rm_rf') === false);
verifier('opération connue : acceptée', dashboard_chantier_operation_connue('core_maj') === true);

echo "\n== Un silence n’est pas un verdict ==\n";

/* Mettre à jour un plugin, c'est déplacer du code pendant qu'il s'exécute. Quand
   ce plugin est l'agent lui-même, celui qui déplace est celui qui doit répondre :
   la réponse peut ne jamais revenir alors que la mise à jour a réussi. Compter ce
   silence pour un échec annoncerait perdue une opération aboutie. */
verifier('une connexion qui n’aboutit pas est un silence',
	dashboard_silence('transport'));
verifier('une réponse qui n’est pas du JSON est un silence',
	dashboard_silence('reponse_illisible'),
	'un site en cours de mue sert sa page d’erreur, pas du JSON');

/* La distinction est tout le sujet : un agent qui répond « verrou posé » a
   délibéré, et sa réponse se respecte. */
foreach (['svp_verrou', 'svp_absent', 'paquet_inconnu', 'dependance', 'autorisation', 'inconnue'] as $code) {
	verifier("« $code » est une réponse, pas un silence", !dashboard_silence($code));
}
verifier('un code vide n’est pas un silence', !dashboard_silence(''));

/* Le repli sur l'archive, lui, ne se déclenche que sur les deux refus qui disent
   « je ne sais pas traiter ce plugin » — à ne pas confondre avec un silence. */
verifier('un silence n’autorise pas le repli sur l’archive',
	!dashboard_chantier_svp_repli('transport'),
	'sans quoi on déploierait une archive par-dessus une mise à jour peut-être faite');
verifier('SVP absent autorise le repli', dashboard_chantier_svp_repli('svp_absent'));

/* L'état de l'étape voyage dans « reste », en clair : il est relu à chaque
   avancement, et par le cron aussi bien que par le navigateur. */
$reste = 'verif:3:1.0.14';
[$essais, $version_avant] = array_pad(explode(':', substr($reste, 6), 2), 2, '');
verifier('l’état de vérification porte son compte d’essais', (int) $essais === 3);
verifier('l’état de vérification porte la version de départ', $version_avant === '1.0.14');

/* Une version de départ inconnue ne doit pas décaler la lecture : le repli
   archive pose « verif:1: » sans version, et le découpage doit tenir. */
[$essais_vide, $version_vide] = array_pad(explode(':', substr('verif:1:', 6), 2), 2, '');
verifier('une version de départ vide se relit sans décalage',
	(int) $essais_vide === 1 && $version_vide === '');

verifier('le plafond de silences laisse le temps à SPIP de se reprendre',
	defined('_DASHBOARD_PLUGIN_SILENCES') && _DASHBOARD_PLUGIN_SILENCES >= 3);

echo "\n== Retrouver une sauvegarde après une réponse perdue ==\n";

/* Un cache en frontal rend son 503 bien avant que PHP ait fini l'export : la
   sauvegarde existe le plus souvent malgré tout. Encore faut-il reconnaître la
   bonne dans l'inventaire du site, et surtout n'en adopter aucune quand il n'y
   en a pas — annoncer une protection qu'on n'a pas serait pire que l'échec. */
$maintenant = time();
$inventaire = [
	['identifiant' => 'ancienne', 'octets' => 4096, 'date' => date('c', $maintenant - 86400 * 3)],
	['identifiant' => 'connue',   'octets' => 4096, 'date' => date('c', $maintenant - 60)],
	['identifiant' => 'fraiche',  'octets' => 8192, 'date' => date('c', $maintenant - 10)],
];

$trouvee = dashboard_sauvegarde_retrouvee($inventaire, $maintenant - 120, ['connue']);
verifier('la sauvegarde que la demande vient de produire est retrouvée',
	($trouvee['identifiant'] ?? '') === 'fraiche', (string) ($trouvee['identifiant'] ?? 'aucune'));

verifier('une sauvegarde déjà inscrite chez nous n’est jamais adoptée',
	($trouvee['identifiant'] ?? '') !== 'connue');

/* Le garde-fou qui compte : sur un site où rien n'a été produit, il ne faut
   rien adopter du tout — même s'il traîne de vieilles sauvegardes. */
$rien = dashboard_sauvegarde_retrouvee(
	[['identifiant' => 'ancienne', 'octets' => 4096, 'date' => date('c', $maintenant - 86400 * 3)]],
	$maintenant - 120,
	[]
);
verifier('une orpheline ancienne n’est pas adoptée', $rien === null, (string) ($rien['identifiant'] ?? '—'));

verifier('un inventaire vide n’adopte rien',
	dashboard_sauvegarde_retrouvee([], $maintenant - 120, []) === null);

/* Une sauvegarde vide serait une protection en trompe-l’œil. */
verifier('une sauvegarde de zéro octet n’est pas adoptée',
	dashboard_sauvegarde_retrouvee(
		[['identifiant' => 'vide', 'octets' => 0, 'date' => date('c', $maintenant)]],
		$maintenant - 120,
		[]
	) === null);

/* La date vient de l’horloge du site géré, pas de la nôtre : un décalage de
   quelques minutes entre deux hébergements n’a rien d’exceptionnel, et ne doit
   pas faire manquer une sauvegarde bien réelle. */
$decalee = dashboard_sauvegarde_retrouvee(
	[['identifiant' => 'decalee', 'octets' => 4096, 'date' => date('c', $maintenant - 900)]],
	$maintenant - 120,
	[]
);
verifier('un décalage d’horloge de quinze minutes ne fait pas manquer la sauvegarde',
	($decalee['identifiant'] ?? '') === 'decalee');

/* Mais la tolérance a une fin, sans quoi elle ne protégerait plus de rien. */
verifier('au-delà de la tolérance, plus rien n’est adopté',
	dashboard_sauvegarde_retrouvee(
		[['identifiant' => 'trop_vieille', 'octets' => 4096, 'date' => date('c', $maintenant - 7200)]],
		$maintenant - 120,
		[]
	) === null);

/* La plus récente l’emporte quand plusieurs sont inconnues. */
$plusieurs = dashboard_sauvegarde_retrouvee([
	['identifiant' => 'avant', 'octets' => 4096, 'date' => date('c', $maintenant - 70)],
	['identifiant' => 'apres', 'octets' => 4096, 'date' => date('c', $maintenant - 5)],
], $maintenant - 120, []);
verifier('entre deux inconnues, la plus récente l’emporte',
	($plusieurs['identifiant'] ?? '') === 'apres');

/* Une entrée malformée venue d’un site compromis ne doit pas faire tomber la
   fonction ni se faire adopter. */
verifier('une entrée malformée est ignorée',
	dashboard_sauvegarde_retrouvee(
		['pas un tableau', ['octets' => 4096, 'date' => date('c', $maintenant)], ['identifiant' => 'sans_date', 'octets' => 4096]],
		$maintenant - 120,
		[]
	) === null);

echo "\n== Une sauvegarde rapatriée est-elle intacte ? ==\n";

/* Le fichier existe, il pèse son poids, et rien de tout cela ne dit qu'il est
   lisible. Un export interrompu sur le site géré produit une archive gzip
   amputée qui voyage parfaitement : empreinte et taille du transfert sont
   justes, le contenu est perdu. Cela ne se découvre que le jour où l'on veut
   restaurer — c'est-à-dire au pire moment. */
$bac = _DIR_TMP . 'sauvegardes-verif/';
if (!is_dir($bac)) {
	mkdir($bac, 0777, true);
}

$dump = "-- Sauvegarde SPIP\nINSERT INTO spip_articles VALUES (1);\nSET FOREIGN_KEY_CHECKS=1;\n";

file_put_contents($bac . 'saine.sql.gz', gzencode($dump));
$saine = dashboard_sauvegarde_verifier($bac . 'saine.sql.gz');
verifier('une archive saine est reconnue', $saine['ok'] === true, $saine['raison']);
verifier('une archive d’avant la marque de fin n’est pas dite complète', $saine['complet'] === false,
	'son absence ne prouve rien : les sauvegardes d’avant l’agent 1.0.16 n’en portent pas');

/* Le cas qui motive tout : le processus tué en cours d'écriture. */
$entiere = file_get_contents($bac . 'saine.sql.gz');
file_put_contents($bac . 'tronquee.sql.gz', substr($entiere, 0, strlen($entiere) - 6));
$tronquee = dashboard_sauvegarde_verifier($bac . 'tronquee.sql.gz');
verifier('une archive tronquée est refusée', $tronquee['ok'] === false, $tronquee['raison']);

/* Un octet retourné au milieu : le CRC32 du pied de page doit mordre. */
$abimee = $entiere;
$abimee[40] = chr(ord($abimee[40]) ^ 0x01);
file_put_contents($bac . 'abimee.sql.gz', $abimee);
verifier('une archive corrompue est refusée',
	dashboard_sauvegarde_verifier($bac . 'abimee.sql.gz')['ok'] === false);

/* La marque de fin distingue l'archive valide de l'archive complète : un export
   tué proprement entre deux tables produit un gzip parfaitement lisible, et
   incomplet. */
file_put_contents($bac . 'complete.sql.gz', gzencode($dump . "-- fin de sauvegarde 2026-09-18T10:00:00+02:00\n"));
$complete = dashboard_sauvegarde_verifier($bac . 'complete.sql.gz');
verifier('la marque de fin atteste un export mené à son terme',
	$complete['ok'] === true && $complete['complet'] === true);

verifier('un fichier absent est refusé',
	dashboard_sauvegarde_verifier($bac . 'jamais-vue.sql.gz')['ok'] === false);
file_put_contents($bac . 'vide.sql.gz', '');
verifier('un fichier vide est refusé', dashboard_sauvegarde_verifier($bac . 'vide.sql.gz')['ok'] === false);
file_put_contents($bac . 'courte.sql.gz', 'abc');
verifier('un fichier trop court pour porter un pied gzip est refusé',
	dashboard_sauvegarde_verifier($bac . 'courte.sql.gz')['ok'] === false);

/* Une archive volumineuse, pour éprouver la lecture par blocs : le vérificateur
   ne doit pas garder tout le dump en mémoire, ni perdre la marque de fin au
   passage d'un bloc à l'autre. */
file_put_contents($bac . 'grosse.sql.gz',
	gzencode(str_repeat("INSERT INTO spip_articles VALUES (1);\n", 40000) . "-- fin de sauvegarde 2026-09-18T10:00:00+02:00\n"));
$grosse = dashboard_sauvegarde_verifier($bac . 'grosse.sql.gz');
verifier('une archive de plusieurs blocs est vérifiée sans perdre sa marque de fin',
	$grosse['ok'] === true && $grosse['complet'] === true,
	$grosse['octets'] . ' octets décompressés');

foreach (glob($bac . '*') as $f) {
	@unlink($f);
}
@rmdir($bac);

echo "\n== Dire ce qu’on a vu, quand on n’a rien trouvé ==\n";

/* Le rattrapage muet était pire que pas de rattrapage : il renvoyait le message
   d'origine, mot pour mot celui d'avant le correctif. En le lisant, impossible
   de savoir s'il avait cherché sans trouver ou s'il n'était pas installé — deux
   situations qui n'appellent pas les mêmes gestes. */
$injoignable = dashboard_sauvegarde_diagnostic(
	['sauvegarde' => null, 'joignable' => false, 'sauvegardes' => 0, 'inacheves' => 0, 'octets_inacheve' => 0]
);
verifier('un site muet jusque sur son inventaire est signalé comme tel',
	strpos($injoignable, 'n’a pas répondu non plus') !== false, $injoignable);

/* L'indice qui désigne le bon coupable : un export commencé et non terminé
   innocente le cache en frontal, et accuse une limite du site géré. */
$tue = dashboard_sauvegarde_diagnostic(
	['sauvegarde' => null, 'joignable' => true, 'sauvegardes' => 2, 'inacheves' => 1, 'octets_inacheve' => 1048576]
);
verifier('un export inachevé est nommé comme tel', strpos($tue, 'inachevé') !== false, $tue);
verifier('et il oriente vers les limites du site, pas vers le cache',
	strpos($tue, 'max_execution_time') !== false && strpos($tue, 'memory_limit') !== false, $tue);
verifier('la taille de l’export inachevé est dite', strpos($tue, '1 Mio') !== false || strpos($tue, '1 M') !== false, $tue);

/* Rien du tout : l'export n'a pas même démarré. */
$rien = dashboard_sauvegarde_diagnostic(
	['sauvegarde' => null, 'joignable' => true, 'sauvegardes' => 3, 'inacheves' => 0, 'octets_inacheve' => 0]
);
verifier('un site qui répond sans rien de neuf est distingué',
	strpos($rien, 'aucune sauvegarde nouvelle') !== false, $rien);
verifier('le total connu du site est rappelé', strpos($rien, '3 au total') !== false, $rien);

/* Les trois constats doivent être distincts : c'est toute leur raison d'être. */
verifier('les trois diagnostics diffèrent',
	count(array_unique([$injoignable, $tue, $rien])) === 3);

echo "\n== Mesurer un cache sans y laisser la synchronisation ==\n";

/* Le poids des caches est une information de confort ; la synchronisation, elle,
   ne l'est pas. Sur un site à des dizaines de milliers de fichiers de cache, le
   parcours dépassait à lui seul les trente secondes que la tour de contrôle
   accorde, et l'inventaire entier était perdu pour un chiffre accessoire. */
$bac_cache = _DIR_TMP . 'mesure-cache/';
if (!is_dir($bac_cache)) {
	mkdir($bac_cache, 0777, true);
}
for ($i = 0; $i < 1200; $i++) {
	file_put_contents($bac_cache . 'f' . $i . '.txt', 'x');
}

$complet = dashagent_mesurer_repertoire($bac_cache);
verifier('sans échéance, tout est mesuré', $complet['fichiers'] === 1200 && !$complet['partiel'],
	$complet['fichiers'] . ' fichier(s)');

/* Une échéance déjà passée : la mesure doit rendre la main tout de suite, et le
   dire — un chiffre partiel annoncé comme complet serait un mensonge. */
$presse = dashagent_mesurer_repertoire($bac_cache, 20000, microtime(true) - 1);
verifier('une échéance dépassée interrompt la mesure', $presse['partiel'] === true);
verifier('et la mesure interrompue reste en deçà du total',
	$presse['fichiers'] < 1200, $presse['fichiers'] . ' fichier(s) sur 1200');
verifier('le répertoire est tout de même signalé comme existant', $presse['existe'] === true);

// Le plafond en nombre de fichiers n'a pas disparu au passage.
$plafonne = dashagent_mesurer_repertoire($bac_cache, 100);
verifier('le plafond en nombre de fichiers tient toujours',
	$plafonne['fichiers'] === 100 && $plafonne['partiel'] === true, $plafonne['fichiers'] . ' fichier(s)');

// Une échéance large ne doit rien tronquer.
$large = dashagent_mesurer_repertoire($bac_cache, 20000, microtime(true) + 30);
verifier('une échéance large laisse la mesure aller au bout',
	$large['fichiers'] === 1200 && !$large['partiel'], $large['fichiers'] . ' fichier(s)');

foreach (glob($bac_cache . '*') as $f) {
	@unlink($f);
}
@rmdir($bac_cache);

echo "\n== Une série quotidienne se lit sans trou ==\n";

/* Les jours sans événement n'existent pas en base. Les inventer est
   indispensable à la lecture : une courbe qui saute du 3 au 9 laisse croire à
   une continuité entre les deux, là où il ne s'est rien passé pendant cinq
   jours — l'inverse exact de ce qu'un graphique de tendance doit montrer. */
$aujourdhui = date('Y-m-d');
$hier       = date('Y-m-d', strtotime('-1 day'));
$avant      = date('Y-m-d', strtotime('-6 day'));

$complete = dashboard_waf_completer([
	$avant      => ['requetes' => 12, 'ips' => 3],
	$aujourdhui => ['requetes' => 40, 'ips' => 7],
], 7);

verifier('la fenêtre rend exactement le nombre de jours demandé', count($complete) === 7, count($complete));
verifier('elle commence au plus ancien jour de la fenêtre', $complete[0]['jour'] === $avant, $complete[0]['jour']);
verifier('et finit aujourd’hui', $complete[6]['jour'] === $aujourdhui, $complete[6]['jour']);
verifier('les jours connus gardent leurs chiffres',
	$complete[0]['requetes'] === 12 && $complete[6]['requetes'] === 40);
verifier('les jours sans événement valent zéro, ils ne manquent pas',
	$complete[3]['requetes'] === 0 && $complete[3]['ips'] === 0 && isset($complete[3]['jour']));

/* Les jours se suivent sans saut : c'est toute la propriété qu'on cherche. */
$suite = true;
for ($i = 1; $i < count($complete); $i++) {
	$attendu = date('Y-m-d', strtotime($complete[$i - 1]['jour'] . ' +1 day'));
	$suite = $suite && $complete[$i]['jour'] === $attendu;
}
verifier('les jours se suivent un à un, sans trou ni doublon', $suite);

/* Le défaut qui a fait tracer deux courbes vides sur la vue d'ensemble.

   `#VAL|dashboard_waf_serie_parc` ne transmet pas « rien » : SPIP compile cet
   appel en `dashboard_waf_serie_parc('')`. La valeur par défaut déclarée dans
   la signature ne joue alors pas — l'argument est passé —, la chaîne vide vaut
   zéro, et le plancher la ramenait à un. La tendance du parc ne portait qu'un
   seul point, celui du jour, et Chart.js n'a rien à tracer avec un point.

   Rien ne le signalait : l'encadré était là, son JSON aussi, et seules les
   courbes manquaient. Ce qui se vérifie ici, c'est donc ce qu'une fenêtre
   *vide* doit valoir. */
verifier('une fenêtre vide vaut la fenêtre par défaut',
	dashboard_waf_fenetre('') === _DASHBOARD_WAF_FENETRE, dashboard_waf_fenetre(''));
verifier('une fenêtre nulle aussi',
	dashboard_waf_fenetre(0) === _DASHBOARD_WAF_FENETRE, dashboard_waf_fenetre(0));
verifier('une fenêtre négative aussi',
	dashboard_waf_fenetre(-5) === _DASHBOARD_WAF_FENETRE, dashboard_waf_fenetre(-5));
verifier('une fenêtre demandée est respectée', dashboard_waf_fenetre(7) === 7);
verifier('une fenêtre démesurée est bornée', dashboard_waf_fenetre(9999) === 365);

/* Et la conséquence, là où elle se voyait : une série complétée sans fenêtre
   porte la fenêtre par défaut, pas un point. */
$sans_fenetre = dashboard_waf_completer([], '');
verifier('une série sans fenêtre porte la fenêtre par défaut',
	count($sans_fenetre) === _DASHBOARD_WAF_FENETRE, count($sans_fenetre));

/* Un jour hors fenêtre ne doit pas s'y inviter, et un inventaire vide doit tout
   de même rendre une série complète — sans quoi le graphique n'aurait aucun axe
   sur un site qui n'a jamais rien bloqué. */
$hors = dashboard_waf_completer([date('Y-m-d', strtotime('-40 day')) => ['requetes' => 99, 'ips' => 9]], 7);
verifier('un jour antérieur à la fenêtre est ignoré',
	array_sum(array_column($hors, 'requetes')) === 0);

$vide = dashboard_waf_completer([], 30);
verifier('un inventaire vide rend quand même trente jours à zéro',
	count($vide) === 30 && array_sum(array_column($vide, 'requetes')) === 0);

/* Le JSON déposé dans la page : les quatre drapeaux d'échappement sont ce qui
   empêche une valeur de refermer le bloc <script> qui la porte. */
$json = dashboard_waf_json([['jour' => '2026-09-18', 'requetes' => 5, 'ips' => 2]]);
$relu = json_decode($json, true);
verifier('le JSON se relit', is_array($relu['points'] ?? null) && count($relu['points']) === 1);
verifier('il ne contient aucun chevron littéral', strpos($json, '<') === false && strpos($json, '>') === false);

$piege = dashboard_waf_json([['jour' => '2026-09-18', 'requetes' => 1, 'ips' => '</script><svg onload=alert(1)>']]);
verifier('une valeur hostile ne referme pas le bloc script',
	strpos($piege, '</script>') === false && strpos($piege, '<svg') === false,
	substr($piege, 0, 90));

echo "\n== Fraîcheur d’un compte rendu ==\n";

/* L'encadré d'avancement disparaît avec le statut « en cours », et il ne restait
   alors que la notification de départ, figée sur son étape : une opération
   achevée se lisait comme un blocage. Le bilan prend le relais, puis s'efface. */
verifier('une date de l’instant est récente', dashboard_recent(date('Y-m-d H:i:s')));
verifier('une date d’il y a dix minutes est récente',
	dashboard_recent(date('Y-m-d H:i:s', time() - 600)));
verifier('une date d’il y a deux heures ne l’est plus',
	!dashboard_recent(date('Y-m-d H:i:s', time() - 7200)));
verifier('le délai est réglable',
	dashboard_recent(date('Y-m-d H:i:s', time() - 600), 1200)
		&& !dashboard_recent(date('Y-m-d H:i:s', time() - 600), 60));
verifier('une date vide n’est jamais récente', !dashboard_recent(''));
verifier('une date nulle de SQL n’est jamais récente', !dashboard_recent('0000-00-00 00:00:00'));

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

echo "\n== Le dossier d'un plugin porte sa version ==\n";

verifier(
	'convention du site respectée : v6.3.4 devient v6.3.6',
	dashagent_dossier_versionne('plugins/auto/saisies/v6.3.4', '6.3.4', '6.3.6') === 'plugins/auto/saisies/v6.3.6',
	dashagent_dossier_versionne('plugins/auto/saisies/v6.3.4', '6.3.4', '6.3.6')
);
verifier(
	'suffixe à tiret conservé',
	dashagent_dossier_versionne('plugins/champs_extras_core-4.3.3', '4.3.3', '4.3.6') === 'plugins/champs_extras_core-4.3.6',
	dashagent_dossier_versionne('plugins/champs_extras_core-4.3.3', '4.3.3', '4.3.6')
);
verifier(
	'dossier sans numéro : le numéro est ajouté',
	dashagent_dossier_versionne('plugins/saisies', '6.3.4', '6.3.6') === 'plugins/saisies-6.3.6',
	dashagent_dossier_versionne('plugins/saisies', '6.3.4', '6.3.6')
);
verifier(
	'ancien numéro sous une autre forme : remplacé, pas accumulé',
	dashagent_dossier_versionne('plugins/saisies-6.3.4', '', '6.3.6') === 'plugins/saisies-6.3.6',
	dashagent_dossier_versionne('plugins/saisies-6.3.4', '', '6.3.6')
);
verifier(
	'la barre finale ne crée pas de dossier vide',
	dashagent_dossier_versionne('plugins/saisies/v6.3.4/', '6.3.4', '6.3.6') === 'plugins/saisies/v6.3.6',
	dashagent_dossier_versionne('plugins/saisies/v6.3.4/', '6.3.4', '6.3.6')
);
verifier(
	'sans version d’archive, le dossier ne bouge pas',
	dashagent_dossier_versionne('plugins/saisies/v6.3.4', '6.3.4', '') === 'plugins/saisies/v6.3.4'
);
verifier(
	'même version : même dossier',
	dashagent_dossier_versionne('plugins/saisies/v6.3.4', '6.3.4', '6.3.4') === 'plugins/saisies/v6.3.4'
);
verifier(
	'un nom réduit à son numéro reste un numéro',
	dashagent_dossier_versionne('plugins/saisies/6.3.4', '', '6.3.6') === 'plugins/saisies/6.3.6',
	dashagent_dossier_versionne('plugins/saisies/6.3.4', '', '6.3.6')
);
verifier(
	'le « v » du site est conservé même sans ancien numéro connu',
	dashagent_dossier_versionne('plugins/saisies/v6.3.4', '', '6.3.6') === 'plugins/saisies/v6.3.6',
	dashagent_dossier_versionne('plugins/saisies/v6.3.4', '', '6.3.6')
);

verifier(
	'chemin relatif reconstruit sous son parent',
	dashagent_dossier_relatif('auto/saisies/v6.3.4', 'v6.3.6') === 'auto/saisies/v6.3.6',
	dashagent_dossier_relatif('auto/saisies/v6.3.4', 'v6.3.6')
);
verifier(
	'plugin à la racine de plugins/',
	dashagent_dossier_relatif('saisies', 'saisies-6.3.6') === 'saisies-6.3.6',
	dashagent_dossier_relatif('saisies', 'saisies-6.3.6')
);
verifier(
	'sans nouveau nom, le chemin est inchangé',
	dashagent_dossier_relatif('auto/saisies/v6.3.4', '') === 'auto/saisies/v6.3.4'
);

echo "\n== Installation à côté, sans écraser ==\n";

$bac = _DIR_TMP . 'installer-a-cote-' . bin2hex(random_bytes(4)) . '/';
mkdir($bac . 'plugins/auto/saisies/v6.3.4', 0777, true);
mkdir($bac . 'source', 0777, true);
file_put_contents($bac . 'plugins/auto/saisies/v6.3.4/paquet.xml', '<paquet prefix="saisies" version="6.3.4" />');
file_put_contents($bac . 'plugins/auto/saisies/v6.3.4/temoin-ancien.txt', 'ancien');
file_put_contents($bac . 'source/paquet.xml', '<paquet prefix="saisies" version="6.3.6" />');

$ancien  = $bac . 'plugins/auto/saisies/v6.3.4';
$cible   = dashagent_dossier_versionne($ancien, '6.3.4', '6.3.6');
$echange = dashagent_installer_a_cote($ancien, $cible, $bac . 'source');

verifier('déploiement réussi', !empty($echange['ok']), (string) ($echange['erreur'] ?? ''));
verifier('le nouveau dossier porte la nouvelle version', ($echange['dossier'] ?? '') === 'v6.3.6', $echange['dossier'] ?? '');
verifier('les fichiers de la nouvelle version sont en place', is_file($cible . '/paquet.xml'));
verifier('l’ancien dossier a été libéré', !is_dir($ancien));
verifier('une copie de secours a été gardée', ($echange['sauvegarde'] ?? '') !== '', $echange['sauvegarde'] ?? '');
verifier(
	'la copie de secours est cachée au balayage de SPIP',
	strncmp((string) ($echange['sauvegarde'] ?? ''), '.', 1) === 0,
	$echange['sauvegarde'] ?? ''
);
verifier(
	'la copie de secours contient bien l’ancienne version',
	is_file($bac . 'plugins/auto/saisies/' . ($echange['sauvegarde'] ?? 'x') . '/temoin-ancien.txt')
);
verifier(
	'les fichiers de l’ancienne version ne sont pas mélangés aux nouveaux',
	!is_file($cible . '/temoin-ancien.txt')
);

/* Un déploiement précédent a laissé le nom libre convoité : mieux vaut
   s'arrêter que d'écraser ce qu'on ne connaît pas. */
mkdir($bac . 'plugins/auto/saisies/v6.4.0', 0777, true);
mkdir($bac . 'source2', 0777, true);
file_put_contents($bac . 'source2/paquet.xml', '<paquet prefix="saisies" version="6.4.0" />');
$occupe = dashagent_installer_a_cote($cible, $bac . 'plugins/auto/saisies/v6.4.0', $bac . 'source2');
verifier('un dossier déjà occupé interrompt le déploiement', empty($occupe['ok']));
verifier('la version en place est intacte', is_file($cible . '/paquet.xml'));

dashagent_supprimer_repertoire($bac, true);

echo "\n== Délégation à SVP ==\n";

/* La distinction qui compte : un refus de SVP ne se contourne pas en déployant
   l'archive, une indisponibilité si. */
verifier('SVP absent : repli sur l’archive', dashboard_chantier_svp_repli('svp_absent'));
verifier('aucun paquet local : repli sur l’archive', dashboard_chantier_svp_repli('paquet_inconnu'));
/* Sur un site où SVP suit ce plugin, son « aucune mise à jour » est un avis,
   pas une panne : il écarte peut-être une version trop instable ou incompatible.
   Passer outre installerait ce qu'il refuse. */
verifier('aucune mise à jour annoncée : pas de repli', !dashboard_chantier_svp_repli('maj_inconnue'));
verifier('erreur sans code : repli sur l’archive', dashboard_chantier_svp_repli(''));
verifier('refus de dépendance : pas de repli', !dashboard_chantier_svp_repli('dependances'));
verifier('verrou d’un autre administrateur : pas de repli', !dashboard_chantier_svp_repli('verrou'));
verifier('auto/ non inscriptible : pas de repli', !dashboard_chantier_svp_repli('dir_auto'));
verifier('code inconnu : pas de repli', !dashboard_chantier_svp_repli('quelque_chose'));

/* Ce que SVP affiche pendant ses actions ne doit ni casser le JSON ni gonfler
   la réponse. */
verifier('le HTML de SVP est réduit à du texte',
	dashagent_svp_texte('<p>Plugin <b>installé</b></p>') === 'Plugin installé',
	dashagent_svp_texte('<p>Plugin <b>installé</b></p>'));
verifier('les blancs sont resserrés',
	dashagent_svp_texte("a\n\n\t  b") === 'a b', dashagent_svp_texte("a\n\n\t  b"));
$long = dashagent_svp_texte(str_repeat('x', 5000));
verifier('un journal démesuré est coupé', substr($long, 0, 2000) === str_repeat('x', 2000)
	&& substr($long, 2000) === '…', strlen($long));
verifier('un journal vide reste vide', dashagent_svp_texte('') === '');

/* Les tampons de sortie : SVP écrit pendant qu'on lui parle en JSON. */
$niveau = ob_get_level();
ob_start();
echo 'premier';
ob_start();
echo 'second';
$pris = dashagent_svp_vider_tampons($niveau);
verifier('les tampons imbriqués sont rendus dans l’ordre', $pris === 'premiersecond', $pris);
verifier('le niveau de départ est retrouvé', ob_get_level() === $niveau);

echo "\n----------------------------------------\n";
echo ($total - $echecs) . " / $total vérifications passées\n";

exit($echecs === 0 ? 0 : 1);
