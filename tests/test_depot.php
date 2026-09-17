<?php
/**
 * Vérifie le dépôt SVP que fabrique `outils/generer-depot.php`.
 *
 * Un catalogue mal formé ne se voit pas à la publication : il se voit le jour où
 * un site du parc essaie d'ajouter le dépôt et reçoit « le fichier XML n'est pas
 * conforme », sans autre indication. Les règles vérifiées ici sont celles que
 * SVP applique réellement — ses propres expressions régulières sont reprises
 * telles quelles, avec la référence du fichier d'où elles viennent.
 *
 * Usage : php tests/test_depot.php
 */

$racine = dirname(__DIR__);

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
		echo "  ÉCHEC $titre\n";
		if ($detail !== '') {
			echo "         $detail\n";
		}
	}
}

/**
 * Efface une arborescence de travail.
 *
 * @param string $chemin
 * @return void
 */
function depot_effacer($chemin) {
	if (!is_dir($chemin)) {
		return;
	}
	$entrees = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($entrees as $entree) {
		$entree->isDir() ? @rmdir($entree->getPathname()) : @unlink($entree->getPathname());
	}
	@rmdir($chemin);
}

if (!class_exists('ZipArchive')) {
	echo "L’extension zip de PHP est absente : test ignoré.\n";
	exit(0);
}

$travail = sys_get_temp_dir() . '/dashboard-tests/depot-' . getmypid();
depot_effacer($travail);

$url = 'https://exemple.test/parc';
exec(
	'php ' . escapeshellarg($racine . '/outils/generer-depot.php')
	. ' --url=' . escapeshellarg($url)
	. ' --vers=' . escapeshellarg($travail) . ' 2>&1',
	$sortie,
	$code
);

verifier('le générateur s’exécute sans erreur', $code === 0, implode("\n         ", $sortie));
if ($code !== 0) {
	echo "\n----------------------------------------\n";
	echo ($total - $echecs) . " / $total vérifications passées\n";
	exit(1);
}

$catalogue = @file_get_contents($travail . '/plugins.xml');
verifier('le catalogue est écrit', (bool) $catalogue);

/* --------------------------------------------------------------------------
   Les règles de SVP, reprises de son propre code.
   -------------------------------------------------------------------------- */

// `inc/svp_phraser.php` de SVP : c'est à la regexp qu'il lit le catalogue, pas
// avec un analyseur XML — d'où deux blocs frères et non une racine unique.
$regexp_depot    = '#<depot[^>]*>(.*)</depot>#Uims';
$regexp_archive  = '#<archive[^s][^>]*>.*</archive>#Uims';
$regexp_zip      = '#<zip[^>]*>(.*)</zip>#Uims';
$regexp_paquet   = '#<paquet[^>]*>(.*)</paquet>#Uims';

verifier('SVP y trouve le bloc <depot>', (bool) preg_match($regexp_depot, $catalogue));
$nb_archives = preg_match_all($regexp_archive, $catalogue, $archives);
verifier('SVP y trouve au moins une <archive>', $nb_archives > 0);

/* Une racine englobante ferait échouer `svp_phraser_depot()` sur les dépôts
   réels ? Non : il lit à la regexp. Mais un générateur passant par DOMDocument
   en produirait une, et ce serait le premier symptôme d'une réécriture. */
verifier(
	'le catalogue n’est pas enfermé dans une racine unique',
	!preg_match('#<(?!\?)(\w+)[^>]*>\s*<depot\b#i', $catalogue)
);

// `svp_actionner.php` : le téléporteur est choisi sur ce champ, et seul `http`
// concatène `url_archives` et le nom de l'archive.
verifier('le dépôt se déclare de type http', (bool) preg_match('#<type>http</type>#', $catalogue));

preg_match('#<url_archives>(.*?)</url_archives>#s', $catalogue, $trouve);
$url_archives = $trouve[1] ?? '';
verifier('l’URL des archives est absolue', (bool) preg_match('#^https?://#', $url_archives));
verifier(
	'l’URL des archives n’a pas de barre oblique finale',
	$url_archives !== '' && substr($url_archives, -1) !== '/',
	'SVP concatène « url_archives / nom_archive » : une barre de plus en ferait deux'
);
verifier('l’URL des archives découle de celle qu’on a demandée', $url_archives === $url . '/archives');

/* --------------------------------------------------------------------------
   Chaque archive annoncée existe, et pèse ce qui est annoncé.
   -------------------------------------------------------------------------- */

$prefixes = [];
foreach ($archives[0] as $archive) {
	preg_match($regexp_zip, $archive, $z);
	$zip = $z[1] ?? '';
	preg_match('#<file>(.*?)</file>#s', $zip, $f);
	preg_match('#<size>(.*?)</size>#s', $zip, $s);
	preg_match('#<date>(.*?)</date>#s', $zip, $d);
	$fichier = $f[1] ?? '';
	$chemin  = $travail . '/archives/' . $fichier;

	verifier("l’archive annoncée existe : $fichier", is_file($chemin));
	if (!is_file($chemin)) {
		continue;
	}
	verifier("la taille annoncée de $fichier est la bonne", (int) ($s[1] ?? 0) === filesize($chemin));
	verifier("la date de $fichier est un horodatage plausible", (int) ($d[1] ?? 0) > 1000000000);

	verifier("le bloc <paquet> de $fichier est présent", (bool) preg_match($regexp_paquet, $archive, $p));
	preg_match('/\bprefix\s*=\s*"([^"]+)"/i', $archive, $pref);
	preg_match('/\bversion\s*=\s*"([^"]+)"/i', $archive, $vers);
	$prefixe = strtolower($pref[1] ?? '');
	$prefixes[] = $prefixe;

	verifier("le nom de $fichier porte le préfixe et la version", $fichier === $prefixe . '-' . ($vers[1] ?? '') . '.zip');

	/* Le téléporteur retire la racine commune du zip et déballe le reste dans
	   `plugins/auto/<prefixe>/v<version>` (`teleporter/http_deballe_zip.php`,
	   `teleporter_http_charger_zip()`). Deux racines, ou aucune, et le plugin
	   se retrouve à un niveau qui n'est pas celui que SPIP indexe. */
	$archive_zip = new ZipArchive();
	$archive_zip->open($chemin);
	$racines = [];
	$a_paquet = false;
	$scories  = [];
	for ($i = 0; $i < $archive_zip->numFiles; $i++) {
		$nom = $archive_zip->getNameIndex($i);
		$racines[explode('/', $nom)[0]] = true;
		if ($nom === $prefixe . '/paquet.xml') {
			$a_paquet = true;
		}
		if (preg_match('#(^|/)(\.git|node_modules|install\.log)(/|$)#', $nom)) {
			$scories[] = $nom;
		}
	}
	$archive_zip->close();

	verifier("$fichier n’a qu’une racine, « $prefixe »", array_keys($racines) === [$prefixe], implode(', ', array_keys($racines)));
	verifier("$fichier porte son paquet.xml à cette racine", $a_paquet);
	verifier("$fichier n’emporte aucune scorie de travail", !$scories, implode(', ', $scories));
}

verifier(
	'les deux plugins du dépôt sont publiés',
	in_array('tourdecontrole', $prefixes, true) && in_array('tourdecontrole_agent', $prefixes, true),
	implode(', ', $prefixes)
);

/* --------------------------------------------------------------------------
   La variante allégée, et la page d'accueil.
   -------------------------------------------------------------------------- */

/* SVP sonde `plugins.thin.spip-<branche>.xml` puis `plugins.thin.xml` avant
   l'original (`svp_depoter_distant_copie_xml_paquets()`). La publier épargne un
   aller-retour, et protège d'un hébergement qui répondrait 200 à un fichier
   absent : SVP lirait alors sa page d'erreur en guise de catalogue. */
verifier('la variante allégée est publiée', is_file($travail . '/plugins.thin.xml'));
verifier(
	'la variante allégée a le même contenu',
	@file_get_contents($travail . '/plugins.thin.xml') === $catalogue
);

$accueil = (string) @file_get_contents($travail . '/index.html');
verifier('la page d’accueil existe', $accueil !== '');
verifier("la page d’accueil donne l’adresse à coller dans SVP", strpos($accueil, $url . '/plugins.xml') !== false);

/* --------------------------------------------------------------------------
   Le garde-fou : un dossier dont le nom ment sur sa version.
   -------------------------------------------------------------------------- */

/* La convention du dépôt veut qu'un dossier porte sa version. Publier un
   `dashboard-1.0.18` dont le manifeste annonce autre chose mettrait le parc
   devant une version que personne ne retrouverait ensuite : le générateur doit
   refuser, plutôt que de publier un dépôt trompeur. */
$faux = $travail . '-incoherent';
depot_effacer($faux);
mkdir($faux . '/plugins/monplug-1.0.0', 0777, true);
mkdir($faux . '/outils', 0777, true);
copy($racine . '/outils/generer-depot.php', $faux . '/outils/generer-depot.php');
file_put_contents(
	$faux . '/plugins/monplug-1.0.0/paquet.xml',
	'<paquet prefix="monplug" version="9.9.9" etat="test" compatibilite="[4.1.0;4.*]"><nom>Test</nom></paquet>'
);

$sortie_incoherente = [];
$code_incoherent = 0;
exec(
	'php ' . escapeshellarg($faux . '/outils/generer-depot.php')
	. ' --url=https://exemple.test/x --vers=' . escapeshellarg($faux . '/_depot') . ' 2>&1',
	$sortie_incoherente,
	$code_incoherent
);
verifier(
	'un dossier qui ment sur sa version fait échouer la publication',
	$code_incoherent !== 0 && strpos(implode("\n", $sortie_incoherente), 'INCOHÉRENCE') !== false,
	implode("\n         ", $sortie_incoherente)
);

/* Et sans `--url`, rien ne doit être produit : une URL vide donnerait un
   catalogue dont les archives sont introuvables. */
$sortie_sans_url = [];
$code_sans_url = 0;
exec('php ' . escapeshellarg($racine . '/outils/generer-depot.php') . ' 2>&1', $sortie_sans_url, $code_sans_url);
verifier('le générateur refuse de tourner sans --url', $code_sans_url !== 0);

depot_effacer($travail);
depot_effacer($faux);

echo "\n----------------------------------------\n";
echo ($total - $echecs) . " / $total vérifications passées\n";

exit($echecs === 0 ? 0 : 1);
