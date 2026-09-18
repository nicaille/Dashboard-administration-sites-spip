# Conventions du dépôt

## Les dossiers de plugins portent leur version

`plugins/<prefixe>-<version>` : `plugins/tourdecontrole-1.0.22`,
`plugins/tourdecontrole_agent-1.0.16`.

**À chaque montée de version d'un plugin, renommer son dossier en conséquence**,
dans le même commit que le changement de `version=` dans son `paquet.xml`. Un
`git mv` conserve l'historique des fichiers.

La raison : déposer la nouvelle version à côté de l'ancienne, plutôt que
par-dessus, évite qu'un fichier supprimé entre deux versions survive à une mise
en ligne par FTP et continue d'être chargé. Revenir en arrière se réduit alors à
supprimer le nouveau dossier.

SPIP s'y retrouve seul : il indexe les plugins par préfixe et retient la version
la plus élevée quand deux dossiers déclarent le même (`ecrire/inc/plugin.php`,
`plugin_valide_resume()`).

À ne pas confondre avec le déclenchement des migrations : `maj_plugin()` compare
la version du `paquet.xml` à celle mémorisée en meta, jamais le nom du dossier.

**Sur les sites administrés, c'est SVP qui applique la règle** quand il est là :
`inc/dashagent_svp.php` lui délègue la mise à jour, et il range les plugins dans
`plugins/auto/<prefixe>/v<version>`. Un refus de sa part ne se contourne pas —
voir `dashboard_chantier_svp_repli()`.

À défaut de SVP, l'agent déploie l'archive lui-même sans écraser :
`dashagent_dossier_versionne()` et `dashagent_installer_a_cote()`, dans
`inc/dashagent_maj.php`. Comme SPIP tient sa liste de plugins actifs par
dossier, le nouveau lui est déclaré dans la foulée — sans quoi le plugin serait
désactivé par son propre changement de nom.

### Un piège de SPIP 4.4 : autoriser_exception() et les types en `_`

`autoriser_exception('ajouter', '_plugins', '*')` — la formule qu'emploient SVP
comme spip-cli — **n'a aucun effet**. L'exception est rangée sous le type réduit
(`plugins`), mais `autoriser()` la relit en repassant par `autoriser_type()`,
qui le transforme une seconde fois en `plugin` : la clé consultée n'est jamais
celle qui a été écrite. Il faut déclarer les deux orthographes
(`dashagent_svp_autoriser()`). Sans cela, `action/teleporter.php` refuse le
téléchargement avec un laconique « Chargement impossible de la source ».

### Ne pas figer la version dans les références

Tout ce qui doit atteindre un plugin le retrouve **par préfixe**, jamais par un
chemin complet — autrement chaque montée de version casserait une dizaine de
fichiers :

- `tests/bootstrap.php` et `tests/test_structure.php` : fonction
  `chemin_plugin('tourdecontrole')`, qui prend la version la plus élevée ;
- `tests/integration/executer.sh` : `plugins/tourdecontrole-*` au glob ;
- `README.md` et `docs/` : écrire `plugins/tourdecontrole-<version>`.

Le code des plugins, lui, ne doit jamais mentionner son propre nom de dossier.
SPIP fournit `_DIR_PLUGIN_<PREFIXE>` pour cela.

## Un silence n'est pas une réponse

`dashboard_silence()`, dans `inc/dashboard_client.php`, distingue les deux —
c'est elle qui dit ce qu'on a le droit de conclure d'un échec :

- une **réponse** de l'agent (« verrou SVP posé », « opération non autorisée »)
  se respecte, et l'opération s'arrête là ;
- un **silence** — `transport`, ou `reponse_illisible` — ne dit rien de l'issue.
  L'opération a très bien pu aboutir.

Deux causes de silence, toutes deux hors de portée de l'agent : le plugin qui se
remplace lui-même pendant qu'il répond, et le **cache ou répartiteur en frontal**
(Varnish, nginx, Cloudflare) dont la patience est plus courte que la nôtre — son
`first_byte_timeout` vaut soixante secondes par défaut, là où un export SQL en
prend davantage, et il rend un 503 en HTML pendant que PHP travaille encore.

Toute opération longue doit donc savoir **aller constater** plutôt que conclure :

- la mise à jour d'un plugin reste sur son étape et redemande des nouvelles
  (`dashboard_chantier_plugin_verifier()`) ;
- la sauvegarde interroge l'inventaire du site et adopte celle qu'elle y trouve
  (`dashboard_sauvegarde_rattraper()`, `dashboard_sauvegarde_retrouvee()`).

La règle d'adoption est stricte, parce qu'annoncer une sauvegarde qu'on n'a pas
serait pire que l'échec : inconnue de nous, non vide, et récente — avec une
tolérance d'horloge, la date venant du site géré et non de nous.

Une sauvegarde adoptée n'a pas d'empreinte : l'inventaire n'en publie pas. Le
rapatriement contrôle alors la taille annoncée, faute de SHA-256.

**Présente ne veut pas dire valide.** Empreinte et taille attestent le transfert,
jamais le contenu : un export interrompu sur le site géré voyage parfaitement.
Trois protections, à trois endroits :

- l'agent écrit sous `.partiel` et **renomme au succès** — le renommage étant
  atomique, le nom définitif ne désigne jamais un fichier incomplet ;
- chaque dump se termine par `_DASHAGENT_SAUVEGARDE_FIN`. Le pied de page du gzip
  prouve qu'aucun octet n'a été perdu ; il ne dit rien de ce que le script avait
  encore à écrire quand il a été tué entre deux tables ;
- `dashboard_sauvegarde_verifier()` relit l'archive au rapatriement : CRC32 et
  taille décompressée du pied de page, recalculés sur le flux — ce que fait
  `gzip -t`.

Ses deux verdicts ne se confondent pas : `ok` à faux **prouve** que le fichier
est abîmé, `complet` à faux dit seulement que la marque n'a pas été vue. Les
sauvegardes d'avant l'agent 1.0.16 n'en portent pas, et les refuser reviendrait
à jeter des sauvegardes valides.

## Le préfixe d'un plugin n'est pas le préfixe de ses fonctions

Les deux plugins ont changé de préfixe en 1.0.19 et 1.0.15 — `dashboard` était
déjà pris par un autre plugin SPIP, et SVP indexe par préfixe **tous dépôts
confondus** : il aurait fini par proposer l'un pour l'autre.

Ce que SPIP dérive du préfixe, et qu'il faut donc renommer avec lui :

- les fichiers `<prefixe>_fonctions.php`, `<prefixe>_options.php`,
  `<prefixe>_administrations.php`, que SPIP charge tout seul pour les plugins
  actifs ;
- les fonctions `<prefixe>_upgrade()` et `<prefixe>_vider_tables()` ;
- **les fonctions de pipeline** : `<prefixe>_autoriser()`,
  `<prefixe>_declarer_tables_*()`, `<prefixe>_taches_generales_cron()`. Le
  `inclure=` du `paquet.xml` dit dans quel fichier chercher, jamais comment la
  fonction s'appelle ;
- la meta de version de schéma `<prefixe>_base_version` — d'où
  `dashboard_reprendre_schema()`, sans quoi SPIP croit le plugin neuf et rejoue
  toutes ses migrations sur une base déjà à jour ;
- le module de langue du manifeste `lang/paquet-<prefixe>_XX.php`, et ses clefs
  `<prefixe>_nom`, `<prefixe>_slogan`, `<prefixe>_description`.

Ce que SPIP ne dérive de rien, et qui a donc gardé son nom : **les fonctions
internes et les filtres de squelette** (`dashboard_*`, `dashagent_*`), **les noms
de tables** (`spip_dashboard_sites`), **les chemins de configuration**
(`lire_config('dashboard/…')`) et **les modules de langue ordinaires**
(`<:dashboard:clef:>`, qui tiennent au nom du fichier). Les renommer aurait
touché deux cents fonctions et les tables — donc les données des sites déjà
installés — pour un gain nul.

`tests/test_structure.php` porte cette distinction dans son tableau
`$familles` : préfixe du plugin d'un côté, famille de fonctions de l'autre. Sans
lui, il cherchait `|tourdecontrole_…` dans les squelettes, n'en trouvait aucun,
et **passait au vert en ayant cessé de vérifier trente-six filtres**. Un test qui
perd son sujet ne le dit pas : quand un renommage fait tomber le nombre de
vérifications, c'est là qu'il faut regarder.

Un underscore dans un préfixe ne pose aucun problème — `porte_plume`, livré avec
SPIP, en porte un.

## Le dépôt SVP se fabrique, il ne s'écrit pas à la main

`outils/generer-depot.php` produit le catalogue et les archives que publie
`.github/workflows/depot.yml` sur GitHub Pages. Trois règles que SVP impose et
qu'aucun message d'erreur n'explique — `tests/test_depot.php` les vérifie :

- **Le catalogue n'a pas de racine unique** : `<depot>` et `<archives>` sont deux
  blocs frères. SVP le lit à l'expression régulière (`svp_phraser_depot()`),
  jamais avec un analyseur XML. Passer par `DOMDocument` produirait une racine
  englobante et un dépôt inajoutable.
- **`<type>http</type>` désigne le téléporteur**, et lui seul fait télécharger
  l'archive à `url_archives` + `/` + `<file>` — d'où une `url_archives` **sans**
  barre oblique finale.
- **Le zip a une racine unique, du nom du préfixe** : le téléporteur la retire au
  déballage et range le reste dans `plugins/auto/<prefixe>/v<version>`.

Et un piège qui ne se voit qu'à l'usage : avant de demander le catalogue, SVP
sonde `plugins.thin.spip-<branche>.xml` puis `plugins.thin.xml`
(`svp_depoter_distant_copie_xml_paquets()`). **Un hébergement qui répond 200 à un
fichier absent** — le serveur intégré de PHP sert `index.html` — fait lire cette
page en guise de catalogue, pour un laconique « le fichier XML n'est pas
conforme ». GitHub Pages répond bien 404 ; on publie tout de même
`plugins.thin.xml`.

La règle du dossier versionné se vérifie là où elle compte : le générateur
**refuse de publier** un dossier dont le nom ne s'accorde pas avec la version de
son `paquet.xml`.

La publication suit un geste, jamais un commit : un tag, ou le workflow lancé à
la main. Le nom du tag n'est qu'une étiquette — le dépôt publie tous les plugins
présents, chacun à la version de son dossier.

## L'habillage est celui du privé, pas le nôtre

Le thème de l'espace privé habille déjà tout ce dont ces squelettes ont besoin.
Redéfinir par-dessus produit surtout des accidents : un fond blanc posé sans
redéfinir la couleur du texte, que le thème met en blanc, donnait des libellés
invisibles sur les boutons de pagination.

- **Boutons** : `class="btn"`, `btn btn_secondaire`, `btn btn_danger`. Jamais de
  classe maison.
- **Actions** : `#BOUTON_ACTION{libelle,url,classes[,confirmation]}`, qui rend un
  formulaire POST. Une action qui change l'état d'un site ne se déclenche pas en
  suivant un lien. Les vrais liens de navigation restent des `<a class="btn …">`.
- **Pagination** : `{pagination N}` sur la boucle, puis
  `[<nav class="pagination" role="navigation">(#PAGINATION{page_precedent_suivant})</nav>]`
  — page précédente, les numéros de page, page suivante.
  L'argument de `#PAGINATION{X}` est **d'abord un type**, accessoirement un nom
  de modèle : `filtre_pagination_dist()` pose `type_pagination = X`, puis prend
  `modeles/pagination_X.html` s'il existe et le modèle par défaut sinon. Or
  `pagination.html` teste lui-même `type_pagination == page_precedent_suivant`
  pour ajouter précédent et suivant à ses numéros. SPIP 4.4 ne livre que trois
  modèles — `pagination`, `pagination_precedent_suivant`, `pagination_prive` —
  et `page_precedent_suivant` n'en est pas un : c'est voulu.
- **Listes paginées côté JavaScript** (le contenu des tables d'un site géré ne
  vient d'aucune boucle) : reprendre le balisage à l'identique —
  `nav.pagination` > `ul.pagination-items.pagination_page_precedent_suivant` >
  `li.pagination-item.prev|next[.disabled]`, `li.pagination-item[.on.active]`
  pour les numéros, `li.pagination-item.tbc.disabled` pour les points de
  suspension > `a.pagination-item-label` (ou un `span` quand l'item est figé).
  Sans total connu, s'en tenir à précédent/suivant : on ne peut pas numéroter
  ce qu'on n'a pas compté.
- **Champs de formulaire hors formulaire** : les envelopper dans
  `div.formulaire_spip > div.editer > label + champ`, sinon un `select` reste
  celui du navigateur.
- **Retour à l'encadré** : chaque bloc porte une ancre (`id="plugins"`), et
  l'URL de retour des actions la désigne — sinon la page recharge en haut et le
  compte rendu reste hors de vue.
- **Critère facultatif** : seul `{champ ?}` l'est, et il lit le contexte. Écrire
  `{champ = #GET{x} ?}` ne rend rien facultatif — le `?` est pris pour un
  caractère de la valeur, et la boucle cherche `champ = 'non?'`. Pour filtrer
  depuis une variable, dire ce qu'on **écarte** : `{champ != #GET{x}}`, où un
  `x` vide ne retient rien et laisse donc tout passer. Une liste n'est pas une
  échappatoire : `#SET{x,non,oui}` est coupé sur sa virgule et `x` vaut `non`.
- **Un libellé qui porte un décompte** se calcule avec `#SET` avant l'appel, et
  par `#VAL{clef}|_T{#ARRAY{nb,#BALISE}}` — une chaîne de langue à arguments
  écrite directement dans un `#SET` rend une chaîne vide.

## Ce qui vient d'un site géré est inerte, toujours

L'échappement de SPIP n'est pas une protection contre l'injection de balisage :
`interdire_scripts()` laisse passer `<svg onload>` et `<details ontoggle>`. Or un
site géré peut être compromis, et son inventaire s'affiche dans l'espace privé de
la tour de contrôle, qui détient les secrets de tout le parc.

- **à l'entrée** : tout ce qu'un agent répond passe par `dashboard_inerte()`
  avant d'atteindre la base — `dashboard_synchroniser()`,
  `dashboard_enregistrer_plugins()`, `dashboard_journaliser()`,
  `dashboard_chantier_ecrire()`. Un nouveau champ venu de l'agent se range
  derrière l'un de ces quatre points, pas à côté ;
- **à l'affichage** : un champ SQL rendu tel quel (`#VERSION_SPIP`, `#PREFIXE`…)
  porte `|entites_html`. `|textebrut` et `|couper{N}` suffisent aussi — ils
  retirent les balises ; `|typo` ne suffit qu'à moitié (il ôte les attributs,
  garde la balise).

Le `phpinfo()` d'un site est la seule exception, parce que c'est du HTML par
nature : il va dans une `<iframe sandbox>`, et nulle part ailleurs. Le reste de
l'onglet *Serveur* se construit par `textContent` — aucun `innerHTML` ne reçoit
autre chose que `''`.

`tests/test_protocole.php` vérifie le filtre, `tests/integration/scenario.mjs`
le rendu, charge utile à l'appui.

## Quatre pièges du compilateur, tous rencontrés

1. **Une balise à accolades dans un argument composite** (`op/#ID/#GET{x}`)
   désorganise l'analyse : les balises voisines arrivent littéralement dans la
   sortie. Calculer l'argument à part avec `#SET`.
2. **Un filtre à accolades dans l'argument d'un autre filtre**
   (`|lien_ou_expose{…,#GET{x}|=={non},…}`) est relu comme la suite de la chaîne
   de filtres : SPIP cherche alors un filtre nommé `non,btn btn_secondaire`.
   Même remède.
3. **SPIP compile les balises jusque dans les commentaires** — les commentaires
   JavaScript compris. Un `#PAGINATION` écrit dans un `/* … */` pour
   l'explication produit une erreur de compilation que rien, sur la page, ne
   rattache au commentaire fautif.
4. **Deux balises dans un même bloc optionnel** : la dernière en est le sujet,
   la première n'est plus interprétée et ses parenthèses ressortent telles
   quelles. `[<a href="(#URL_SITE)">(#URL_SITE|couper{48})</a>]` produisait
   `href="(https://exemple.org/)"` — une adresse relative, du point de vue du
   navigateur, qui ramenait sur l'espace privé du parc. Donner ses crochets à
   chaque balise, ou calculer à part avec `#SET`.

`tests/test_structure.php` vérifie les quatre — le dernier en refusant toute
valeur d'attribut qui s'ouvre sur une parenthèse littérale.

## Tests à passer avant tout commit

```bash
php tests/test_protocole.php    # protocole, masquage, chantiers
php tests/test_structure.php    # manifestes, tables, contrat d'API, squelettes
php tests/test_depot.php        # catalogue SVP et archives publiées
```

Le parcours sur SPIP réel (`tests/integration/executer.sh`) est à relancer dès
qu'on touche à une opération distante, à une table ou à un squelette de la fiche
d'un site. Voir `tests/integration/README.md`.

## Langue

Le code, les commentaires, les messages de commit et la documentation sont en
français. Les chaînes affichées passent par `lang/`, en français et en anglais.
