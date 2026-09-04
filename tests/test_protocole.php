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

echo "\n== Formatage des tailles ==\n";

verifier('octets bruts', dashboard_octets(512) === '512 o', dashboard_octets(512));
verifier('kilo-octets', dashboard_octets(2048) === '2 Ko', dashboard_octets(2048));
verifier('méga-octets', dashboard_octets(5 * 1024 * 1024) === '5 Mo', dashboard_octets(5 * 1024 * 1024));
verifier('zéro', dashboard_octets(0) === '0 o', dashboard_octets(0));

echo "\n----------------------------------------\n";
echo ($total - $echecs) . " / $total vérifications passées\n";

exit($echecs === 0 ? 0 : 1);
