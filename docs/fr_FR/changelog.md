# Changelog

## 1.4.0-beta

- **L'écouteur de capteurs fonctionne enfin** : il lisait la mauvaise clé d'options (il ne faisait rien, seul le cron d'une minute pilotait). Il accepte maintenant les deux formes, avec un verrou qui évite les traitements en parallèle.
- **Plus d'ordres répétés** : la dernière consigne envoyée est mémorisée (120 s) ; la même valeur n'est plus renvoyée à la Tesla tant que le courant lu n'a pas suivi (quota d'API).
- **Priorité Maison** : la décharge de la batterie compte toujours dans le surplus (la voiture ne vit plus de la batterie).
- **Mesure illisible** (réseau, batterie) : la consigne est conservée, avec une coupure d'urgence si la batterie se décharge pendant la charge.
- Un mode vide ou inconnu ne coupe plus la charge ; les entrées de commandes (mode, priorité, seuil 0-100, cible 10-100) sont validées ; passer une voiture en Stand-By n'envoie plus d'ordre aux autres équipements.
- Une erreur d'ordre (voiture endormie) ne bloque plus les autres équipements ; une commande de capteur introuvable ne fait plus échouer le cron ni l'enregistrement.
- Réveil réussi : la garde de 30 minutes est levée. Infos d'énergie et de vitesse de charge mises à jour aussi en mode Solaire. Calcul des heures creuses borné à 14 jours. Dépréciations PHP 8.2 corrigées.

## 1.3.0-beta

- **Priorité Maison sans attente** : sous le seuil de batterie, la charge de la voiture n'est plus arrêtée. La batterie passe d'abord, mais la voiture prend tout ce que la batterie n'absorbe pas (l'injection) et charge dès 2 A. Au-dessus du seuil, elle prend aussi le surplus qui chargerait la batterie.

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
