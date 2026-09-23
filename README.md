# La lanterne de lamparo

Un seul fichier PHP, `lamparo.php`, posé à la racine d'un site. Quand [lamparo](https://lamparo.app) l'appelle avec une
requête signée, il répond ce que le site sait de lui-même : la version de PHP, le CMS et sa version, les extensions,
modules et thèmes installés, les paquets composer déclarés. Sans signature valide, il répond « page introuvable ».

Il **lit** une liste fermée de faits. Il n'écrit rien, n'exécute rien, ne modifie rien, ne se met pas à jour tout seul.
Son code tient dans un seul fichier, sans dépendance, PHP 7.4 ou plus, que vous pouvez lire en entier avant de le poser.

## Installation par composer

### Drupal

```bash
composer require lamparo/lantern
```

puis, dans le `composer.json` du projet, autorisez le paquet auprès du scaffold de Drupal :

```json
"extra": {
    "drupal-scaffold": {
        "allowed-packages": ["lamparo/lantern"]
    }
}
```

À chaque `composer install` ou `composer update`, le scaffold recopie `lamparo.php` à la racine web. Mettre la
lanterne à jour, c'est mettre le paquet à jour : rien d'autre ne change.

### Symfony et sites sur mesure

```bash
composer require lamparo/lantern
```

puis un script qui copie le fichier vers votre racine web :

```json
"scripts": {
    "post-install-cmd": ["cp vendor/lamparo/lantern/lamparo.php public/lamparo.php"],
    "post-update-cmd": ["cp vendor/lamparo/lantern/lamparo.php public/lamparo.php"]
}
```

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
