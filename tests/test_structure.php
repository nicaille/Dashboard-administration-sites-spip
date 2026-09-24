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

/**
 * Les dossiers de plugins portent leur version (`tourdecontrole-1.0.32`), pour qu'une
 * mise en ligne n'écrase pas la version précédente. On les retrouve donc par
 * préfixe, sans quoi ce fichier serait à retoucher à chaque montée de version.
 *
 * @param string $prefixe
 * @return string
 */
function chemin_plugin($prefixe) {
	global $racine;
	$trouves = glob($racine . '/plugins/' . $prefixe . '-*', GLOB_ONLYDIR);
	if (!$trouves) {
		fwrite(STDERR, "Plugin introuvable : $prefixe\n");
		exit(2);
	}
	usort($trouves, 'version_compare');

	return end($trouves);
}

$plugins = [chemin_plugin('tourdecontrole'), chemin_plugin('tourdecontrole_agent')];

/**
 * Famille de fonctions de chaque plugin, quand elle diffère de son préfixe.
 *
 * SPIP ne dérive du préfixe que ce qu'il va chercher lui-même : les fichiers
 * `<prefixe>_fonctions.php`, `<prefixe>_options.php`,
 * `<prefixe>_administrations.php`, les fonctions de pipeline
 * `<prefixe>_autoriser()`, `<prefixe>_declarer_tables_*()`, la meta
 * `<prefixe>_base_version` et le module de langue `paquet-<prefixe>`.
 *
 * Les fonctions internes, les filtres de squelette et les noms de tables ne sont
 * dérivés de rien : ce sont des noms globaux, que SPIP trouve par leur nom. Les
 * deux plugins ont donc gardé le leur — `dashboard_` et `dashagent_` — quand
 * leur préfixe a changé en 1.0.19 et 1.0.15. Les renommer en masse aurait touché
 * deux cents fonctions et le nom des tables, donc les données des sites
 * installés, pour un gain nul.
 *
 * Ce tableau est ce qui permet au test de continuer à voir les filtres. Sans
 * lui, il cherchait `|tourdecontrole_…` dans les squelettes, n'en trouvait
 * aucun, et passait au vert en ayant cessé de vérifier trente-six filtres.
 */
$familles = [
	'tourdecontrole'       => 'dashboard',
	'tourdecontrole_agent' => 'dashagent',
];

$echecs = 0;
$total  = 0;

/**
 * @param string $titre
 * @param bool $condition
 * @return void
 */
/**
 * Le corps d'une fonction PHP, pris dans un fichier source.
 *
 * De quoi confronter deux écritures sans relire tout un fichier : ce qu'une
 * fonction envoie d'un côté, ce qu'une autre lit de l'autre.
 *
 * @param string $source
 * @param string $nom
 * @return string Chaîne vide si la fonction est introuvable
 */
function fonction_php($source, $nom) {
	$debut = strpos($source, 'function ' . $nom . '(');
	if ($debut === false) {
		return '';
	}
	$ouvrante = strpos($source, '{', $debut);
	if ($ouvrante === false) {
		return '';
	}
	$niveau = 0;
	for ($i = $ouvrante, $n = strlen($source); $i < $n; $i++) {
		if ($source[$i] === '{') {
			$niveau++;
		} elseif ($source[$i] === '}') {
			$niveau--;
			if ($niveau === 0) {
				return substr($source, $ouvrante, $i - $ouvrante + 1);
			}
		}
	}

	return '';
}

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
	/*
	 * SPIP 4.4 déprécie le fichier de langue qui remplit une globale : il doit
	 * **rendre** son tableau. `lire_fichier_langue()` fait `include $fichier`,
	 * prend le retour s'il est un tableau, et ne retombe sur
	 * `$GLOBALS[$GLOBALS['idx_lang']]` qu'en signalant la dépréciation.
	 *
	 * Mais les paquets se déclarent compatibles depuis SPIP 4.1, dont le
	 * chargeur ne regarde que la globale : s'en tenir au retour y effacerait
	 * toutes les chaînes du module — bien pire que l'avertissement réparé. Les
	 * deux formes cohabitent donc, départagées par la présence de la fonction
	 * qui a apporté la nouvelle.
	 *
	 * Et le garde-fou `_ECRIRE_INC_VERSION` n'a plus sa place ici : un
	 * `return;` nu rendrait `null`, que SPIP refuse — « Fichier de langue
	 * incorrect », et le module perd tout. Les fichiers de langue du core n'en
	 * portent aucun.
	 */
	foreach (glob($plugin . '/lang/*.php') as $fichier) {
		$source = file_get_contents($fichier);
		$nom    = basename($fichier);
		verifier("$nom rend son tableau", (bool) preg_match('/^return \$lang;$/m', $source));
		verifier("$nom ne rend jamais null", strpos($source, "\n	return;\n") === false);
		if (strpos($source, 'idx_lang') !== false) {
			verifier("$nom ne remplit la globale que pour les chargeurs d’avant 4.4",
				strpos($source, "!function_exists('lire_fichier_langue')") !== false);
		}
	}

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
	/* Le préfixe a changé en 1.0.19 / 1.0.15, et SPIP nomme la meta de version de
	   schéma d'après lui : sans reprise de l'ancienne, il croit le plugin neuf et
	   rejoue toutes les migrations sur une base déjà à jour. */
	verifier(
		"$nom_court : l’installation reprend la version de schéma de l’ancien préfixe",
		strpos($install, '_reprendre_schema($nom_meta_base_version)') !== false
	);

	// `tables_manquantes()` n'est pas dérivée du préfixe : c'est un utilitaire
	// interne, donc il porte le nom de la famille de fonctions du plugin.
	verifier(
		"$nom_court : la création contrôle son résultat",
		strpos($install, ($familles[$prefixe] ?? $prefixe) . '_tables_manquantes(') !== false
	);

	/* Le garde-fou qui dit « les tables sont là » portait sa propre liste,
	   écrite à la main et restée à quatre tables quand le plugin en déclare
	   sept. Une base amputée de l'une des trois oubliées passait donc pour
	   saine : les pages s'affichaient normalement, et l'absence ne se
	   manifestait que par une « erreur mysql 1146 » au fond d'un journal.

	   Une liste de tables écrite à la main ne suit pas les tables qu'on
	   ajoute, et rien ne le signale. On exige donc qu'elle soit dérivée des
	   déclarations — le seul endroit qui ne puisse pas se désynchroniser. */
	$famille = $familles[$prefixe] ?? $prefixe;
	foreach (fichiers($plugin, ['php']) as $fichier) {
		$source = file_get_contents($fichier);
		$debut = strpos($source, 'function ' . $famille . '_tables_presentes(');
		if ($debut === false) {
			continue;
		}
		$corps = substr($source, $debut, 2000);
		$corps = substr($corps, 0, strpos($corps . "\n}\n", "\n}\n"));

		verifier(
			"$nom_court : le garde-fou des tables lit les déclarations",
			strpos($corps, $prefixe . '_descriptions_tables()') !== false
		);
		$en_dur = '/\'spip_[a-z0-9_]+\'/';
		verifier(
			"$nom_court : le garde-fou des tables n’écrit aucune table en dur",
			!preg_match($en_dur, $corps),
			premiere_occurrence($en_dur, $corps)
		);
	}

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

	/* Marqueur de dernière modification attendu par SPIP sur toute table gérée.

	   La déclaration se lisait sur une fenêtre de trois mille caractères, ce
	   qui laissait déborder sur la table suivante : une table pouvait passer
	   au vert en trouvant le « maj » de sa voisine. Et une table déclarée par
	   un appel de fonction — `$tables['x'] = schema_x();` — n'avait même pas
	   sa propre déclaration dans la fenêtre. Un contrôle qui trouve la bonne
	   valeur au mauvais endroit ne vérifie rien.

	   On découpe donc sur la déclaration suivante, et on suit l'appel de
	   fonction quand il y en a un. */
	foreach (glob($plugin . '/base/*.php') as $fichier) {
		$source = file_get_contents($fichier);
		foreach ($declarees as $table) {
			if (strpos($source, "\$tables['$table']") === false) {
				continue;
			}

			/* Une même table est souvent déclarée deux fois : une fois comme
			   objet éditorial, une fois comme table principale. Seule la
			   seconde porte les colonnes. On les lit donc toutes, et il suffit
			   que l'une d'elles convienne. */
			$porte = false;
			$depart = 0;
			while (($position = strpos($source, "\$tables['$table']", $depart)) !== false) {
				$depart = $position + 1;

				$suite = substr($source, $depart);
				$fin   = strpos($suite, "\$tables['");
				$bloc  = $fin === false ? $suite : substr($suite, 0, $fin);

				// Déclarée par une fonction : c'est son corps qu'il faut lire.
				if (preg_match('/=\s*([a-z_][a-z0-9_]*)\(\s*\)\s*;/i', $bloc, $appel)) {
					$debut = strpos($source, 'function ' . $appel[1] . '(');
					if ($debut !== false) {
						$bloc .= substr($source, $debut, 3000);
					}
				}

				if (strpos($bloc, "'maj'") !== false) {
					$porte = true;
					break;
				}
			}

			verifier("$nom_court : $table porte une colonne maj TIMESTAMP", $porte);
		}
	}
}

echo "\n== Aucun préfixe d’avant le renommage ne traîne ==\n";

/* Le bug qui motive ce test : `dashagent_version_plugin()` cherchait encore
   `$infos['DASHAGENT']` dans la liste des plugins actifs, que SPIP range sous le
   préfixe **courant**. Elle rendait « 0 », et le parc affichait « Version de
   l’agent : 0 » sur tous les sites — sans que rien n’échoue par ailleurs.

   Une lecture de meta figée sur un ancien préfixe ne casse rien de visible : elle
   rend une valeur par défaut, et se remarque des semaines plus tard. */
$anciens = ['DASHAGENT', 'DASHBOARD'];
$fautes  = [];
foreach ($plugins as $plugin) {
	$prefixe_courant = strtoupper((string) simplexml_load_file($plugin . '/paquet.xml')['prefix']);
	foreach (fichiers($plugin, ['php']) as $fichier) {
		$source = file_get_contents($fichier);
		foreach ($anciens as $ancien) {
			if ($ancien === $prefixe_courant) {
				continue;
			}
			// Seules les lectures de la liste des plugins actifs sont en cause :
			// le nom peut légitimement figurer ailleurs, dans un commentaire ou
			// une chaîne de compatibilité.
			if (preg_match('/\[\s*.' . $ancien . '.\s*\]\s*\[/', $source)) {
				$fautes[] = str_replace($plugin . '/', '', $fichier) . " : \$…['$ancien'][…]";
			}
		}
	}
}
verifier('aucune lecture de plugin actif ne vise un préfixe abandonné', !$fautes, implode(' ; ', $fautes));

/* Et la contrepartie : le préfixe courant doit bien être cherché quelque part,
   sans quoi le test ci-dessus passerait sur du code qui ne cherche plus rien. */
$agent = chemin_plugin('tourdecontrole_agent');
$version = file_get_contents($agent . '/inc/dashagent.php');
verifier('l’agent lit sa version sous son préfixe courant',
	strpos($version, 'TOURDECONTROLE_AGENT') !== false);

/* La tour de contrôle, elle, nomme le préfixe de l'agent pour savoir quels
   sites ont un agent à mettre à jour. Cette lecture-là souffre du même mal :
   un préfixe périmé ne lève aucune erreur, il ne trouve simplement aucun site.
   Le bouton disparaîtrait de la vue d'ensemble, et personne ne saurait dire
   depuis quand. On relit donc la valeur dans le paquet.xml de l'agent. */
$parc = chemin_plugin('tourdecontrole');
$fonctions = file_get_contents($parc . '/tourdecontrole_fonctions.php');
$prefixe_agent = strtoupper((string) simplexml_load_file($agent . '/paquet.xml')['prefix']);
preg_match('/function\s+dashboard_prefixe_agent\s*\(\)\s*\{\s*return\s+\x27([^\x27]+)\x27/', $fonctions, $declare);
verifier(
	'le parc nomme l’agent par son préfixe courant',
	($declare[1] ?? '') === $prefixe_agent,
	($declare[1] ?? '(absent)') . ' vs ' . $prefixe_agent
);

echo "\n== Autorisations ==\n";

foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	$prefixe   = (string) simplexml_load_file($plugin . '/paquet.xml')['prefix'];
	$fichier   = $plugin . "/{$prefixe}_autorisations.php";
	if (!is_file($fichier)) {
		continue;
	}
	$source = file_get_contents($fichier);

	// La chaîne de résolution de SPIP essaie plusieurs noms pour un même
	// contrôle. Si l'un délègue à un autre, ou repasse par autoriser(), la
	// récursion est sans fin — et le symptôme est un dépassement de pile très
	// loin de la cause.
	preg_match_all('/function\s+(autoriser_[a-z0-9_]+)\s*\([^)]*\)\s*\{(.*?)\n\}/s', $source, $trouves, PREG_SET_ORDER);
	verifier("$nom_court : des fonctions d’autorisation sont définies", (bool) $trouves);
	foreach ($trouves as $fonction) {
		verifier(
			"$nom_court : {$fonction[1]}() ne délègue à aucune autre autorisation",
			!preg_match('/\bautoriser[_(]/', $fonction[2])
		);
	}

	// Sans ce chargement, autoriser() n'existe pas encore dans un fichier action.
	foreach (glob($plugin . '/action/*.php') as $action) {
		$code = file_get_contents($action);
		if (strpos($code, 'autoriser(') === false) {
			continue;
		}
		verifier(
			"$nom_court : " . basename($action) . " charge inc/autoriser",
			strpos($code, "include_spip('inc/autoriser')") !== false
		);
	}
}

echo "\n== Conventions de nommage des objets ==\n";

foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	$prefixe   = (string) simplexml_load_file($plugin . '/paquet.xml')['prefix'];
	$fichier   = $plugin . "/base/{$prefixe}_tables.php";
	if (!is_file($fichier)) {
		continue;
	}
	preg_match_all("/'type'\s*=>\s*'([a-z0-9_]+)'/", file_get_contents($fichier), $types);
	if (empty($types[1])) {
		continue;
	}

	$code = '';
	foreach (fichiers($plugin, ['php']) as $f) {
		$code .= file_get_contents($f);
	}

	// objet_inserer('x', …) appelle x_inserer() si elle existe, objet_modifier()
	// appelle x_modifier(), etc. Définir ces fonctions et y appeler l'API
	// générique produit une récursion infinie : chacune rappelle l'autre.
	foreach (array_unique($types[1]) as $type) {
		foreach (['inserer', 'modifier', 'instituer', 'supprimer', 'dupliquer'] as $verbe) {
			verifier(
				"$nom_court : pas de fonction {$type}_{$verbe}() en collision avec l’API générique",
				strpos($code, "function {$type}_{$verbe}(") === false
			);
		}
	}
}

echo "\n== Recalcul de la liste des plugins ==\n";

foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	$code = '';
	foreach (fichiers($plugin, ['php']) as $f) {
		$code .= file_get_contents($f);
	}
	if (strpos($code, 'ecrire_plugin_actifs(') === false) {
		continue;
	}
	// ecrire_plugin_actifs() en mode « raz » écrit la liste telle qu'on la lui
	// donne : appelée avec autre chose que la liste complète des chemins, elle
	// désactive les plugins absents de l'argument — y compris l'appelant.
	verifier(
		"$nom_court : ecrire_plugin_actifs() n’est jamais appelée en mode « raz »",
		!preg_match("/ecrire_plugin_actifs\\([^;]*'raz'/", $code)
	);
	// En mode « ajoute », la liste fournie s'ajoute aux plugins déjà actifs :
	// elle ne sert qu'à déclarer un dossier que SPIP ne retrouverait pas seul,
	// celui d'un plugin qui a changé de nom en changeant de version.
	verifier(
		"$nom_court : recalcul par « ajoute », sur liste vide ou sur les dossiers déplacés",
		(bool) preg_match("/ecrire_plugin_actifs\\(\\s*(\\[\\s*\\]|\\\$dossiers)\\s*,[^;]*'ajoute'/", $code)
	);
	if (strpos($code, 'function dashagent_apres_maj(') !== false) {
		verifier(
			"$nom_court : dashagent_apres_maj() ne déclare aucun dossier par défaut",
			(bool) preg_match('/function dashagent_apres_maj\\(\\s*\\$dossiers\\s*=\\s*\\[\\s*\\]\\s*\\)/', $code)
		);
	}
}

echo "\n== Champs éditables ==\n";

foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	$prefixe   = (string) simplexml_load_file($plugin . '/paquet.xml')['prefix'];
	$fichier   = $plugin . "/base/{$prefixe}_tables.php";
	if (!is_file($fichier)) {
		continue;
	}
	// objet_modifier() route tout « statut » posté vers objet_instituer() : le
	// déclarer éditable revient à écrire le champ par deux mécanismes à la fois.
	if (preg_match("/'champs_editables'\s*=>\s*\[([^\]]*)\]/", file_get_contents($fichier), $bloc)) {
		verifier(
			"$nom_court : « statut » n’est pas déclaré champ éditable",
			strpos($bloc[1], "'statut'") === false
		);
	}
}

echo "\n== Noms de colonnes ==\n";

foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	$prefixe   = (string) simplexml_load_file($plugin . '/paquet.xml')['prefix'];
	$fichier   = $plugin . "/base/{$prefixe}_tables.php";
	if (!is_file($fichier)) {
		continue;
	}

	// La couche SQL de SPIP réécrit tout identifiant préfixé « spip_ » en nom de
	// table : une colonne ainsi nommée casse le CREATE TABLE, et la table n'est
	// jamais créée. Le symptôme est une erreur de syntaxe SQL sans rapport
	// apparent avec la colonne fautive.
	preg_match_all("/^\t+'(spip_[a-z0-9_]+)'\s*=>\s*[\"']/m", file_get_contents($fichier), $trouves);
	$suspectes = [];
	foreach ($trouves[1] as $nom) {
		// Les clefs de premier niveau sont des noms de tables, elles sont légitimes.
		if (strpos(file_get_contents($fichier), "\$tables['$nom']") === false) {
			$suspectes[] = $nom;
		}
	}
	verifier(
		"$nom_court : aucune colonne préfixée « spip_ »",
		!$suspectes
	);
	foreach ($suspectes as $nom) {
		echo "         colonne fautive : $nom\n";
	}
}

/**
 * Première occurrence d'un motif, pour que l'échec dise où regarder.
 *
 * @param string $motif
 * @param string $sujet
 * @return string
 */
function premiere_occurrence($motif, $sujet) {
	return preg_match($motif, $sujet, $m) ? trim($m[0]) : '';
}

echo "\n== Crochets des squelettes ==\n";

foreach ($plugins as $plugin) {
	foreach (fichiers($plugin, ['html']) as $squelette) {
		$source  = file_get_contents($squelette);
		$affiche = basename($plugin) . '/' . str_replace($plugin . '/', '', $squelette);

		// Un `[(…)]` dans l'argument d'un filtre déséquilibre le comptage des
		// crochets : le compilateur perd le fil et le reste du fichier est
		// affiché littéralement, `[(oui|=={oui}|oui)` compris.
		verifier(
			"$affiche : pas de [(…)] imbriqué dans un argument de filtre",
			!preg_match('/\\|[a-z_]+\\{[^}]*\\[\\(/i', $source)
		);

		// Une chaîne de langue dans un argument entre quotes n'est pas
		// interprétée : elle s'affiche telle quelle, `<:module:cle:>` compris.
		// La quote et la chaîne doivent être dans les mêmes accolades : sans
		// cette précision, un argument vide (`|parametre_url{distribue,''}`)
		// s'appariait avec le premier `<:` venu, des lignes plus bas.
		verifier(
			"$affiche : pas de <:…:> dans un argument entre quotes",
			!preg_match("/\\|[a-z_?]+\\{[^}']*'[^'}]*<:/i", $source)
		);

		// Une balise à accolades placée dans un argument *composite* — collée à
		// d'autres balises ou à du texte par des « / », comme dans
		// `operation/#ID_TRUC/#GET{cible}` — désorganise l'analyse des
		// arguments : les balises voisines arrivent littéralement dans la
		// sortie. Dans une URL d'action, cela donne un identifiant qui vaut
		// « #ID_… » et un « Site inconnu » muet, sans la moindre erreur.
		// Une balise qui constitue à elle seule un argument, elle, va bien :
		// `#AUTORISER{voir, truc, #ENV{id_truc}}` est l'idiome courant.
		$compose = '/#[A-Z_]+\\{[^{}]*[^,{\\s]\\/#[A-Z_]+\\{/';
		verifier(
			"$affiche : pas de balise à accolades dans un argument composite",
			!preg_match($compose, $source),
			premiere_occurrence($compose, $source)
		);

		// Le même défaut sous une autre forme : une balise à accolades
		// *imbriquée* dans l'argument d'un filtre à accolades. La chaîne de
		// langue à décompte s'écrit `#VAL{clef}|_T{#ARRAY{nb,#BALISE}}`, et il
		// y faut une balise simple : `#ARRAY{nb,#GET{x}}` ajoute un niveau
		// d'accolades que l'analyse ne suit plus. Le message ne désigne pas le
		// coupable — « Argument manquant dans la balise SET » — et c'est la
		// page entière qui tombe.
		$imbrique = '/#ARRAY\\{[^{}]*#(GET|ENV|VAL)\\{/';
		verifier(
			"$affiche : pas de balise à accolades dans un #ARRAY",
			!preg_match($imbrique, $source),
			premiere_occurrence($imbrique, $source)
		);

		// SPIP compile les balises jusque dans les commentaires : une syntaxe
		// de balise écrite là pour l'explication est évaluée pour de bon, et
		// une balise invalide emporte silencieusement le bloc entier. Les
		// commentaires JavaScript comptent aussi — un `#PAGINATION` écrit dans
		// un `/* … */` a produit une erreur de compilation que rien, sur la
		// page, ne rattachait au commentaire qui l'avait causée.
		$balise = '/#[A-Z_]{3,}|#(GET|SET|ENV|VAL)\\{/';
		$commentaires = [];
		preg_match_all('/<!--.*?-->|\\/\\*.*?\\*\\//s', $source, $commentaires);
		foreach ($commentaires[0] as $commentaire) {
			verifier(
				"$affiche : pas de syntaxe de balise dans un commentaire",
				!preg_match($balise, $commentaire),
				premiere_occurrence($balise, $commentaire)
			);
		}

		// Un piège de plus, qui n'a **pas** sa place ici : le contenu d'un bloc
		// optionnel ne supporte aucun crochet littéral — un crochet ouvrant
		// casse le bloc, un crochet fermant le termine trop tôt. La règle est
		// sûre, sa vérification statique ne l'est pas : un bloc optionnel
		// s'écrit aussi `[texte(#BALISE)texte]`, si bien qu'un crochet suivi
		// d'autre chose qu'une parenthèse peut parfaitement en ouvrir un.
		// Cinq squelettes sains du dépôt se faisaient ainsi accuser.
		//
		// C'est le parcours d'intégration qui le voit, et sans ambiguïté :
		// une syntaxe de squelette qui atteint le navigateur n'a aucune
		// raison d'être là. Le contrôle est posé dans `ouvrir()`, donc sur
		// toutes les pages visitées, et non sur celle où le défaut est né.

		// Une valeur d'attribut qui commence par « (# » : les parenthèses y sont
		// du texte, et sortent telles quelles. C'est ce qui donnait
		// href="(https://exemple.org/)", que le navigateur relit comme une
		// adresse relative — le lien menait sur l'espace privé du parc.
		//
		// La cause est en amont : deux balises dans un même bloc optionnel. La
		// dernière en est le sujet, la première n'est plus interprétée. Chaque
		// balise a besoin de ses propres crochets, ou d'un #SET calculé à part.
		$attribut = '/(?:href|src|action|data-[a-z-]+)\s*=\s*"\(#/';
		verifier(
			"$affiche : aucune valeur d’attribut ne s’ouvre sur une parenthèse littérale",
			!preg_match($attribut, $source),
			premiere_occurrence($attribut, $source)
		);
	}
}


echo "\n== Filtres appelés par les squelettes ==\n";

foreach ($plugins as $plugin) {
	$prefixe = (string) simplexml_load_file($plugin . '/paquet.xml')['prefix'];
	$famille = $familles[$prefixe] ?? $prefixe;
	$fonctions = $plugin . '/' . $prefixe . '_fonctions.php';
	$disponibles = '';
	foreach (array_merge(glob($plugin . '/*_fonctions.php'), glob($plugin . '/inc/*.php')) as $fichier) {
		$disponibles .= file_get_contents($fichier);
	}
	foreach (fichiers($plugin, ['html']) as $squelette) {
		$affiche = str_replace($plugin . '/', '', $squelette);
		preg_match_all('/\|(' . $famille . '_[a-z0-9_]+)/', file_get_contents($squelette), $trouves);
		foreach (array_unique($trouves[1]) as $filtre) {
			verifier("$affiche : filtre |$filtre défini", strpos($disponibles, "function $filtre(") !== false);
		}
	}
	if (glob($plugin . '/*_fonctions.php')) {
		verifier(basename($plugin) . " : {$prefixe}_fonctions.php chargé automatiquement", is_file($fonctions));
	}
}

echo "\n== Surface d’API SPIP utilisée ==\n";

/**
 * Fonctions du core SPIP que ce projet s’autorise à appeler.
 *
 * Toute fonction absente de cette liste fait échouer le test : c’est le seul
 * garde-fou contre l’appel d’une API supposée exister. Ajouter une entrée doit
 * être un geste délibéré, fait après avoir vérifié la fonction.
 */
$api_spip = [
	// Noyau, disponible sans inclusion
	'include_spip', 'charger_fonction', '_T', '_request', 'spip_log', 'ecrire_meta',
	'effacer_meta', 'parametre_url', 'redirige_par_entete', 'url_de_base', 'generer_url_ecrire',
	// inc/
	'autoriser', 'autoriser_exception', 'autoriser_type', 'lire_config', 'ecrire_config', 'recuperer_url', 'purger_repertoire',
	// find_in_path() sert à savoir si un plugin est là avant de l'appeler :
	// c'est ainsi que l'agent reconnaît un site pourvu de SVP.
	'find_in_path',
	'lire_metas', 'plugin_installes_meta', 'affdate_heure', 'affdate_jourcourt',
	'sous_repertoire', 'spip_unlink', 'spip_version_compare', 'session_get',
	'auth_synchroniser_distant',
	'liste_plugin_actifs', 'ecrire_plugin_actifs',
	// formulaires CVT sur objet
	'formulaires_editer_objet_charger', 'formulaires_editer_objet_verifier',
	'formulaires_editer_objet_traiter',
	// action/editer_objet — objet_modifier_champs() n’en fait pas partie :
	// elle n’est pas exposée, et l’avoir appelée provoquait une erreur fatale.
	'objet_inserer', 'objet_modifier', 'objet_instituer',
	// base/ — creer_base() et maj_base() mènent la migration du schéma du core
	// sur un site géré, ce que la page de mise à niveau de SPIP fait à la main.
	'maj_plugin', 'maj_tables', 'creer_base', 'maj_base',
	// abstract_sql
	'sql_allfetsel', 'sql_alltable', 'sql_countsel', 'sql_create', 'sql_delete',
	'sql_drop_table', 'sql_error', 'sql_fetch', 'sql_fetsel', 'sql_free', 'sql_insertq',
	'sql_query', 'sql_quote', 'sql_select', 'sql_showtable', 'sql_updateq', 'sql_version',
	'sql_in', 'sql_getfetsel',
	// SVP, quand le site géré en dispose : c'est lui qui sait mettre à jour un
	// plugin proprement, dépendances comprises. Les classes Decideur et
	// Actionneur ne passent pas par ici — seules les fonctions sont analysées.
	'svp_actualiser_paquets_locaux', 'svp_actualiser_maj_version', 'svp_actualiser_depot',
	// Elle dit quelles adresses SVP dérive de celle du dépôt — et donc où sont
	// les copies locales qu'un forçage doit effacer. Appel gardé par
	// function_exists() : les SVP anciens ne la connaissent pas.
	'svp_depoter_distant_variantes_url',
	// inc/distant : le nom de la copie locale d'un fichier distant.
	'fichier_copie_locale',
	// SPIP WAF, quand le site géré l'a installé. Son appel est gardé par un
	// function_exists() : l'agent lit ses tables, et n'emprunte à son code que
	// le vidage de la file d'événements, qu'on ne sait pas refaire soi-même.
	'waf_flush_events',
	// Les alertes : une adresse absolue pour le lien du courriel, le contrôle
	// des adresses de destinataires, et l'incrément SQL du compteur d'échecs
	// (sql_updateq() citerait la valeur, et « echecs + 1 » deviendrait une
	// chaîne).
	'url_absolue', 'email_valide', 'sql_update',
	// La file de travaux, pour l'entrée en ligne de commande d'outils/cron.php.
	// Les deux vivent dans ecrire/inc/utils.php, donc chargées par l'amorçage
	// lui-même : aucun include_spip() ne les apporte, et il ne faut pas en
	// chercher un.
	'cron', 'queue_sleep_time_to_next_job',
];

$definies = [];
$appels   = [];
foreach ($plugins as $plugin) {
	foreach (fichiers($plugin, ['php']) as $fichier) {
		$source = file_get_contents($fichier);
		$affiche = basename($plugin) . '/' . str_replace($plugin . '/', '', $fichier);

		// Analyse sur les jetons PHP : les commentaires et les chaînes ne sont
		// pas du code, et un extracteur textuel y ramasse n'importe quoi.
		$jetons = token_get_all($source);
		$nb = count($jetons);
		for ($i = 0; $i < $nb; $i++) {
			$jeton = $jetons[$i];
			if (!is_array($jeton) || $jeton[0] !== T_STRING) {
				continue;
			}

			// Ce qui précède : une déclaration, une méthode, une instanciation ?
			$avant = null;
			for ($j = $i - 1; $j >= 0; $j--) {
				if (is_array($jetons[$j]) && in_array($jetons[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
					continue;
				}
				$avant = $jetons[$j];
				break;
			}
			if (is_array($avant) && in_array($avant[0], [T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
				if ($avant[0] === T_FUNCTION) {
					$definies[strtolower($jeton[1])] = true;
				}
				continue;
			}

			// Ce qui suit : une parenthèse ouvrante, sinon c'est une constante.
			$apres = null;
			for ($j = $i + 1; $j < $nb; $j++) {
				if (is_array($jetons[$j]) && $jetons[$j][0] === T_WHITESPACE) {
					continue;
				}
				$apres = $jetons[$j];
				break;
			}
			if ($apres !== '(') {
				continue;
			}

			$appels[strtolower($jeton[1])] = $affiche;
		}
	}
}

$natives   = array_flip(get_defined_functions()['internal']);
$structures = array_flip(['if', 'for', 'foreach', 'while', 'switch', 'catch', 'return', 'echo',
	'array', 'isset', 'unset', 'empty', 'list', 'print', 'exit', 'die', 'include', 'require',
	'include_once', 'require_once', 'elseif', 'fn', 'match', 'and', 'or', 'xor', 'clone', 'yield', 'use']);
$autorisees = array_flip(array_map('strtolower', $api_spip));

$inconnues = [];
foreach ($appels as $nom => $ou) {
	if (isset($definies[$nom]) || isset($natives[$nom]) || isset($structures[$nom]) || isset($autorisees[$nom])) {
		continue;
	}
	$inconnues[$nom] = $ou;
}

verifier(count($appels) . ' appels de fonction analysés, aucun hors contrat', !$inconnues);
foreach ($inconnues as $nom => $ou) {
	echo "         fonction non déclarée au contrat : $nom() dans $ou\n";
}

// Le diagnostic affiché à l’utilisateur doit couvrir la même surface.
$fonctions = file_get_contents(chemin_plugin('tourdecontrole') . '/tourdecontrole_fonctions.php');
if (preg_match('/function dashboard_api_requise\(.*?\n}/s', $fonctions, $bloc)) {
	preg_match_all("/'([a-z_][a-z0-9_]*)'/", $bloc[0], $t);
	$declarees = array_diff($t[1], ['inc', 'action', 'base']);
	$hors = [];
	foreach ($declarees as $nom) {
		if (strpos($nom, '/') === false && !isset($autorisees[$nom]) && !isset($natives[$nom])) {
			$hors[] = $nom;
		}
	}
	verifier('le diagnostic d’API n’annonce que des fonctions du contrat', !$hors);
	foreach ($hors as $nom) {
		echo "         annoncée mais hors contrat : $nom\n";
	}
}

echo "\n== Fichiers d’inclusion des API SPIP ==\n";

/**
 * Fichier qui définit réellement chaque fonction, relevé dans les sources de
 * SPIP 4.4.23. Une fonction appelée sans son include_spip() est fatale à
 * l’exécution — c’est ainsi que purger_repertoire(), cherchée à tort dans
 * inc/flock, restait indéfinie.
 */
$fournisseur = [
	'autoriser'            => 'inc/autoriser',
	'ecrire_config'        => 'inc/config',
	'lire_config'          => 'inc/config',
	'ecrire_meta'          => 'inc/meta',
	'lire_metas'           => 'inc/meta',
	'affdate_heure'        => 'inc/filtres_dates',
	'affdate_jourcourt'    => 'inc/filtres_dates',
	'plugin_installes_meta' => 'inc/plugin',
	'effacer_meta'         => 'inc/meta',
	'purger_repertoire'    => 'inc/invalideur',
	'sous_repertoire'      => 'inc/flock',
	'recuperer_url'        => 'inc/distant',
	'session_get'          => 'inc/session',
	'url_absolue'          => 'inc/filtres',
	'email_valide'         => 'inc/filtres',
	'redirige_par_entete'  => 'inc/headers',
	'spip_version_compare' => 'inc/plugin',
	'liste_plugin_actifs'  => 'plugins/installer',
	'ecrire_plugin_actifs' => 'inc/plugin',
	'objet_inserer'        => 'action/editer_objet',
	'objet_modifier'       => 'action/editer_objet',
	'objet_instituer'      => 'action/editer_objet',
	'maj_tables'           => 'base/create',
	'maj_plugin'           => 'base/upgrade',
	'svp_actualiser_paquets_locaux' => 'inc/svp_depoter_local',
	'svp_actualiser_maj_version'    => 'inc/svp_depoter_local',
	'svp_actualiser_depot'          => 'inc/svp_depoter_distant',
	'svp_depoter_distant_variantes_url' => 'inc/svp_depoter_distant',
	'fichier_copie_locale'          => 'inc/distant',
	'formulaires_editer_objet_charger'  => 'inc/editer',
	'formulaires_editer_objet_verifier' => 'inc/editer',
	'formulaires_editer_objet_traiter'  => 'inc/editer',
];

// Chargés par le noyau avant tout code de plugin.
$toujours_charges = ['inc/autoriser', 'inc/config', 'inc/plugin', 'inc/session'];

$defauts = [];
foreach ($plugins as $plugin) {
	foreach (fichiers($plugin, ['php']) as $fichier) {
		$source  = file_get_contents($fichier);
		$affiche = basename($plugin) . '/' . str_replace($plugin . '/', '', $fichier);
		preg_match_all("/include_spip\(\s*'([^']+)'/", $source, $inc);
		$inclus = array_flip($inc[1]);

		$jetons = token_get_all($source);
		$nb = count($jetons);
		for ($i = 0; $i < $nb; $i++) {
			if (!is_array($jetons[$i]) || $jetons[$i][0] !== T_STRING) {
				continue;
			}
			$nom = strtolower($jetons[$i][1]);
			if (!isset($fournisseur[$nom])) {
				continue;
			}
			for ($j = $i + 1; $j < $nb && is_array($jetons[$j]) && $jetons[$j][0] === T_WHITESPACE; $j++);
			if (($jetons[$j] ?? null) !== '(') {
				continue;
			}
			for ($k = $i - 1; $k >= 0 && is_array($jetons[$k])
				&& in_array($jetons[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true); $k--);
			$avant = $jetons[$k] ?? null;
			if (is_array($avant) && in_array($avant[0], [T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
				continue;
			}

			$requis = $fournisseur[$nom];
			if (in_array($requis, $toujours_charges, true) || isset($inclus[$requis])) {
				continue;
			}
			$defauts[$affiche . ' : ' . $nom . '() sans include_spip(\'' . $requis . '\')'] = true;
		}
	}
}

verifier('chaque API SPIP est appelée avec son include_spip', !$defauts);
foreach (array_keys($defauts) as $defaut) {
	echo "         $defaut\n";
}

echo "\n== Les filtres appelés sans argument ==\n";

/*
 * `#VAL|mon_filtre` ne transmet pas « rien » : SPIP compile cet appel en
 * `mon_filtre('')`. Une valeur par défaut déclarée dans la signature ne joue
 * donc pas — l'argument est bel et bien passé —, et la chaîne vide atterrit
 * dans le premier paramètre.
 *
 * C'est ce qui est arrivé à `dashboard_waf_serie_parc($jours = 90)` : le `''`
 * valait zéro, la fenêtre se réduisait au jour même, et la tendance du parc
 * traçait deux courbes vides. L'encadré était là, son JSON aussi, et seules
 * les courbes manquaient — rien, sur la page, ne le disait.
 *
 * D'où la convention, que le reste du code suivait déjà : un filtre appelé
 * `#VAL|nom` ne prend pas de paramètre, ou en prend un nommé `$rien`. Ce nom
 * est le seul moyen de dire « je sais que SPIP me passe une chaîne vide, et je
 * n'en fais rien ».
 */
$sans_argument = [];
foreach ($plugins as $plugin) {
	foreach (fichiers($plugin, ['html']) as $fichier) {
		preg_match_all('/#VAL\|([a-z0-9_]+)/', file_get_contents($fichier), $t);
		$sans_argument = array_merge($sans_argument, $t[1]);
	}
}
$sans_argument = array_unique($sans_argument);

$fautifs = [];
foreach ($plugins as $plugin) {
	foreach (fichiers($plugin, ['php']) as $fichier) {
		$source = file_get_contents($fichier);
		foreach ($sans_argument as $nom) {
			if (!preg_match('/function\s+' . preg_quote($nom, '/') . '\s*\(([^)]*)\)/', $source, $signature)) {
				continue;
			}
			$premier = trim(explode(',', $signature[1])[0]);
			if ($premier === '' || strncmp($premier, '$rien', 5) === 0) {
				continue;
			}
			$fautifs[$nom . '() : premier paramètre ' . $premier . ', qui recevra la chaîne vide'] = true;
		}
	}
}

verifier(
	count($sans_argument) . ' filtres appelés #VAL|… ne reçoivent la chaîne vide nulle part de sensible',
	!$fautifs
);
foreach (array_keys($fautifs) as $fautif) {
	echo "         $fautif\n";
}


echo "\n== Opérations de parc : boutons, files et action ==\n";

/*
 * Trois écritures doivent s'accorder, et rien ne les relie à l'exécution :
 *
 * - le bouton nomme une opération (`data-parc-action="sync"`) ;
 * - une file d'adresses signées porte le même nom (`data-parc-file="sync"`) ;
 * - l'action sait la traiter (`case 'sync':`).
 *
 * Qu'une seule manque et le bouton ne fait rien — sans erreur, sans message.
 * Le pilote cherche une file qu'il ne trouve pas et s'arrête sur « Rien à
 * faire », ou bien l'action répond « opération inconnue » à chaque site.
 */
$parc_squelette = chemin_plugin('tourdecontrole') . '/prive/squelettes/contenu/dashboard.html';
$parc_action    = chemin_plugin('tourdecontrole') . '/action/dashboard_parc.php';
$html = file_get_contents($parc_squelette);
$php  = file_get_contents($parc_action);

preg_match_all('/data-parc-action="([a-z_]+)"/', $html, $t);
$boutons = array_unique($t[1]);
preg_match_all('/data-parc-file="([a-z_]+)"/', $html, $t);
$files = array_unique($t[1]);
preg_match_all("/case '([a-z_]+)':/", $php, $t);
$connues = array_unique($t[1]);

verifier('des opérations de parc sont proposées', count($boutons) >= 2, implode(', ', $boutons));

$sans_file = array_diff($boutons, $files);
verifier('chaque bouton de parc a sa file d’adresses signées', !$sans_file, implode(', ', $sans_file));

$sans_action = array_diff($boutons, $connues);
verifier('chaque bouton de parc est traité par l’action', !$sans_action, implode(', ', $sans_action));

$file_orpheline = array_diff($files, $connues);
verifier('aucune file ne vise une opération inconnue de l’action', !$file_orpheline, implode(', ', $file_orpheline));

/*
 * Et la règle qui tient tout : une adresse d'action se signe côté serveur. Le
 * pilote ne doit jamais en fabriquer une — sans quoi cocher une case
 * reviendrait à s'accorder un droit.
 */
$pilote = file_get_contents(chemin_plugin('tourdecontrole') . '/javascript/dashboard_parc.js');
verifier('le pilote ne fabrique aucune adresse d’action',
	!preg_match('{[\x27"`][^\x27"`]*action=[a-z_]+}i', $pilote)
	&& strpos($pilote, 'generer_action') === false);

/*
 * Les cases à cocher ne portent qu'un identifiant. Leur donner l'adresse
 * directement marcherait — mais alors la page en contiendrait une par site et
 * par opération, et il deviendrait tentant d'en composer une.
 */
verifier('une case à cocher ne porte qu’un identifiant de site',
	preg_match('/data-parc-site="#ID_DASHBOARD_SITE"/', $html)
	&& !preg_match('/data-parc-site[^>]*data-url/', $html));


echo "\n== Sauvegarde découpée : les deux bouts du contrat ==\n";

/*
 * Un export découpé tient à quatre mots, et rien ne les relie à l'exécution :
 *
 * - la tour envoie `decoupee`, sans quoi l'agent exporte d'un seul tenant —
 *   exactement ce qu'on cherchait à éviter, et sans aucune erreur ;
 * - elle envoie `reprendre` pour la suite, sans quoi chaque tranche repart de
 *   zéro et l'export ne finit jamais ;
 * - l'agent répond `termine`, sans quoi la tour croit l'export achevé à la
 *   première tranche et publie une sauvegarde amputée ;
 * - il répond `reprendre`, sans quoi la tour ne sait pas quoi redemander.
 *
 * Renommer l'un des quatre d'un seul côté ne lève rien : l'export redevient
 * silencieusement celui d'avant, ou s'arrête sur une archive partielle qu'un
 * contrôle d'empreinte déclarera parfaitement valide.
 */
$tour_ops = file_get_contents(chemin_plugin('tourdecontrole') . '/inc/dashboard_operations.php');
$agent_sv = file_get_contents(chemin_plugin('tourdecontrole_agent') . '/inc/dashagent_sauvegarde.php');

$envoi = fonction_php($tour_ops, 'dashboard_sauvegarde_exporter');
$lecture = fonction_php($tour_ops, 'dashboard_sauvegarde_suite');
/* Les trois fonctions par lesquelles les arguments de la demande entrent
   dans l'agent : l'aiguillage, la tranche, et le calcul des exclusions. */
$recu = fonction_php($agent_sv, 'dashagent_sauvegarde_creer')
	. fonction_php($agent_sv, 'dashagent_sauvegarde_tranche')
	. fonction_php($agent_sv, 'dashagent_sauvegarde_exclusions');

verifier('les trois fonctions du découpage sont retrouvées',
	$envoi !== '' && $lecture !== '' && $recu !== '');

/* Ce que la tour met dans `$args`, et rien d'autre : la fonction rend aussi des
   tableaux, dont les clefs ne sont pas des arguments d'opération. */
preg_match('/\$args\s*=\s*\[(.*?)\];/s', $envoi, $litteral);
preg_match_all("/'([a-z0-9_]+)'\s*=>/", (string) ($litteral[1] ?? ''), $t);
preg_match_all("/\\\$args\[\s*'([a-z0-9_]+)'\s*\]\s*=[^=]/", $envoi, $u);
$sortants = array_values(array_unique(array_merge($t[1], $u[1])));

preg_match_all("/\\\$args\[\s*'([a-z0-9_]+)'\s*\]/", $recu, $t);
$entrants = array_unique($t[1]);

$ignores = array_diff($sortants, $entrants);
verifier('chaque argument envoyé par la tour est lu par l’agent',
	!$ignores, 'jamais lus : ' . implode(', ', $ignores));

foreach (['decoupee', 'reprendre', 'sans_statistiques'] as $clef) {
	verifier('l’argument « ' . $clef . ' » va d’un bout à l’autre',
		in_array($clef, $sortants, true) && in_array($clef, $entrants, true));
}

foreach (['termine', 'reprendre'] as $clef) {
	verifier('la réponse « ' . $clef . ' » est écrite par l’agent et lue par la tour',
		strpos($recu, "'" . $clef . "'") !== false
		&& preg_match("/\\\$data\[\s*'" . $clef . "'\s*\]/", $lecture));
}

/* Et les deux issues, pas seulement l'une : un agent qui ne sait plus dire
   « pas fini » publie sa première tranche comme une sauvegarde entière. */
foreach (['false', 'true'] as $issue) {
	verifier('l’agent sait répondre « termine » à ' . $issue,
		(bool) preg_match("/'termine'\\s*=>\\s*" . $issue . "/", $recu));
}

/*
 * Et le garde-fou qui tient la compatibilité : un agent d'avant le découpage ne
 * dit rien de `termine`. Prendre son silence pour un « pas fini » ferait boucler
 * la tour sur un export déjà publié, jusqu'au plafond de tranches.
 */
verifier('l’absence de « termine » est traitée explicitement',
	strpos($lecture, "array_key_exists('termine'") !== false);


echo "\n== La page de configuration porte le nom du préfixe ==\n";

/*
 * SPIP affiche le bouton « Configurer » de sa page *Gestion des plugins* à une
 * condition, et une seule : que l'exec `configurer_<prefixe>` existe.
 * `plugin_bouton_config()` compose ce nom depuis `strtolower($infos['prefix'])`,
 * et `prive/squelettes/inclure/cfg.html` ne rend le lien que si
 * `#SCRIPT|tester_url_ecrire` répond.
 *
 * C'est une exception à la règle qui veut que nos noms restent en `dashboard_*`
 * et `dashagent_*` : ce nom-là, SPIP le dérive, il ne nous appartient pas. Nos
 * pages s'appelaient `configurer_dashboard` et `configurer_dashagent`, et le
 * bouton n'a jamais pu apparaître — sans que rien ne le signale.
 */
foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	$xml       = simplexml_load_file($plugin . '/paquet.xml');
	$prefixe   = strtolower((string) $xml['prefix']);
	$page      = $plugin . '/prive/squelettes/contenu/configurer_' . $prefixe . '.html';

	verifier("$nom_court : la page configurer_$prefixe existe", is_file($page),
		'sans elle, SPIP ne propose aucun bouton « Configurer »');

	$menus = [];
	foreach ($xml->menu as $menu) {
		$menus[] = (string) $menu['nom'];
	}
	verifier("$nom_court : le menu de configuration désigne cette page",
		in_array('configurer_' . $prefixe, $menus, true), implode(', ', $menus));

	if (is_file($page)) {
		verifier("$nom_court : la page refuse l’accès non autorisé",
			strpos(file_get_contents($page), 'sinon_interdire_acces') !== false);
	}
}

echo "\n== Configurer le parc reste un droit de webmestre ==\n";

/*
 * Le tableau de bord détient les secrets de tout le parc, et l'agent ouvre son
 * site à distance : leurs pages de configuration ne sont pas des pages
 * d'administration ordinaires. Un administrateur non webmestre n'y entre pas.
 *
 * SPIP essaie plusieurs noms pour un même contrôle, et lequel dépend de sa
 * version : il suffit qu'une seule de ces fonctions soit plus permissive pour
 * que la porte s'ouvre. On les relit donc toutes.
 */
$verifiees_conf = 0;
foreach ($plugins as $plugin) {
	foreach (fichiers($plugin, ['php']) as $fichier) {
		if (strpos(basename($fichier), '_autorisations.php') === false) {
			continue;
		}
		$source = file_get_contents($fichier);
		preg_match_all('/function\s+(autoriser_\w*configurer\w*_dist)\s*\(/i', $source, $trouves);
		foreach ($trouves[1] as $fonction) {
			$corps = fonction_php($source, $fonction);
			$verifiees_conf++;
			verifier("$fonction exige le webmestre",
				strpos($corps, "webmestre") !== false || strpos($corps, 'dashboard_qui_peut_agir') !== false,
				trim(preg_replace('/\s+/', ' ', $corps)));
		}
	}
}
verifier('les autorisations de configuration ont bien été relues', $verifiees_conf >= 6,
	$verifiees_conf . ' fonction(s)');


echo "\n== Forcer une relecture de dépôt veut dire forcer ==\n";

/*
 * SVP ne télécharge pas le catalogue : il appelle `copie_locale($url, 'modif')`,
 * qui ne va le chercher que si le serveur le dit plus récent que la copie rangée
 * sous `IMG/distant/`. Cette copie voit sa date rafraîchie à chaque contrôle,
 * même sans rapatriement — un contrôle tombé pendant qu'un cache en frontal
 * servait l'ancien fichier la rend donc « plus récente » que le catalogue
 * publié, et le verrou se referme pour de bon.
 *
 * Le forçage n'a de sens que s'il efface cette copie. Qu'on retire cet effacement
 * et tout redevient vert : l'agent répond, la date avance, le catalogue reste
 * celui d'avant. D'où ce garde-fou, qui confronte les deux bouts.
 */
$agent_svp = file_get_contents(chemin_plugin('tourdecontrole_agent') . '/inc/dashagent_svp.php');
$tour_ops  = file_get_contents(chemin_plugin('tourdecontrole') . '/inc/dashboard_operations.php');

$forcage = fonction_php($agent_svp, 'dashagent_svp_depots_actualiser');
verifier('la relecture forcée efface la copie locale du catalogue',
	strpos($forcage, 'dashagent_svp_copies_effacer') !== false);
verifier('et vide l’empreinte, l’autre moitié du garde-fou',
	(bool) preg_match("/'sha_paquets'\s*=>\s*''/", $forcage));

$constat = fonction_php($agent_svp, 'dashagent_svp_relecture_constat');
foreach (['true', 'false'] as $issue) {
	verifier('l’agent sait rendre un constat « fiable » à ' . $issue,
		(bool) preg_match("/'fiable'\s*=>\s*" . $issue . "/", $constat));
}

$lecture = fonction_php($tour_ops, 'dashboard_depots_constat');
verifier('la tour lit ce constat', strpos($lecture, "'fiable'") !== false);

/*
 * Et la compatibilité, dans l'autre sens : un agent d'avant la 1.0.22 ne rend
 * aucun constat. Prendre son silence pour une panne ferait crier au loup sur
 * tout le parc au lendemain d'une mise à jour de la tour.
 */
verifier('l’absence de constat est traitée explicitement',
	strpos($lecture, "array_key_exists('fiable'") !== false);


echo "\n== La branche « sinon » d'une boucle ==\n";

/*
 * Ce qui suit `</BOUCLE_x>` jusqu'au `<//B_x>` n'est pas la fin du squelette :
 * c'est la branche que SPIP rend **quand la boucle n'a rien trouvé**. Les deux
 * balises de chargement de Chart.js y avaient atterri, en bas de fichier, à
 * l'endroit qui semblait naturel — et les graphiques ne s'affichaient que sur
 * un parc dont les tables sont absentes, c'est-à-dire jamais.
 *
 * Rien, sur la page, ne le dit : le bloc du graphique est bien là, son JSON
 * aussi, et seul le tracé manque. Un `<script src>` dans cette branche est
 * donc refusé — un chargement de bibliothèque n'a aucune raison d'être réservé
 * au cas où la boucle est vide.
 */
$egares = [];
$branches = 0;
foreach ($plugins as $plugin) {
	foreach (fichiers($plugin, ['html']) as $fichier) {
		$source = file_get_contents($fichier);
		if (!preg_match_all('{<//B_([a-z0-9_]+)>}i', $source, $fins, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
			continue;
		}
		$affiche = str_replace($plugin . '/', '', $fichier);
		foreach ($fins as $fin) {
			$nom = $fin[1][0];
			// Le début de la branche, c'est la dernière fermeture qui précède.
			$debut = -1;
			foreach (['</B_' . $nom . '>', '</BOUCLE_' . $nom . '>'] as $marque) {
				$ou = strrpos(substr($source, 0, $fin[0][1]), $marque);
				if ($ou !== false) {
					$debut = max($debut, $ou + strlen($marque));
				}
			}
			if ($debut < 0) {
				continue;
			}
			$branches++;
			$sinon = substr($source, $debut, $fin[0][1] - $debut);
			if (preg_match('{<script[^>]+src=}i', $sinon)) {
				$egares[$affiche . ' : <script src> dans la branche « sinon » de ' . $nom] = true;
			}
		}
	}
}

verifier("$branches branches « sinon » relues, aucune ne charge de script", !$egares);
foreach (array_keys($egares) as $egare) {
	echo "         $egare\n";
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
