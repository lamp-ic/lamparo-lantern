# Journal des versions

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
