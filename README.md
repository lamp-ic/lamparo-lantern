# La lanterne de lamparo

Un seul fichier PHP, `lamparo.php`, posé à la racine d'un site. Quand [lamparo](https://lamparo.app) l'appelle avec une
requête signée, il répond ce que le site sait de lui-même : la version de PHP, le CMS et sa version, les extensions,
modules et thèmes installés, les paquets composer déclarés. Sans signature valide, il répond « page introuvable ».

Il **lit** une liste fermée de faits. Il n'écrit rien, n'exécute rien, ne modifie rien, ne se met pas à jour tout seul.
Son code tient dans un seul fichier, sans dépendance, PHP 7.4 ou plus, que vous pouvez lire en entier avant de le poser.

## Installation par composer

```bash
composer require lamparo/lantern
```

Le fichier arrive dans `vendor/`, où il n'est pas servi — c'est voulu. Il reste à le copier à la racine web, et
cela dépend de ce qui fait tourner le site. Choisissez votre ligne, elle se recopie telle quelle :

| Site | Ce qu'il faut ajouter au `composer.json` du projet |
|---|---|
| **Drupal** | rien à copier : le scaffold s'en charge. Autorisez seulement le paquet : `"extra": {"drupal-scaffold": {"allowed-packages": ["lamparo/lantern"]}}` |
| **Symfony**, **Laravel** | `"scripts": {"post-install-cmd": ["cp vendor/lamparo/lantern/lamparo.php public/lamparo.php"], "post-update-cmd": ["cp vendor/lamparo/lantern/lamparo.php public/lamparo.php"]}` |
| **WordPress** (Bedrock) | même script, vers `web/lamparo.php` |
| **PrestaShop**, **Joomla**, **WordPress** classique, **sur mesure** à la racine | même script, vers `lamparo.php` (la racine du projet est la racine web) |

Dans tous les cas, chaque `composer install` ou `composer update` remet la lanterne à jour : mettre à jour la lanterne,
c'est mettre à jour le paquet, rien d'autre ne change. Une extension WordPress officielle viendra après le lancement
de lamparo ; d'ici là, un WordPress sans composer se pose par le fichier direct.

### Sans composer

Téléchargez `lamparo.php` depuis votre espace lamparo, ou depuis ce dépôt, et déposez-le à la racine du site.

## La clé

La clé ne va jamais dans le fichier ni dans git. Elle se définit **sur le serveur de production**, dans une variable
d'environnement `LAMPARO_KEY` — c'est la voie recommandée, celle que lamparo affiche en premier :

| Où | Comment |
|---|---|
| Plesk, cPanel, o2switch | le panneau, rubrique variables d'environnement PHP du domaine |
| Apache (`.htaccess` ou vhost) | `SetEnv LAMPARO_KEY "k_xxxxxxxx:secret"` |
| nginx + PHP-FPM | `fastcgi_param LAMPARO_KEY "k_xxxxxxxx:secret";` dans le bloc `server` |
| PHP-FPM (pool) | `env[LAMPARO_KEY] = "k_xxxxxxxx:secret"` |

À défaut, un fichier `lamparo-key.php` posé à côté, **exclu de git** — moins sûr, parce qu'un fichier peut finir dans
un dépôt ou être servi en clair par un serveur mal configuré ; à réserver aux hébergements où l'on n'a pas la main
sur l'environnement :

```php
<?php return 'k_xxxxxxxx:secret';
```

Sans clé, la lanterne se tait : une préproduction ne parle jamais. La clé de chaque site se trouve dans votre espace
lamparo, sur la page de la lanterne.

### Plusieurs comptes sur le même site

Un client qui suit son propre parc et l'agence qui l'entretient ont chacun leur clé pour le même site. Elles se posent
toutes sur le même serveur, séparées par des virgules dans `LAMPARO_KEY` :

```
SetEnv LAMPARO_KEY "k_xxxxxxxx:secret,k_yyyyyyyy:secret"
```

ou une par chaîne dans le fichier :

```php
<?php return ['k_xxxxxxxx:secret', 'k_yyyyyyyy:secret'];
```

Les clés de l'environnement et du fichier s'additionnent, dix au plus. Chaque requête annonce la clé qu'elle porte ;
la lanterne répond avec celle-là, ou pas du tout.

## Versions

La version publiée suit `LAMPARO_PROBE_VERSION` dans le fichier. lamparo sait quelle version répond sur chaque site et
prévient quand une mise à jour compte.

Documentation : [lamparo.dev](https://lamparo.dev) · Licence MIT · [lamp](https://lamp-ic.fr)
