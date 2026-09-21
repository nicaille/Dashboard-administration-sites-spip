# Conventions du dépôt

## Les dossiers de plugins portent leur version

`plugins/<prefixe>-<version>` : `plugins/tourdecontrole-1.0.29`,
`plugins/tourdecontrole_agent-1.0.21`.

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

### Une version publiée est figée

Corollaire, et la faute est arrivée : l'authentification HTTP a été livrée dans
un dossier `1.0.27` **déjà publié au dépôt**, sans montée de version. Le dépôt
s'est mis à servir un 1.0.27 dont le contenu différait de celui que les sites
avaient installé — et SVP compare des numéros. Même numéro, aucune mise à jour
proposée : la fonction était inatteignable, et la migration de schéma qui
l'accompagnait ne pouvait pas se jouer.

Rien ne le signale. Le dépôt publie sans broncher, les tests passent, et le parc
affiche une version qui a l'air juste.

**Dès qu'un `outils/generer-depot.php` a tourné sur une version, cette version
est morte** : le moindre changement de code dans son dossier impose un
numéro de plus, fût-ce dans la même session et pour trois lignes. Dans le doute,
regarder les exécutions de `.github/workflows/depot.yml` : ce qui y est passé
est dehors.

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
Rencontré en vrai sur l'option CDN d'un hébergement OVH, où ce délai n'est
exposé dans **aucun réglage accessible** : un frontal qu'on administre se règle,
un CDN d'hébergeur se contourne — en pointant `url_agent`, champ distinct de
`url_site`, vers un nom que le CDN ne couvre pas.

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

### Mieux que constater : ne pas s'exposer au silence

Le rattrapage sait dire ce qui s'est passé ; il ne sait pas l'empêcher. Depuis
l'agent 1.0.21, la sauvegarde **se découpe** : l'agent exporte pendant
`_DASHAGENT_SAUVEGARDE_BUDGET` — vingt secondes, choisies sur la patience du
frontal et non sur ce que PHP tient — puis rend la main, et la tour rappelle la
même opération tant que `termine` est faux. Le contrat est celui des chantiers
et des actions de parc.

Ce qui tient le fil d'une tranche à l'autre est un état à côté du `.partiel` :
table en cours, rang atteint, et **taille du fichier au dernier point de
contrôle**. Cette dernière est tout : un processus tué en plein milieu d'une
tranche laisse des octets que l'état ne mentionne pas, et
`dashagent_sauvegarde_recaler()` les tronque avant de reprendre. Sans elle, la
reprise écrirait à la suite d'un membre gzip inachevé, ou rejouerait des lignes
déjà exportées — une archive plausible, que rien ensuite ne signalerait comme
fausse.

Le découpage renverse la règle du silence sur un point, et sur un seul : ici
**un silence se retente**, parce que la reprise est idempotente. Rien de tel
dans l'export d'un seul tenant, où retenter voudrait dire tout refaire.
`dashboard_sauvegarde_suite()` porte cette décision, et se vérifie donc sans
réseau.

Deux compatibilités, dans les deux sens, et c'est là qu'est le piège : un agent
d'avant la 1.0.21 ignore `decoupee` et **ne dit rien de `termine`**. Son silence
sur cette clef vaut « c'est fini » — le prendre pour un « pas fini » ferait
boucler la tour sur un export déjà publié. Réciproquement, une tour ancienne
n'envoie pas `decoupee`, et l'agent lui doit alors l'export d'un seul tenant.

**Présente ne veut pas dire valide.** Empreinte et taille attestent le transfert,
jamais le contenu : un export interrompu sur le site géré voyage parfaitement.
Trois protections, à trois endroits :

- l'agent écrit sous `.partiel` et **renomme au succès** — le renommage étant
  atomique, le nom définitif ne désigne jamais un fichier incomplet ;
- chaque dump se termine par `_DASHAGENT_SAUVEGARDE_FIN`. Le pied de page du gzip
  prouve qu'aucun octet n'a été perdu ; il ne dit rien de ce que le script avait
  encore à écrire quand il a été tué entre deux tables ;
- `dashboard_sauvegarde_verifier()` relit l'archive au rapatriement, membre par
  membre : chacun doit se terminer proprement — zlib vérifie lui-même son CRC32
  et sa taille décompressée —, et le fichier doit s'achever sur une frontière de
  membre. C'est ce que fait `gzip -t`.

**Un gzip n'a pas forcément un seul membre** (RFC 1952), et une sauvegarde
découpée en a un par tranche. Le contrôle d'avant ne lisait que les huit derniers
octets, c'est-à-dire le pied du *dernier* membre, et les comparait au flux tout
entier : il aurait déclaré tronquée toute archive découpée. Le parcours passe par
`inflate_add()`, dont `inflate_get_read_len()` donne la frontière exacte de
chaque membre — la seule façon de savoir où l'un finit sans deviner.

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

## Une lecture de meta figée sur un ancien préfixe ne casse rien de visible

`dashagent_version_plugin()` cherchait `$infos['DASHAGENT']` dans la liste des
plugins actifs, que SPIP range sous le **préfixe courant**. Après le renommage,
elle rendait sa valeur par défaut — « 0 » — et le parc a affiché « Version de
l'agent : 0 » pendant des semaines sans que rien n'échoue par ailleurs.

C'est le propre de cette faute : elle ne lève aucune erreur, elle rend un
défaut. Les renommages de préfixe doivent donc être relus **aussi** du côté des
lectures de `$GLOBALS['meta']['plugin']`, que la liste des fichiers dérivés du
préfixe ne couvre pas.

`tests/test_structure.php` refuse maintenant toute lecture indexée sur un
préfixe abandonné, et vérifie que l'agent cherche bien le sien.

## Les opérations longues ont un budget, pas seulement un plafond

La mesure des caches parcourait jusqu'à vingt mille fichiers par répertoire, un
`stat()` par fichier. Le plafond en nombre ne protège pas du temps : sur un
disque partagé, ce parcours dépassait à lui seul les trente secondes que la tour
de contrôle accorde à une synchronisation, et l'inventaire entier était perdu
pour un chiffre accessoire.

`_DASHAGENT_CACHES_BUDGET` borne désormais l'inventaire **entier**, pas chaque
répertoire — cinq cibles à trois secondes chacune auraient reproduit le
problème. Ce qui n'a pas été parcouru ressort `partiel`, et l'inventaire est
rendu quand même : une valeur approximative vaut mieux qu'une synchronisation
perdue.

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
  écrite directement dans un `#SET` rend une chaîne vide. Et il y faut une
  balise **simple** : `#ARRAY{nb,#GET{x}}` ajoute un niveau d'accolades que
  l'analyse ne suit plus, pour un « Argument manquant dans la balise SET » qui
  emporte la page entière. Quand le nombre vient d'une fonction et non d'un
  champ de boucle, faire la chaîne de langue en PHP
  (`dashboard_agent_maj_libelle()`).

## `#VAL|filtre` passe une chaîne vide, pas « rien »

SPIP compile `#VAL|mon_filtre` en `mon_filtre('')`. Une valeur par défaut
déclarée dans la signature **ne joue donc pas** — l'argument est bel et bien
passé — et la chaîne vide atterrit dans le premier paramètre.

`dashboard_waf_serie_parc($jours = 90)` en est mort à petit feu : le `''` valait
zéro, la fenêtre se réduisait au jour même, et la tendance du parc traçait deux
courbes vides. L'encadré était là, son JSON aussi, et seules les courbes
manquaient — rien, sur la page, ne le disait. Le parcours d'intégration ne
vérifiait pour ce graphique-là que sa *présence*, là où celui de la fiche d'un
site relisait déjà les pixels du canvas : le défaut a vécu dans cet écart.

La convention, que le reste du code suivait déjà : **un filtre appelé
`#VAL|nom` ne prend pas de paramètre, ou en prend un nommé `$rien`.** Ce nom est
le seul moyen de dire « je sais que SPIP me passe une chaîne vide, et je n'en
fais rien ». `tests/test_structure.php` refuse tout autre premier paramètre.

Et, ceinture et bretelles, une fenêtre de jours se normalise au lieu de se
supposer : `dashboard_waf_fenetre()` ramène zéro, le vide et le négatif à la
fenêtre par défaut.

## Ce qui vient d'un site géré est inerte, toujours

L'échappement de SPIP n'est pas une protection contre l'injection de balisage :
`interdire_scripts()` laisse passer `<svg onload>` et `<details ontoggle>`. Or un
site géré peut être compromis, et son inventaire s'affiche dans l'espace privé de
la tour de contrôle, qui détient les secrets de tout le parc.

- **à l'entrée** : tout ce qu'un agent répond passe par `dashboard_inerte()`
  avant d'atteindre la base — `dashboard_synchroniser()`,
  `dashboard_enregistrer_plugins()`, `dashboard_journaliser()`,
  `dashboard_chantier_ecrire()` et `dashboard_enregistrer_waf_serie()`, qui ne
  laisse passer qu'une date et des entiers. Un nouveau champ venu de l'agent se
  range derrière l'un de ces cinq points, pas à côté ;
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

## Cinq pièges du compilateur, tous rencontrés

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

5. **Ce qui suit `</BOUCLE_x>` n'est pas la fin du squelette** : jusqu'au
   `<//B_x>`, c'est la branche que SPIP rend **quand la boucle n'a rien
   trouvé**. Les deux `<script src>` de Chart.js y avaient atterri, en bas de
   fichier, à l'endroit qui semblait naturel — et les graphiques ne se
   traçaient que sur un parc dont les tables sont absentes, c'est-à-dire
   jamais. Rien sur la page ne le dit : le bloc est là, son JSON aussi, seul le
   tracé manque.

`tests/test_structure.php` vérifie les cinq — le quatrième en refusant toute
valeur d'attribut qui s'ouvre sur une parenthèse littérale, le cinquième tout
`<script src>` rangé dans une branche « sinon ». Il refuse aussi tout
`#ARRAY{…#GET{…}}`, variante du premier.

## Une case à cocher ne donne aucun droit

Les actions de parc — relire les dépôts, synchroniser ou vider une sélection,
mettre à jour l'agent — se pilotent depuis le navigateur, un site par requête
(`action/dashboard_parc.php`, `javascript/dashboard_parc.js`). D'où une règle
qui tient tout :

**Aucune adresse d'action n'est fabriquée en JavaScript.** Le squelette écrit
une file par opération, chacune ne contenant que les sites que l'utilisateur a
le droit d'opérer, et chaque adresse est signée pour son couple
`operation/id_site`. La case à cocher, elle, ne porte qu'un identifiant : elle
**désigne**, elle n'autorise pas. Un site coché absent de la file est écarté.

Trois écritures doivent donc s'accorder, et rien ne les relie à l'exécution : le
bouton (`data-parc-action="sync"`), la file (`data-parc-file="sync"`) et le
`case 'sync':` de l'action. Qu'une seule manque et le bouton ne fait rien, sans
erreur ni message — `tests/test_structure.php` les confronte.

Le contrat de réponse est le même pour les quatre opérations : tant que
`termine` est faux, le pilote rappelle **la même adresse**. C'est ainsi qu'une
opération en plusieurs temps — six dépôts à relire, un chantier de cinq étapes —
tient dans une boucle qui n'en sait rien.

## Porter un outil tiers, sans le piloter

SPIP Check (`git.spip.net/technova69/spip-check`, GPL 3) est un contrôle
d'intégrité autonome, déposé à la racine d'un site et ouvert dans le navigateur.
Le parc sait le déposer, en lire la version et le retirer
(`inc/dashagent_check.php`, encadré `#check` de la fiche d'un site) — et **rien
de plus**.

La raison est dans l'outil : il s'autorise sur une **session** d'administrateur
du site géré, là où l'agent s'authentifie par signature. Contourner cette
autorisation pour le piloter à distance reviendrait à poser une porte dérobée
sur tout le parc. L'agent se borne donc à ce que le FTP faisait à la main.

Trois différences avec le `spip_loader.php`, dont tout le reste est copié :

- **il se retire** (`check_retirer`), parce qu'un mégaoctet de code capable de
  lire tout le disque n'a rien à faire en permanence à la racine web. Et il se
  **supprime**, seule exception au « jamais d'effacement » qui gouverne le reste
  de l'agent : il se retélécharge d'un clic, et un script dont le nom commence
  par un point est exactement ce que SPIP Check signale comme dissimulation —
  lui laisser sa dépouille sous ce nom lui fabriquerait son constat. Le
  `spip_loader.php` a reçu le même bouton, pour les mêmes raisons ;
- **sa version se lit dans la queue du fichier**, pas dans l'en-tête : les
  bibliothèques embarquées occupent tout le début du livrable. On relit
  `_DASHAGENT_CHECK_QUEUE` octets à la fin, pas le mégaoctet entier ;
- **son adresse de téléchargement désigne une branche d'un dépôt tiers**, pas
  une archive officielle versionnée comme celle du core. Chaque dépôt sert donc
  ce qui a été poussé sur cette branche ; c'est un réglage, et un parc de
  production préférera une release ou un miroir interne. Vidée, l'encadré le dit
  et ne propose rien.

Le dépôt publie les deux orthographes, identiques. On prend celle à **souligné**
des deux côtés : elle passe sur les hébergements qui réécrivent les noms à
tiret, et c'est celle de son voisin de palier, `spip_loader.php`.

L'autorisation `op_check` lui est propre : `op_loader` ne l'ouvre pas, et
réciproquement.

Son adresse désignant une branche, le parc peut **épingler une empreinte**
(`sha256_spip_check`) : l'agent refuse alors tout fichier qui n'y répond pas, et
rend celle qu'il a reçue — sans quoi mettre l'épingle à jour après une
publication amont demanderait d'aller télécharger le mégaoctet à la main pour le
hacher. Vide par défaut, parce qu'une épingle posée d'office sur une branche
bloquerait le dépôt au premier commit venu. Le https atteste du transport,
jamais du contenu.

## Franchir la porte n'est pas s'authentifier

Un site géré derrière un `htpasswd` renvoie un **401 avant que PHP ne
s'exécute** : la signature n'est jamais examinée, puisque le code qui la
vérifie n'est jamais atteint. D'où deux colonnes sur la fiche d'un site —
`auth_user` en clair, `auth_pass` chiffré comme le secret partagé — jointes en
en-tête `Authorization: Basic` par `dashboard_auth_http()`.

Les deux ne se confondent pas, et le code le dit : les identifiants **ouvrent
la porte**, la signature **authentifie l'appel**. Un appelant qui connaîtrait le
htpasswd sans le secret franchirait le 401 pour se faire refuser par
`dashagent_verifier_signature()`.

Deux règles qui tiennent à la fuite d'identifiants :

- **en-tête, jamais dans l'adresse.** `https://user:pass@site/` finit dans les
  journaux du serveur, dans les référents et dans les messages d'erreur ;
- **Basic et rien d'autre** (`CURLAUTH_BASIC`). `CURLAUTH_ANY` ferait un premier
  appel à blanc pour découvrir la méthode, et accepterait qu'un serveur réclame
  une authentification qui laisse fuir davantage.

Tout ce qui part vers un agent passe par `dashboard_http_post()` — l'appel signé
et le rapatriement de sauvegarde. Un nouveau chemin vers un site géré se range
derrière elle, pas à côté, sans quoi il ignorera le htpasswd.

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
