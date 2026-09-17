# Diffuser les plugins par un dépôt SVP

Les deux plugins se déposent à la main sur chaque site : copier `plugins/`,
activer, recommencer au site suivant. Passé quelques sites, c'est une corvée — et
une corvée qu'on repousse, ce qui laisse traîner des versions anciennes sur le
parc.

Un dépôt SVP supprime la corvée : chaque site géré déclare une adresse une fois
pour toutes, puis voit les nouvelles versions arriver comme celles de n'importe
quel plugin de `plugins.spip.net`. La tour de contrôle sait déjà les installer à
distance — c'est le chemin décrit dans
[Mettre à jour les plugins](exploitation.md#mettre-à-jour-les-plugins).

## Ce qu'est un dépôt, au juste

Pas un service : **deux fichiers statiques**, servis en HTTP.

```
plugins.xml                    le catalogue
plugins.thin.xml               une copie (voir plus bas)
archives/tourdecontrole-1.0.19.zip  une archive par plugin et par version
archives/tourdecontrole_agent-1.0.15.zip
index.html                     une page lisible par un humain
```

N'importe quel hébergement statique convient. Ici, c'est **GitHub Pages**,
alimenté par le workflow `.github/workflows/depot.yml`.

Le catalogue porte deux blocs **frères**, `<depot>` et `<archives>` : ce n'est
pas un document XML à racine unique, et c'est voulu. SVP le lit à l'expression
régulière (`svp_phraser_depot()`, dans `inc/svp_phraser.php`), jamais avec un
analyseur strict. Un générateur qui passerait par `DOMDocument` produirait une
racine englobante et un dépôt que personne ne pourrait ajouter.

```xml
<?xml version="1.0" encoding="utf-8"?>
<depot>
	<titre>Tour de contrôle — administration d’un parc de sites SPIP</titre>
	<type>http</type>
	<url_archives>https://…/archives</url_archives>
</depot>
<archives>
	<archive dtd="paquet">
		<zip>
			<file>tourdecontrole-1.0.19.zip</file>
			<size>93601</size>
			<date>1789646033</date>
			<last_commit>2026-09-17 11:53:53</last_commit>
			<source>https://…/archives/tourdecontrole-1.0.19.zip</source>
		</zip>
		<!-- le paquet.xml du plugin, recopié tel quel -->
		<paquet prefix="tourdecontrole" version="1.0.19" …>…</paquet>
	</archive>
</archives>
```

Trois détails se paient cher si on les ignore :

- **`<type>http</type>` désigne le téléporteur**, pas le protocole d'accès au
  catalogue. C'est lui qui fait télécharger l'archive à l'adresse
  `url_archives` + `/` + `<file>` (`svp_actionner.php`). Les autres valeurs
  (`git`, `svn`) attendent un dépôt de sources, pas des zips.
- **`url_archives` n'a pas de barre oblique finale** : SVP en ajoute une, et deux
  donneraient une adresse introuvable.
- **Le zip a une racine unique**, du nom du préfixe. Le téléporteur la retire au
  déballage (`teleporter_http_charger_zip()`) et range le contenu dans
  `plugins/auto/<prefixe>/v<version>`. Deux racines, ou aucune, et le plugin
  atterrit à un niveau que SPIP n'indexe pas.

## Le sondage des variantes allégées

SVP ne demande pas le catalogue tout de suite. Il sonde d'abord, en HEAD,
`plugins.thin.spip-<branche>.xml`, puis `plugins.thin.xml`, et ne retombe sur
l'original qu'après deux 404 — c'est `svp_depoter_distant_copie_xml_paquets()`,
et cela ne se voit dans aucun message d'erreur.

D'où deux conséquences :

- on publie `plugins.thin.xml`, copie conforme du catalogue. Nos deux plugins
  couvrant les mêmes versions de SPIP, il n'y a rien à alléger ; cela épargne un
  aller-retour ;
- **l'hébergement doit répondre 404 à un fichier absent.** GitHub Pages le fait.
  Le serveur intégré de PHP, non : il sert `index.html` avec un code 200, et SVP
  lit alors cette page d'accueil en guise de catalogue, pour n'annoncer qu'un
  laconique « le fichier XML de description du dépôt n'est pas conforme ». Qui
  voudrait héberger ce dépôt ailleurs doit vérifier ce point d'abord.

## Publier une version

La publication ne suit pas les commits, elle suit **un geste**. Pousser sur
`main` ne change rien pour le parc.

1. Monter la version du plugin : `version=` dans son `paquet.xml`, et le nom de
   son dossier dans le même commit (voir
   [la règle des dossiers versionnés](../README.md#les-dossiers-de-plugins-portent-leur-version)).
   Le générateur refuse de publier un dossier dont le nom ment sur sa version.
2. Poser un tag et le pousser :

   ```bash
   git tag tourdecontrole-1.0.19
   git push origin tourdecontrole-1.0.19
   ```

   Sur le web, c'est la page **Releases** du dépôt qui pose un tag : *Draft a
   new release*, puis *Choose a tag* et saisir le nom pour le créer.

   ou, sans tag, lancer *Publier le dépôt de plugins* depuis l'onglet **Actions**
   de GitHub.

Le nom du tag n'est qu'une étiquette : le dépôt publie **tous** les plugins
présents, chacun à la version que porte son dossier. Tagger `tourdecontrole-1.0.19`
publie donc aussi l'agent, à sa version du moment.

Le workflow passe les trois suites de tests avant de publier : un dépôt est lu
par tout le parc, une erreur y coûte plus qu'ailleurs.

### Fabriquer le dépôt à la main

```bash
php outils/generer-depot.php --url=https://exemple.org/depot --vers=_depot
```

`--url` est l'adresse publique à laquelle le contenu de `--vers` sera servi. Elle
figure en toutes lettres dans le catalogue, puisque c'est par elle que les sites
téléchargent les archives : on ne peut pas la deviner après coup.

## Déclarer le dépôt sur un site géré

Sur chaque site du parc, muni de SVP : espace privé, **Plugins → Dépôts →
Ajouter un dépôt**, puis coller l'adresse du catalogue :

```
https://<compte>.github.io/<dépôt>/plugins.xml
```

SVP vérifie l'adresse par une requête HEAD qui doit rendre **exactement 200**
(`svp_verifier_adresse_depot()`) ; les redirections sont suivies, mais la page
d'arrivée doit être le fichier.

Ensuite, plus rien à faire à la main : la tour de contrôle relit les dépôts de
tous les sites — bouton *Relire les dépôts de tout le parc* sur la vue
d'ensemble — et signale les mises à jour disponibles comme pour n'importe quel
autre plugin.

## Activer GitHub Pages, une fois

Dans les réglages du dépôt GitHub : **Settings → Pages → Source : GitHub
Actions**. Sans cela, le workflow échoue au déploiement.

## Ce que ce dépôt ne fait pas

- **Il ne signe rien.** SVP ne vérifie aucune signature de paquet : la confiance
  repose entièrement sur HTTPS et sur l'hébergeur. Or ces plugins-là portent les
  secrets du parc. C'est le même modèle que `plugins.spip.net`, mais il vaut
  mieux l'avoir dit.
- **Il ne garde pas les anciennes versions.** Le catalogue publie ce que contient
  `plugins/` au moment du tag, soit une version par plugin. Revenir en arrière se
  fait en republiant depuis un tag antérieur.
- **Il ne résout pas la mise à jour de l'agent par lui-même.** Un agent qui se
  met à jour voit son propre code déplacé pendant qu'il répond ; la réponse peut
  ne jamais revenir. Ce cas se traite côté chantier, pas côté dépôt.

## Tests

```bash
php tests/test_depot.php
```

Il fabrique un dépôt dans un répertoire temporaire et le passe aux règles de SVP
— ses propres expressions régulières, reprises telles quelles — puis vérifie les
archives une à une : taille annoncée, racine unique, `paquet.xml` à sa place,
aucune scorie de travail. Il vérifie aussi les deux refus du générateur : un
dossier dont le nom ment sur sa version, et l'absence de `--url`.

La chaîne complète a par ailleurs été jouée contre un vrai SVP : ajout du dépôt,
indexation des paquets, détection d'une version plus récente, téléportation et
déballage dans `plugins/auto/dashagent/v1.0.15`.
