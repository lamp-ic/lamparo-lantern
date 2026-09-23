# Journal des versions

## 0.3.0 — 2026-09-23

Première publication sur packagist, sous `lamparo/lantern`.

- Jusqu'à deux mille paquets composer lus (cinq cents auparavant), et un `composer.lock` de plus de six mégaoctets
  n'est plus lu du tout : le motif remonte à lamparo.
- Drupal : chaque module et thème dit son dossier d'origine (contrib ou custom) et le projet drupal.org qui l'a
  empaqueté, ce qui permet de reconnaître un composant maison sans le deviner.
- Signature et fenêtre temporelle inchangées ; sans clé valide, la réponse reste « page introuvable ».
