# Journal des versions

## 0.6.1 — 2026-09-23

- Joomla : un paquet livré avec le cœur — le pack de langue en-GB, par exemple — n'a pas de ligne, comme les extensions du cœur.

## 0.6.0 — 2026-09-23

- Joomla : les paquets (`administrator/manifests/packages/pkg_*.xml`) se lisent, avec leur version, leur serveur de
  mise à jour — nu, en CDATA ou avec des entités — et la liste de ce qu'ils installent. Chaque composant, module ou
  plugin installé par un paquet dit lequel (`package`) : c'est le paquet qui se met à jour, et son serveur qui répond.
- Joomla : une extension livrée avec le cœur se reconnaît aussi à son adresse d'auteur (joomla.org) ou à son copyright
  (Open Source Matters), et les éditeurs embarqués TinyMCE et CodeMirror, signés de leur propre nom, sont écartés de même.

## 0.5.0 — 2026-09-23

- Joomla : les extensions se lisent par leurs manifestes XML — composants, modules, plugins, templates — avec le nom,
  la version, l'auteur et le serveur de mise à jour que l'éditeur déclare. Les extensions livrées avec Joomla (auteur
  « Joomla! Project ») sont le cœur et n'ont pas leur ligne. Un manifeste se lit jusqu'à soixante-quatre kilo-octets,
  le serveur de mise à jour étant souvent en fin de fichier. Rien n'est appelé depuis la lanterne : lamparo interroge
  ces serveurs de son côté.

## 0.4.0 — 2026-09-23

- Une variable ou un fichier par compte lamparo qui surveille le site, nommés d'après la clé : `LAMPARO_KEY_09887C4F`,
  `lamparo-key-09887c4f.php`. La lanterne lit toutes les variables `LAMPARO_KEY_*` (et leurs formes `REDIRECT_`) et
  tous les fichiers `lamparo-key*.php`, dix clés au plus. Chaque requête annonce sa clé (`X-Lamparo-Key-Id`) et
  n'obtient de réponse qu'avec celle-là. `LAMPARO_KEY` et `lamparo-key.php`, sans suffixe, restent lus : une
  installation 0.3.0 continue telle quelle.

## 0.3.0 — 2026-09-23

Première publication sur packagist, sous `lamparo/lantern`.

- Jusqu'à deux mille paquets composer lus (cinq cents auparavant), et un `composer.lock` de plus de six mégaoctets
  n'est plus lu du tout : le motif remonte à lamparo.
- Drupal : chaque module et thème dit son dossier d'origine (contrib ou custom) et le projet drupal.org qui l'a
  empaqueté, ce qui permet de reconnaître un composant maison sans le deviner.
- Signature et fenêtre temporelle inchangées ; sans clé valide, la réponse reste « page introuvable ».
