<?php
/**
 * Vérifie que les manifestes et les squelettes pointent vers du code qui existe.
 *
 * Ces erreurs-là ne se voient qu'à l'installation ou au premier clic dans
 * l'espace privé : les attraper ici coûte une seconde, les attraper en
 * production coûte un aller-retour.
 *
 * Usage : php tests/test_structure.php
 */

$racine = dirname(__DIR__);
$plugins = [$racine . '/plugins/dashboard', $racine . '/plugins/dashboard_agent'];

$echecs = 0;
$total  = 0;

/**
 * @param string $titre
 * @param bool $condition
 * @return void
 */
function verifier($titre, $condition) {
	global $echecs, $total;
	$total++;
	if ($condition) {
		echo "  ok   $titre\n";
	} else {
		$echecs++;
		echo "  ÉCHEC $titre\n";
	}
}

/**
 * Liste tous les fichiers d'une arborescence portant l'une des extensions données.
 *
 * @param string $racine
 * @param array $extensions
 * @return array
 */
function fichiers($racine, $extensions) {
	$trouves = [];
	$iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
	foreach ($iterateur as $fichier) {
		if ($fichier->isFile() && in_array(strtolower($fichier->getExtension()), $extensions, true)) {
			$trouves[] = $fichier->getPathname();
		}
	}
	sort($trouves);

	return $trouves;
}

/**
 * Balises acceptées par la DTD de paquet.xml, dans leur ordre attendu.
 *
 * `slogan`, `description` et `install` n'en font pas partie : les deux
 * premières vivent dans lang/paquet-<prefixe>_XX.php, la troisième a disparu
 * avec le format plugin.xml.
 */
$balises_paquet = ['nom', 'auteur', 'licence', 'credit', 'traduire', 'pipeline',
	'necessite', 'utilise', 'procure', 'menu', 'onglet', 'genie', 'script'];

foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	echo "\n== $nom_court : manifeste ==\n";

	$xml = simplexml_load_file($plugin . '/paquet.xml');
	verifier('paquet.xml bien formé', $xml !== false);
	if ($xml === false) {
		continue;
	}

	$prefixe = (string) $xml['prefix'];
	verifier('attribut prefix présent', $prefixe !== '');

	$rang = -1;
	$ordre_ok = true;
	foreach ($xml->children() as $enfant) {
		$balise = $enfant->getName();
		verifier("balise <$balise> reconnue par la DTD", in_array($balise, $balises_paquet, true));
		$position = array_search($balise, $balises_paquet, true);
		if ($position !== false) {
			if ($position < $rang) {
				$ordre_ok = false;
			}
			$rang = $position;
		}
	}
	verifier('balises dans l’ordre attendu par la DTD', $ordre_ok);

	$logo = (string) $xml['logo'];
	verifier("logo présent sur le disque ($logo)", $logo !== '' && is_file($plugin . '/' . $logo));

	// `schema` déclenche l'installation, trouvée par convention de nommage.
	if ((string) $xml['schema'] !== '') {
		$administrations = $plugin . '/' . $prefixe . '_administrations.php';
		verifier("$prefixe" . '_administrations.php présent', is_file($administrations));
		if (is_file($administrations)) {
			$source = file_get_contents($administrations);
			verifier("fonction {$prefixe}_upgrade()", strpos($source, "function {$prefixe}_upgrade(") !== false);
			verifier("fonction {$prefixe}_vider_tables()", strpos($source, "function {$prefixe}_vider_tables(") !== false);
		}
	}

	echo "\n== $nom_court : pipelines ==\n";
	foreach ($xml->pipeline as $pipeline) {
		$nom     = (string) $pipeline['nom'];
		$inclure = (string) $pipeline['inclure'];
		$action  = (string) $pipeline['action'];
		if ($inclure === '') {
			continue;
		}
		$fichier = $plugin . '/' . $inclure;
		verifier("pipeline $nom : fichier $inclure", is_file($fichier));
		if (is_file($fichier) && $action === '') {
			verifier(
				"pipeline $nom : fonction {$prefixe}_{$nom}()",
				strpos(file_get_contents($fichier), "function {$prefixe}_{$nom}(") !== false
			);
		}
	}

	echo "\n== $nom_court : menus ==\n";
	foreach ($xml->menu as $menu) {
		$nom   = (string) $menu['nom'];
		$icone = (string) $menu['icone'];
		$page  = (string) $menu['action'] ?: $nom;
		if ($icone !== '') {
			// L'attribut icone se résout depuis le thème privé, pas la racine du plugin.
			verifier("menu $nom : icône prive/themes/spip/$icone", is_file($plugin . '/prive/themes/spip/' . $icone));
		}
		verifier("menu $nom : page prive/squelettes/contenu/$page.html", is_file($plugin . "/prive/squelettes/contenu/$page.html"));
	}

	echo "\n== $nom_court : squelettes ==\n";
	foreach (fichiers($plugin, ['html']) as $squelette) {
		$source  = file_get_contents($squelette);
		$affiche = str_replace($plugin . '/', '', $squelette);

		if (preg_match_all('/#URL_ACTION_AUTEUR\{([a-z0-9_]+)/', $source, $trouves)) {
			foreach (array_unique($trouves[1]) as $action) {
				$fichier = $plugin . "/action/$action.php";
				verifier("$affiche : action/$action.php", is_file($fichier));
				if (is_file($fichier)) {
					verifier(
						"$affiche : action_{$action}_dist()",
						strpos(file_get_contents($fichier), "function action_{$action}_dist(") !== false
					);
				}
			}
		}

		if (preg_match_all('/#FORMULAIRE_([A-Z0-9_]+)/', $source, $trouves)) {
			foreach (array_unique($trouves[1]) as $formulaire) {
				$base = strtolower($formulaire);
				verifier("$affiche : formulaires/$base.php", is_file($plugin . "/formulaires/$base.php"));
				verifier("$affiche : formulaires/$base.html", is_file($plugin . "/formulaires/$base.html"));
			}
		}
	}

	echo "\n== $nom_court : tâches périodiques ==\n";
	foreach (fichiers($plugin, ['php']) as $fichier) {
		if (!preg_match_all('/\$taches\[\'([a-z0-9_]+)\'\]/', file_get_contents($fichier), $trouves)) {
			continue;
		}
		foreach (array_unique($trouves[1]) as $tache) {
			$genie = $plugin . "/genie/$tache.php";
			verifier("tâche $tache : genie/$tache.php", is_file($genie));
			if (is_file($genie)) {
				verifier(
					"tâche $tache : genie_{$tache}_dist()",
					strpos(file_get_contents($genie), "function genie_{$tache}_dist(") !== false
				);
			}
		}
	}

	echo "\n== $nom_court : langue ==\n";
	verifier(
		"lang/paquet-$prefixe" . '_fr.php présent',
		is_file($plugin . "/lang/paquet-$prefixe" . '_fr.php')
	);
	foreach (glob($plugin . '/lang/*_fr.php') as $reference) {
		$module = basename($reference, '_fr.php');
		$traduction = $plugin . "/lang/$module" . '_en.php';
		if (!is_file($traduction)) {
			continue;
		}
		preg_match_all("/^\t'([a-z0-9_]+)'\s*=>/m", file_get_contents($reference), $a);
		preg_match_all("/^\t'([a-z0-9_]+)'\s*=>/m", file_get_contents($traduction), $b);
		verifier("$module : traduction anglaise complète", !array_diff($a[1], $b[1]));
		verifier("$module : pas de clef surnuméraire en anglais", !array_diff($b[1], $a[1]));
	}
}

echo "\n== Cohérence installation / déclaration des tables ==\n";

foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	$xml       = simplexml_load_file($plugin . '/paquet.xml');
	$prefixe   = (string) $xml['prefix'];
	$schema    = (string) $xml['schema'];
	$administrations = $plugin . '/' . $prefixe . '_administrations.php';
	if ($schema === '' || !is_file($administrations)) {
		continue;
	}
	$install = file_get_contents($administrations);

	$declarees = [];
	$base = '';
	foreach (glob($plugin . '/base/*.php') as $fichier) {
		$source = file_get_contents($fichier);
		$base .= $source;
		preg_match_all('/\$tables\[\'(spip_[a-z0-9_]+)\'\]/', $source, $trouves);
		$declarees = array_merge($declarees, $trouves[1]);
	}
	$declarees = array_unique($declarees);
	verifier("$nom_court : des tables sont déclarées", (bool) $declarees);

	// Les descripteurs doivent être accessibles sans passer par le registre de
	// SPIP : c'est ce qui permet à l'installation de se vérifier elle-même.
	verifier(
		"$nom_court : {$prefixe}_descriptions_tables() expose les descripteurs",
		strpos($base, "function {$prefixe}_descriptions_tables(") !== false
	);
	verifier(
		"$nom_court : l’installation appelle {$prefixe}_creer_tables",
		strpos($install, "{$prefixe}_creer_tables") !== false
	);
	verifier(
		"$nom_court : la création contrôle son résultat",
		strpos($install, "{$prefixe}_tables_manquantes(") !== false
	);

	foreach ($declarees as $table) {
		verifier("$nom_court : $table supprimée par la désinstallation", strpos($install, "sql_drop_table('$table')") !== false);

		// Sans correspondance nom de boucle -> table, le compilateur répond
		// « Table SQL inconnue » alors que la table existe bel et bien. Seules
		// les tables réellement parcourues par un squelette sont concernées.
		$boucle = preg_replace('/^spip_/', '', $table);
		$bouclee = false;
		foreach (fichiers($plugin, ['html']) as $squelette) {
			if (preg_match('/<BOUCLE[a-z0-9_]*\\(' . strtoupper($boucle) . '\\)/i', file_get_contents($squelette))) {
				$bouclee = true;
				break;
			}
		}
		if ($bouclee) {
			verifier(
				"$nom_court : <BOUCLE(" . strtoupper($boucle) . ")> associée à $table",
				strpos($base, "['table_des_tables']['$boucle']") !== false
			);
		}
	}

	// Une table déclarée seulement comme objet éditorial n'est pas créée :
	// maj_tables() ne consulte pas ce registre-là.
	if (preg_match_all('/\\$tables\\[\'(spip_[a-z0-9_]+)\'\\]/', $base, $t)
		&& preg_match('/function ' . $prefixe . '_declarer_tables_objets_sql\\(.*?\\n}/s', $base, $bloc)
	) {
		preg_match_all('/\\$tables\\[\'(spip_[a-z0-9_]+)\'\\]/', $bloc[0], $objets);
		preg_match('/function ' . $prefixe . '_declarer_tables_principales\\(.*?\\n}/s', $base, $bloc_principales);
		foreach (array_unique($objets[1]) as $table) {
			verifier(
				"$nom_court : $table déclarée aussi comme table principale",
				!empty($bloc_principales[0]) && strpos($bloc_principales[0], $table) !== false
			);
		}
	}

	// Créer des tables sans réinitialiser les caches laisse le compilateur sur
	// une vue périmée du schéma.
	verifier(
		"$nom_court : la création réinitialise les caches de schéma",
		strpos($install, "charger_fonction('trouver_table', 'base'") !== false
	);

	// « create » n'étant jamais rejouée, il faut une étape versionnée au niveau
	// du schéma pour rattraper une installation restée incomplète.
	preg_match_all('/\$maj\[\'([0-9]+\.[0-9]+\.[0-9]+)\'\]/', $install, $trouves);
	$etapes = array_unique($trouves[1]);
	verifier("$nom_court : au moins une étape de migration versionnée", (bool) $etapes);
	if ($etapes) {
		usort($etapes, 'version_compare');
		$plus_haute = end($etapes);
		verifier("$nom_court : schema=$schema aligné sur la plus haute étape ($plus_haute)", $schema === $plus_haute);
	}

	// Marqueur de dernière modification attendu par SPIP sur toute table gérée.
	foreach (glob($plugin . '/base/*.php') as $fichier) {
		$source = file_get_contents($fichier);
		foreach ($declarees as $table) {
			$position = strpos($source, "\$tables['$table']");
			if ($position === false) {
				continue;
			}
			$suite = substr($source, $position, 3000);
			verifier("$nom_court : $table porte une colonne maj TIMESTAMP", strpos($suite, "'maj'") !== false);
		}
	}
}

echo "\n== Filtres appelés par les squelettes ==\n";

foreach ($plugins as $plugin) {
	$prefixe = (string) simplexml_load_file($plugin . '/paquet.xml')['prefix'];
	$fonctions = $plugin . '/' . $prefixe . '_fonctions.php';
	$disponibles = '';
	foreach (array_merge(glob($plugin . '/*_fonctions.php'), glob($plugin . '/inc/*.php')) as $fichier) {
		$disponibles .= file_get_contents($fichier);
	}
	foreach (fichiers($plugin, ['html']) as $squelette) {
		$affiche = str_replace($plugin . '/', '', $squelette);
		preg_match_all('/\|(' . $prefixe . '_[a-z0-9_]+)/', file_get_contents($squelette), $trouves);
		foreach (array_unique($trouves[1]) as $filtre) {
			verifier("$affiche : filtre |$filtre défini", strpos($disponibles, "function $filtre(") !== false);
		}
	}
	if (glob($plugin . '/*_fonctions.php')) {
		verifier(basename($plugin) . " : {$prefixe}_fonctions.php chargé automatiquement", is_file($fonctions));
	}
}

echo "\n== Clefs de langue référencées ==\n";

$modules = [];
foreach ($plugins as $plugin) {
	foreach (glob($plugin . '/lang/*_fr.php') as $fichier) {
		preg_match_all("/^\t'([a-z0-9_]+)'\s*=>/m", file_get_contents($fichier), $trouves);
		$modules[basename($fichier, '_fr.php')] = array_flip($trouves[1]);
	}
}

$manquantes = [];
$verifiees  = 0;
foreach ($plugins as $plugin) {
	foreach (fichiers($plugin, ['html', 'php', 'xml']) as $fichier) {
		$source = file_get_contents($fichier);
		$refs = [];
		preg_match_all('/<:([a-z0-9_\-]+):([a-z0-9_]+)[:{]/', $source, $t, PREG_SET_ORDER);
		$refs = array_merge($refs, $t);
		preg_match_all('/_T\(\s*\'([a-z0-9_\-]+):([a-z0-9_]+)\'/', $source, $t, PREG_SET_ORDER);
		$refs = array_merge($refs, $t);
		preg_match_all('/=>\s*\'(dashboard|dashagent):([a-z0-9_]+)\'/', $source, $t, PREG_SET_ORDER);
		$refs = array_merge($refs, $t);
		preg_match_all('/titre="([a-z0-9_\-]+):([a-z0-9_]+)"/', $source, $t, PREG_SET_ORDER);
		$refs = array_merge($refs, $t);

		foreach ($refs as $ref) {
			// Les modules du core (spip:, ecrire:, public:) ne sont pas vérifiables ici.
			if (!isset($modules[$ref[1]])) {
				continue;
			}
			$verifiees++;
			if (!isset($modules[$ref[1]][$ref[2]])) {
				$manquantes[$ref[1] . ':' . $ref[2]] = str_replace($plugin . '/', '', $fichier);
			}
		}
	}
}

verifier("$verifiees références de langue résolues", !$manquantes);
foreach ($manquantes as $clef => $ou) {
	echo "         manque $clef (utilisée dans $ou)\n";
}

echo "\n----------------------------------------\n";
echo ($total - $echecs) . " / $total vérifications passées\n";

exit($echecs === 0 ? 0 : 1);
