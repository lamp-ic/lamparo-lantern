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

La clé ne va jamais dans ce fichier ni dans git. Elle se définit sur le serveur, dans une variable d'environnement
`LAMPARO_KEY`, ou dans un fichier `lamparo-key.php` posé à côté, exclu de git :

```php
<?php return 'k_xxxxxxxx:secret';
```

Sans clé, la lanterne se tait : une préproduction ne parle jamais.

## Versions

La version publiée suit `LAMPARO_PROBE_VERSION` dans le fichier. lamparo sait quelle version répond sur chaque site et
prévient quand une mise à jour compte.

Documentation : [lamparo.dev](https://lamparo.dev) · Licence MIT · [lamp](https://lamp-ic.fr)
