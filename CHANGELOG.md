# Journal des versions

## 0.4.0 — 2026-09-23

- Plusieurs clés sur un même site : séparées par des virgules dans `LAMPARO_KEY`, ou un tableau de chaînes dans
  `lamparo-key.php` ; environnement et fichier s'additionnent, dix clés au plus. Chaque requête annonce sa clé
  (`X-Lamparo-Key-Id`) et n'obtient de réponse qu'avec celle-là. Une seule clé se pose exactement comme avant.

## 0.3.0 — 2026-09-23

Première publication sur packagist, sous `lamparo/lantern`.

- Jusqu'à deux mille paquets composer lus (cinq cents auparavant), et un `composer.lock` de plus de six mégaoctets
  n'est plus lu du tout : le motif remonte à lamparo.
- Drupal : chaque module et thème dit son dossier d'origine (contrib ou custom) et le projet drupal.org qui l'a
  empaqueté, ce qui permet de reconnaître un composant maison sans le deviner.
- Signature et fenêtre temporelle inchangées ; sans clé valide, la réponse reste « page introuvable ».
