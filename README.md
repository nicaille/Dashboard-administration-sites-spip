# Dashboard d'administration de sites SPIP

Un site SPIP qui sert de **tour de contrôle** pour un parc d'autres sites SPIP,
hébergés n'importe où, chez des hébergeurs différents, sans accès SSH.

Depuis une seule interface :

- vue d'ensemble du parc : version du core, plugins installés et leurs versions,
  extensions PHP et bibliothèques disponibles ;
- signalement des mises à jour disponibles, côté plugins et côté core, et des
  bases en attente de migration ;
- mise à jour à distance des plugins, unitairement ou en lot ;
- mise à jour à distance du core SPIP, avec retour arrière possible, **et
  migration de son schéma de base** dans la foulée — l'étape que SPIP réclamait
  jusque-là à la main, site par site ;
- sauvegarde de la base de données, rapatriée et téléchargeable ;
- purge sélective des caches : pages, squelettes, images calculées, CSS/JS, sessions ;
- consultation de l'état d'un serveur : `phpinfo()`, contenu des tables,
  fichiers de réglage — refusée par défaut, voir plus bas.

**Toute mise à jour commence par une sauvegarde**, sans exception et sans
réglage pour la désactiver.

## Comment ça marche

L'outil est fait de **deux plugins** :

| Plugin | Où on l'installe | Rôle |
|---|---|---|
| `plugins/dashboard-<version>` | sur le site tour de contrôle, un seul | interface, inventaire, conduite des chantiers de mise à jour |
| `plugins/dashboard_agent-<version>` | sur **chaque** site géré | expose un point d'entrée JSON signé, exécute les opérations qu'il a l'autorisation d'exécuter |

Le dashboard n'a besoin ni de SSH, ni de FTP, ni d'accès à la base des sites
gérés : il dialogue en HTTPS avec l'agent, chaque requête étant signée en
HMAC-SHA256 avec un secret partagé propre à chaque site.

```
   ┌──────────────────────────┐            ┌──────────────────────────┐
   │  Site tour de contrôle   │  HTTPS +   │   Site géré n°1          │
   │  plugin « dashboard »    │  HMAC      │   plugin « dashagent »   │
   │                          │───────────►│   spip.php?action=…      │
   │  - parc                  │◄───────────│                          │
   │  - journal               │   JSON     └──────────────────────────┘
   │  - sauvegardes           │            ┌──────────────────────────┐
   │                          │───────────►│   Site géré n°2 …        │
   └──────────────────────────┘            └──────────────────────────┘
```

## Les mises à jour sont des chantiers

Remplacer un noyau ou migrer un schéma prend des minutes ; aucune requête HTTP ne
tient aussi longtemps. Une mise à jour est donc découpée en étapes enregistrées
en base, dont chacune ne fait qu'un aller-retour avec le site géré.

Trois conséquences : l'avancement est **visible pendant qu'il se produit**, un
chantier interrompu — onglet fermé, coupure réseau — est **repris par une tâche
de fond**, et un échec dit **quelle étape** a lâché plutôt que de laisser une
requête mourir en silence.

| Opération | Étapes |
|---|---|
| Un plugin | sauvegarde → plugin → inventaire |
| Tous les plugins | sauvegarde → inventaire → plugins, un par un → inventaire |
| Le core | sauvegarde → contrôles → remplacement → migration du schéma → inventaire |

## Consulter l'état d'un serveur

Un onglet *Serveur* donne, pour un site géré : le résumé de PHP et de la base,
le parcours des tables (tri, filtre, pagination), le contenu de `.htaccess`,
`config/mes_options.php` et `squelettes/mes_fonctions.php`, et son `phpinfo()`.

C'est la capacité la plus indiscrète de l'outil, et elle est traitée comme telle :
**refusée par défaut** sous une autorisation distincte, à accorder site par site ;
**en lecture seule** ; **rien n'est conservé** sur le tour de contrôle ; et tout ce
qui ressemble à un identifiant — empreintes de mots de passe, clés, jetons, aléas
de session, URL de la forme `utilisateur:motdepasse@hôte` — est masqué par l'agent
avant de traverser le réseau.

Ce qui reste exposé malgré tout est décrit dans [docs/securite.md](docs/securite.md).

## Les dossiers de plugins portent leur version

`plugins/dashboard-1.0.9`, `plugins/dashboard_agent-1.0.9`. Ce n'est pas
décoratif : en déposant la nouvelle version **à côté** de l'ancienne plutôt que
par-dessus, on évite le travers classique de la mise en ligne par FTP, où les
fichiers supprimés entre deux versions survivent dans le dossier écrasé et
finissent par être chargés.

SPIP s'y retrouve seul : il indexe les plugins par préfixe et retient la version
la plus élevée quand deux dossiers déclarent le même (`ecrire/inc/plugin.php`).
Déposer le nouveau dossier suffit donc, et supprimer le nouveau suffit à revenir
en arrière.

Une précision, parce qu'elle change ce sur quoi on peut compter : les migrations
de schéma d'un plugin ne se déclenchent **pas** d'après le nom du dossier, mais
de la comparaison entre la version du `paquet.xml` et celle mémorisée en meta
(`maj_plugin()`). Elles se joueraient donc aussi bien en écrasant le dossier. Le
gain des dossiers versionnés est ailleurs : pas de fichier fantôme, et un retour
arrière immédiat.

**Les sites administrés suivent la même règle.** Une mise à jour de plugin par
archive n'écrase pas le dossier existant : elle installe la nouvelle version à
côté, dans un dossier qui porte son numéro — `auto/saisies/v6.3.4` devient
`auto/saisies/v6.3.6`, `saisies` devient `saisies-6.3.6` — puis écarte l'ancien.
Le nom du dossier retenu est repris au journal. Voir
[docs/exploitation.md](docs/exploitation.md#le-dossier-déployé-porte-la-version-installée).

## Installation rapide

1. Copier `plugins/dashboard-<version>` dans le `plugins/` du site tour de contrôle, l'activer.
2. Copier `plugins/dashboard_agent-<version>` dans le `plugins/` de **chaque** site à gérer, l'activer.
3. Sur un site géré : *Configuration → Dashboard : agent*, générer un secret,
   cocher les opérations autorisées, noter l'URL de l'agent. Deux d'entre elles
   sont refusées par défaut, à dessein : *Mettre à jour le core SPIP et migrer sa
   base*, et *Consulter l'état du serveur*.
4. Sur le tour de contrôle : *Édition → Parc de sites SPIP → Ajouter un site*,
   coller l'URL de l'agent et le secret.
5. Cliquer sur **Synchroniser**.

Le détail est dans [docs/installation.md](docs/installation.md).

## Documentation

- [Architecture](docs/architecture.md) — pourquoi deux plugins, ce que fait chacun
- [Installation et appairage](docs/installation.md)
- [Protocole d'API](docs/protocole-api.md) — opérations, signature, codes d'erreur
- [Sécurité](docs/securite.md) — modèle de menace et garde-fous
- [Exploitation](docs/exploitation.md) — usage quotidien, sauvegardes, restauration, dépannage

## Tests

### Sans installation SPIP

```bash
php tests/test_protocole.php   # 166 vérifications : signature partagée, filtrage IP,
                               # validation des archives, masquage des identifiants,
                               # enchaînement des étapes d'un chantier
php tests/test_structure.php   # 232 vérifications : manifestes, tables, autorisations,
                               # API SPIP appelée, pièges de squelette, langue
```

La seconde attrape la classe d'erreurs qui ne se voit sinon qu'à l'installation
du plugin ou au premier clic dans l'espace privé : balise inconnue dans un
`paquet.xml`, pipeline pointant sur une fonction absente, icône ou page de menu
introuvable, chaîne de langue non traduite.

Elle vérifie en particulier que **toute fonction du core SPIP appelée figure
dans un contrat explicite**, avec le `include_spip()` qui la fournit. Appeler
une API supposée exister, ou l'appeler sans son inclusion, casse le test au lieu
de casser l'espace privé. La même liste est contrôlée à l'exécution :
*Configuration → Dashboard : configuration* signale les fonctions attendues qui
manqueraient sur l'installation réelle.

Elle tient aussi deux pièges de squelette qui échouent **sans rien dire** : une
balise à accolades placée dans un argument composite, et de la syntaxe de balise
écrite dans un commentaire HTML — que SPIP compile quand même, une balise
invalide emportant alors le bloc entier.

Le premier fichier couvre le masquage des identifiants dans les deux sens : rien
de sensible ne passe (`pass`, `AWS_ACCESS_KEY_ID`, `low_sec`, les aléas de
session, une URL portant ses identifiants), rien d'anodin n'est masqué à tort
(`nom`, `login`, `monkey_cache`, `keyboard_layout`).

### Sur un SPIP réel

```bash
tests/integration/executer.sh /chemin/vers/SPIP-v4.4.23.zip
```

Installe un SPIP en SQLite, y active les deux plugins, et déroule 111
vérifications dans un navigateur : création d'un site, appairage, synchronisation
signée, purge de cache, sauvegarde de base et restauration du dump, mise à jour
d'un plugin depuis un dépôt local, remplacement du noyau par une archive de core
fabriquée pour l'occasion, migration de schéma qui suit, et onglet *Serveur* —
jusqu'à vérifier qu'aucune empreinte ni aucun secret n'atteint l'écran.

C'est ce parcours qui a trouvé ce que l'analyse statique ne pouvait pas voir :
l'incompatibilité SQLite de la sauvegarde, le recalcul de liste qui désactivait
les plugins, un bouton de mise à jour du core qui n'avait jamais pu fonctionner,
et une migration de schéma qui s'exécutait sur le code d'avant le remplacement.

Si l'environnement n'autorise pas un démon lancé depuis un script, ou si le
serveur doit être démarré après la préparation du site, deux drapeaux séparent
les phases :

```bash
DASHBOARD_TEST_PREPARATION_SEULE=1 tests/integration/executer.sh <zip> 8321
cd <repertoire>/site && PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8321 -t . &
DASHBOARD_TEST_SANS_PREPARATION=1 tests/integration/executer.sh <zip> 8321
```

Voir [tests/integration/README.md](tests/integration/README.md).

## Compatibilité

SPIP 4.1 à 4.4, PHP 7.4+. L'agent fonctionne en hébergement mutualisé : il ne
suppose ni `exec()`, ni `mysqldump`, ni accès au système de fichiers hors du site.

## Licence

GPL v3 — voir [LICENSE](LICENSE).
