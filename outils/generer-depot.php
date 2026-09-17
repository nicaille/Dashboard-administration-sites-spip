<?php
/**
 * Fabrique un dépôt SVP à partir des plugins du présent dépôt git.
 *
 * Un dépôt SVP n'est pas un service : c'est un catalogue XML et un répertoire
 * d'archives, servis en HTTP. N'importe quel hébergement statique fait
 * l'affaire — ici, GitHub Pages. Ce script produit les deux.
 *
 * Usage :
 *     php outils/generer-depot.php --url=https://exemple.github.io/parc [--vers=_depot]
 *
 * Le résultat :
 *     <vers>/plugins.xml              le catalogue, à coller dans SVP
 *     <vers>/archives/<prefixe>-<version>.zip
 *     <vers>/index.html              une page d'accueil lisible par un humain
 *
 * @package SPIP\Dashboard\Outils
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Ce script s’exécute en ligne de commande.\n");
	exit(2);
}

$racine = dirname(__DIR__);

$options = ['url' => '', 'vers' => $racine . '/_depot'];
foreach (array_slice($argv, 1) as $argument) {
	if (preg_match('/^--([a-z]+)=(.*)$/', $argument, $trouve)) {
		$options[$trouve[1]] = $trouve[2];
	}
}

if ($options['url'] === '') {
	fwrite(STDERR, "usage : php outils/generer-depot.php --url=https://… [--vers=répertoire]\n");
	exit(2);
}
if (!class_exists('ZipArchive')) {
	fwrite(STDERR, "L’extension zip de PHP est nécessaire pour fabriquer les archives.\n");
	exit(2);
}

// Sans barre oblique finale : le catalogue en ajoute une là où il en faut une,
// et SVP concatène `url_archives . '/' . <file>` sans en ajouter.
$url_base = rtrim($options['url'], '/');
$vers     = rtrim($options['vers'], '/');

/**
 * Lit le `paquet.xml` d'un plugin et en retient ce que le catalogue demande.
 *
 * Le bloc `<paquet>` est recopié **tel quel** dans le catalogue : c'est lui que
 * SVP relit pour connaître les dépendances, la compatibilité et l'état du
 * plugin. Le reformater, ce serait risquer d'en perdre en route.
 *
 * @param string $dossier
 * @return array{prefixe: string, version: string, nom: string, bloc: string}|null
 */
function depot_lire_paquet($dossier) {
	$source = @file_get_contents($dossier . '/paquet.xml');
	if ($source === false) {
		return null;
	}
	if (!preg_match('#<paquet\b.*</paquet>#is', $source, $trouve)) {
		return null;
	}
	$bloc = $trouve[0];

	if (
		!preg_match('/\bprefix\s*=\s*"([^"]+)"/i', $bloc, $p)
		|| !preg_match('/\bversion\s*=\s*"([^"]+)"/i', $bloc, $v)
	) {
		return null;
	}
	$nom = preg_match('#<nom>(.*?)</nom>#is', $bloc, $n) ? trim($n[1]) : $p[1];

	return ['prefixe' => $p[1], 'version' => $v[1], 'nom' => $nom, 'bloc' => $bloc];
}

/**
 * Date du dernier commit touchant un répertoire, en secondes.
 *
 * Elle sert deux fois : SVP l'affiche comme date du paquet, et elle horodate
 * les fichiers de l'archive — de sorte que régénérer le dépôt deux fois de
 * suite depuis le même commit produise deux zips identiques.
 *
 * @param string $dossier
 * @return int
 */
function depot_date_commit($dossier) {
	$sortie = [];
	$code   = 0;
	@exec('git -C ' . escapeshellarg($dossier) . ' log -1 --format=%ct -- . 2>/dev/null', $sortie, $code);
	$date = ($code === 0 && $sortie) ? (int) trim((string) $sortie[0]) : 0;

	return $date ?: (int) @filemtime($dossier);
}

/**
 * Liste les fichiers d'un plugin, triés, sans les scories de travail.
 *
 * Le tri importe : l'ordre de parcours du système de fichiers n'est pas garanti,
 * et une archive dont l'ordre change à chaque exécution n'est plus comparable
 * d'une publication à l'autre.
 *
 * @param string $dossier
 * @return array
 */
function depot_fichiers($dossier) {
	$ecartes = ['.git', '.github', 'node_modules', '.DS_Store', 'install.log'];
	$fichiers = [];

	$parcours = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS),
			function ($fichier) use ($ecartes) {
				return !in_array($fichier->getFilename(), $ecartes, true);
			}
		),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ($parcours as $fichier) {
		$fichiers[] = $fichier->getPathname();
	}
	sort($fichiers, SORT_STRING);

	return $fichiers;
}

/**
 * Fabrique l'archive d'un plugin.
 *
 * Le zip contient une racine unique, du nom du préfixe : le téléporteur de SVP
 * la retire au déballage (`teleporter_http_charger_zip()`), et range le contenu
 * dans `plugins/auto/<prefixe>/v<version>`. Un zip sans racine commune
 * fonctionnerait aussi, mais serait désagréable à ouvrir à la main.
 *
 * @param string $dossier
 * @param string $archive
 * @param string $racine_zip
 * @param int $date
 * @return bool
 */
function depot_fabriquer_zip($dossier, $archive, $racine_zip, $date) {
	$zip = new ZipArchive();
	if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
		return false;
	}
	$zip->addEmptyDir($racine_zip);

	foreach (depot_fichiers($dossier) as $fichier) {
		$interne = $racine_zip . '/' . ltrim(substr($fichier, strlen($dossier)), '/');
		if (!$zip->addFile($fichier, $interne)) {
			$zip->close();

			return false;
		}
		// Horodater sur le commit plutôt que sur la copie de travail : deux
		// exécutions depuis le même commit donnent alors le même fichier.
		if (method_exists($zip, 'setMtimeName')) {
			@$zip->setMtimeName($interne, $date);
		}
	}

	return $zip->close();
}

/**
 * Échappe une valeur destinée au corps d'une balise du catalogue.
 *
 * @param string $valeur
 * @return string
 */
function depot_xml($valeur) {
	return htmlspecialchars((string) $valeur, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// ---------------------------------------------------------------------------

$dossiers = glob($racine . '/plugins/*', GLOB_ONLYDIR) ?: [];
sort($dossiers, SORT_STRING);

if (!$dossiers) {
	fwrite(STDERR, "Aucun plugin dans plugins/.\n");
	exit(1);
}

@mkdir($vers . '/archives', 0777, true);
if (!is_dir($vers . '/archives')) {
	fwrite(STDERR, "Impossible de créer $vers/archives\n");
	exit(1);
}

$archives = [];
$publies  = [];

foreach ($dossiers as $dossier) {
	$paquet = depot_lire_paquet($dossier);
	if (!$paquet) {
		fwrite(STDERR, 'Ignoré (paquet.xml illisible) : ' . basename($dossier) . "\n");
		continue;
	}

	// La règle du dépôt — un dossier porte sa version — se vérifie ici, au
	// moment où elle compte vraiment : publier un dossier `dashboard-1.0.18`
	// dont le manifeste annonce 1.0.19 mettrait tout le parc devant une version
	// que personne ne retrouverait ensuite.
	if (preg_match('/-([0-9][0-9a-z.\-]*)$/i', basename($dossier), $suffixe)) {
		if ($suffixe[1] !== $paquet['version']) {
			fwrite(
				STDERR,
				'INCOHÉRENCE : ' . basename($dossier) . ' annonce la version ' . $paquet['version']
				. " dans son paquet.xml.\n"
			);
			exit(1);
		}
	}

	$nom_archive = strtolower($paquet['prefixe']) . '-' . $paquet['version'] . '.zip';
	$chemin_zip  = $vers . '/archives/' . $nom_archive;
	$date        = depot_date_commit($dossier);

	if (!depot_fabriquer_zip($dossier, $chemin_zip, strtolower($paquet['prefixe']), $date)) {
		fwrite(STDERR, "Échec de la fabrication de $nom_archive\n");
		exit(1);
	}

	$archives[] = "\t<archive dtd=\"paquet\">\n"
		. "\t\t<zip>\n"
		. "\t\t\t<file>" . depot_xml($nom_archive) . "</file>\n"
		. "\t\t\t<size>" . filesize($chemin_zip) . "</size>\n"
		. "\t\t\t<date>" . $date . "</date>\n"
		. "\t\t\t<last_commit>" . gmdate('Y-m-d H:i:s', $date) . "</last_commit>\n"
		. "\t\t\t<source>" . depot_xml($url_base . '/archives/' . $nom_archive) . "</source>\n"
		. "\t\t</zip>\n"
		. preg_replace('/^/m', "\t\t", $paquet['bloc']) . "\n"
		. "\t</archive>";

	$publies[] = $paquet;
	echo '  ' . str_pad($paquet['prefixe'], 12) . ' ' . str_pad($paquet['version'], 10)
		. ' ' . number_format(filesize($chemin_zip) / 1024, 0, ',', ' ') . " Ko\n";
}

if (!$archives) {
	fwrite(STDERR, "Aucun plugin publiable.\n");
	exit(1);
}

/* Le catalogue porte deux blocs frères, `<depot>` et `<archives>` : ce n'est
   donc pas un document XML à racine unique, et c'est le format que SVP attend.
   Il le lit à la regexp (`svp_phraser_depot()`), jamais par un analyseur strict
   — une racine englobante le ferait échouer. */
$catalogue = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
	. "<depot>\n"
	. "\t<titre>Dashboard — administration de sites SPIP</titre>\n"
	. "\t<descriptif>Les plugins du tableau de bord et de son agent.</descriptif>\n"
	// `http` désigne le téléporteur : SVP télécharge alors `url_archives` suivi
	// du nom de l'archive. Les autres valeurs (`git`, `svn`) attendent un dépôt
	// de sources, pas des zips.
	. "\t<type>http</type>\n"
	. "\t<url_archives>" . depot_xml($url_base . '/archives') . "</url_archives>\n"
	. "\t<url_brouteur>" . depot_xml($url_base . '/') . "</url_brouteur>\n"
	. "</depot>\n"
	. "<archives>\n"
	. implode("\n", $archives) . "\n"
	. "</archives>\n";

file_put_contents($vers . '/plugins.xml', $catalogue);

/* SVP ne demande pas le catalogue tout de suite : il sonde d'abord des variantes
   allégées, `plugins.thin.spip-<branche>.xml` puis `plugins.thin.xml`, et ne
   retombe sur l'original qu'après deux 404
   (`svp_depoter_distant_copie_xml_paquets()`). Publier la variante générique
   épargne un aller-retour — et surtout, elle met le dépôt à l'abri d'un
   hébergement qui répondrait 200 à un fichier absent : SVP y lirait sa page
   d'erreur en guise de catalogue. Nos deux plugins couvrant les mêmes versions
   de SPIP, la variante n'a rien à alléger : c'est le même fichier. */
copy($vers . '/plugins.xml', $vers . '/plugins.thin.xml');

// Une page d'accueil : quelqu'un finira par ouvrir l'URL dans un navigateur, et
// une liste de fichiers ne dit pas quoi en faire.
$lignes = '';
foreach ($publies as $paquet) {
	$lignes .= "\t\t<li><strong>" . depot_xml($paquet['nom']) . '</strong> — '
		. depot_xml($paquet['prefixe']) . ' ' . depot_xml($paquet['version']) . "</li>\n";
}
$xml_public = depot_xml($url_base . '/plugins.xml');

file_put_contents($vers . '/index.html', <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Dépôt de plugins — Dashboard SPIP</title>
	<style>
		body { max-width: 42rem; margin: 3rem auto; padding: 0 1rem; line-height: 1.6;
			font-family: system-ui, sans-serif; }
		code { background: #f4f4f4; padding: .15em .4em; border-radius: 3px; word-break: break-all; }
	</style>
</head>
<body>
	<h1>Dépôt de plugins</h1>
	<p>Ce dépôt se déclare dans un site SPIP muni du plugin <strong>SVP</strong> :
	espace privé, <em>Plugins</em> → <em>Dépôts</em> → <em>Ajouter un dépôt</em>,
	puis coller l'adresse suivante&nbsp;:</p>
	<p><code>{$xml_public}</code></p>
	<h2>Plugins publiés</h2>
	<ul>
{$lignes}	</ul>
</body>
</html>

HTML);

echo "\nCatalogue : $vers/plugins.xml\n";
echo 'À coller dans SVP : ' . $url_base . "/plugins.xml\n";
