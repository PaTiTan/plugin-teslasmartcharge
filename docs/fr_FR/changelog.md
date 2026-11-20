# Changelog

## 1.2.1-beta

- Correctif : avertissement PHP « Undefined variable $puissancePince » à chaque passage du cron hors mode Stand-By.

## 1.2.0-beta

- Les **hausses** d'ampérage sont confirmées en 10 s (au lieu de 60 s) : moins de soleil gaspillé quand le surplus est important. Les **baisses** restent confirmées 60 s (pour ne pas réagir à un nuage) et la coupure d'urgence « maison se vide » reste immédiate.

## 1.1.1-beta

- Correctif : les plages d'heures creuses n'étaient pas mémorisées (Jeedom décode automatiquement les valeurs ressemblant à du JSON). Nouveau format texte.

## 1.1.0-beta

- Plusieurs plages d'heures creuses, avec un sélecteur d'heure (le mode Planifié les utilise toutes).
- Option pour reprendre les plages d'energyManager.
- Nettoyage : démon d'exemple inutilisé et fichiers d'icônes obsolètes supprimés, `info.json` corrigé, documentation ajoutée.

## 1.0.0-beta

- Première version : modes Solaire, Planifié, Stand-By, Non actif.
