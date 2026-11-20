# Plugin Tesla Smart Charge

## Présentation

Tesla Smart Charge pilote la recharge d'une Tesla à partir d'un plugin Tesla existant (commandes `charge_amps`, `charge_onoff`, `wakeup`, `refresh`). Il protège les appels à l'API Tesla (compteurs, séquence de réveil, pause en cas d'échec).

## Configuration du plugin

- **Sondes** : puissance « habitation » (puissance réseau, **positive quand vous soutirez**), niveau et puissance de la batterie maison (positive en charge, négative en décharge), puissance de charge de la voiture.
- **Heures creuses** : une ou plusieurs plages, choisies avec le sélecteur d'heure (de 5 en 5 minutes). Le bouton **Ajouter une plage** crée une plage supplémentaire. Si energyManager est installé, une case permet d'utiliser ses plages à la place.
- **Mode télémétrie intensive** : écrit un statut dans le journal chaque minute.

Après un changement des capteurs, il faut **réenregistrer l'équipement** (bouton Sauvegarder) pour que les écouteurs soient reconstruits avec les nouvelles sondes.

## Modes

| Mode | Fonctionnement |
|---|---|
| Solaire | Ajuste l'ampérage (2 à 32 A) au surplus : batterie + injection + puissance déjà utilisée par la voiture. Une hausse est confirmée 10 s, une baisse 60 s ; coupure immédiate si la maison se vide. |
| Planifié | Charge pour atteindre la cible à la date choisie, en privilégiant les heures creuses (toutes les plages) avec un ampérage lissé. |
| Stand-By | Charge arrêtée. Repasse en Solaire si une charge est détectée après rebranchement. |
| Non actif | Le plugin ne fait plus rien. |

Le passage en Stand-By est automatique si la voiture est débranchée ou si la cible est atteinte.

## Priorité Voiture ou Maison

- **Voiture** : la voiture prend le surplus, y compris celui qui chargerait la batterie.
- **Maison** : tant que la batterie est sous le seuil choisi, la batterie passe d'abord : sa puissance de charge n'est pas offerte à la voiture. La voiture n'attend jamais un niveau de batterie : elle prend tout ce que la batterie n'absorbe pas (l'injection) et charge dès qu'il y a au moins 2 A (460 W). Au-dessus du seuil, elle prend aussi le surplus qui chargerait la batterie.

## Utilisation avec energyManager

energyManager devient le seul décideur : il commande le mode (`set_mode`) et la priorité (`set_priorite_charge`) de ce plugin, et lit `mode_actuel`. Voir la documentation d'energyManager.
