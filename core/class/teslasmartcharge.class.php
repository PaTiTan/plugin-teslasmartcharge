<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class teslasmartcharge extends eqLogic {

    // Délai de confirmation d'un changement d'ampérage dans le même sens avant de l'appliquer :
    // court pour les hausses (ne pas gaspiller de soleil), long pour les baisses (ne pas réagir à un nuage).
    const CONFIRMATION_HAUSSE = 10;
    const CONFIRMATION_BAISSE = 60;

    // ====================================================================
    // LES COMPTEURS D'API ET LE NETTOYAGE JOURNALIER
    // ====================================================================
    public static function incrementApiCounter($eqLogic, $type) {
        if (!is_object($eqLogic)) return;
        $cmdId = ($type == 'info') ? 'api_appels_infos' : 'api_appels_ordres';
        $cmd = $eqLogic->getCmd(null, $cmdId);
        if (is_object($cmd)) {
            $currentVal = floatval($cmd->execCmd());
            $eqLogic->checkAndUpdateCmd($cmdId, $currentVal + 1);
        }
    }

    /** Commande Jeedom depuis sa forme texte (#[Objet][Equipement][Commande]#) ; null si vide, introuvable ou en erreur. */
    private static function cmdFromString($str) {
        if (!is_string($str) || trim($str) === '') return null;
        try {
            $c = cmd::byString($str);
            return is_object($c) ? $c : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Valeur par défaut si le capteur n'est pas configuré ; null si configuré mais introuvable ou non numérique. */
    private static function readSensor($configKey, $default) {
        $str = config::byKey($configKey, 'teslasmartcharge');
        if (!is_string($str) || trim($str) === '') return $default;
        $c = self::cmdFromString($str);
        if ($c === null) return null;
        try {
            $v = $c->execCmd();
        } catch (Throwable $e) {
            return null;
        }
        return is_numeric($v) ? floatval($v) : null;
    }

    /** Envoie un ordre ou un refresh à la Tesla : compteur d'API, journal, et jamais d'exception vers l'appelant. */
    private static function sendOrder($eqLogic, $cmd, $options, $label, $type = 'ordre') {
        if (!is_object($cmd)) return false;
        self::incrementApiCounter($eqLogic, $type);
        log::add('teslasmartcharge', 'info', "🔴 [REQUÊTE API TESLA] -> ACTION : " . $label);
        try {
            $cmd->execCmd($options);
            return true;
        } catch (Throwable $e) {
            log::add('teslasmartcharge', 'error', "Ordre Tesla en échec ({$label}) : " . $e->getMessage());
            return false;
        }
    }

    /** Mémorise la dernière consigne d'ampérage envoyée (0 = arrêt) pour ne pas la renvoyer tant que la Tesla ne l'a pas répercutée. */
    private static function rememberOrder($eqLogic, $amp) {
        $eqLogic->setCache('last_cmd_amp', (int) $amp);
        $eqLogic->setCache('last_cmd_time', time());
    }

    public static function cronDaily() {
        foreach (eqLogic::byType('teslasmartcharge', true) as $eqLogic) {
            if ($eqLogic->getIsEnable() != 1) continue;
            $eqLogic->checkAndUpdateCmd('api_appels_infos', 0);
            $eqLogic->checkAndUpdateCmd('api_appels_ordres', 0);
            log::add('teslasmartcharge', 'info', "[QUOTAS] Remise à zéro des compteurs API (Infos/Ordres) pour " . $eqLogic->getName());
        }
    }

    // ====================================================================
    // PLAGES D'HEURES CREUSES (plusieurs plages possibles)
    // ====================================================================
    private static function parseHm($str) {
        if (preg_match('/^\s*(\d{1,2})\s*[:h]?\s*(\d{2})\s*$/', (string) $str, $m)) {
            return intval($m[1]) * 60 + intval($m[2]);
        }
        return null;
    }

    /**
     * Plages HC en minutes depuis minuit : array(array(debut, fin), ...).
     * Source : plages d'energyManager si l'option est cochee, sinon les plages du plugin,
     * sinon l'ancien reglage a une seule plage (defaut 22h-06h).
     */
    public static function hcSlots() {
        $json = '';
        if (config::byKey('hc_use_energy', 'teslasmartcharge', 0) == 1 && class_exists('energyManager')) {
            $json = config::byKey('hc_slots', 'energyManager', '');
        }
        if ($json === '' || $json === null) {
            $json = config::byKey('hc_slots', 'teslasmartcharge', '');
        }
        $slots = array();
        // format texte "00:50-06:50;14:20-16:20"
        if (is_string($json) && preg_match_all('/(\d{1,2})\s*[:h]\s*(\d{2})\s*-\s*(\d{1,2})\s*[:h]\s*(\d{2})/', $json, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $a = intval($m[1]) * 60 + intval($m[2]);
                $b = intval($m[3]) * 60 + intval($m[4]);
                if ($a != $b) {
                    $slots[] = array($a, $b);
                }
            }
        }
        if (count($slots) == 0) {
            $a = self::parseHm(config::byKey('hc_start', 'teslasmartcharge', '22:00'));
            $b = self::parseHm(config::byKey('hc_end', 'teslasmartcharge', '06:00'));
            $slots[] = array($a === null ? 1320 : $a, $b === null ? 360 : $b);
        }
        return $slots;
    }

    /** Vrai si la minute du jour (0-1439) est en heure creuse. */
    public static function isHcAt($minuteOfDay, $slots = null) {
        if ($slots === null) $slots = self::hcSlots();
        foreach ($slots as $sl) {
            if ($sl[0] < $sl[1]) {
                if ($minuteOfDay >= $sl[0] && $minuteOfDay < $sl[1]) return true;
            } elseif ($minuteOfDay >= $sl[0] || $minuteOfDay < $sl[1]) {
                return true; // plage a cheval sur minuit
            }
        }
        return false;
    }

    /** Nombre de minutes d'heures creuses entre deux timestamps. */
    public static function hcMinutesBetween($from, $to) {
        $to = min($to, $from + 14 * 86400); // borne à 14 jours : une date lointaine ne doit pas boucler des millions de fois
        $count = 0;
        $slots = self::hcSlots();
        for ($t = $from; $t < $to; $t += 60) {
            if (self::isHcAt(intval(date('H', $t)) * 60 + intval(date('i', $t)), $slots)) $count++;
        }
        return $count;
    }

    // ====================================================================
    // L'ESTIMATEUR PRÉDICTIF 
    // ====================================================================
    public static function getChargingPlan($eqLogic) {
        $teslaEqId = $eqLogic->getConfiguration('tesla_eqlogic_id');
        if ($teslaEqId == '') return "";
        $teslaEq = eqLogic::byId($teslaEqId);
        if (!is_object($teslaEq)) return "";

        $cmdBat = $teslaEq->getCmd(null, 'battery_level');
        $batterieVal = is_object($cmdBat) ? floatval($cmdBat->execCmd()) : 0;
        $cibleCharge = is_object($eqLogic->getCmd(null, 'cible_charge')) ? floatval($eqLogic->getCmd(null, 'cible_charge')->execCmd()) : 100;

        if ($batterieVal >= $cibleCharge) return "[PLAN] Cible déjà atteinte. Aucune charge requise.";

        $datePlanifieeVal = is_object($eqLogic->getCmd(null, 'date_planifiee')) ? $eqLogic->getCmd(null, 'date_planifiee')->execCmd() : '';
        $targetTime = strtotime((string) $datePlanifieeVal);
        $now = time();

        if (!$targetTime || $targetTime <= $now) return "";

        $hc_minutes_available = self::hcMinutesBetween($now, $targetTime);

        $delta = $cibleCharge - $batterieVal;
        $mins_at_32 = $delta * 4;
        $h_needed = round($mins_at_32 / 60, 1);
        $h_hc = round($hc_minutes_available / 60, 1);

        if ($hc_minutes_available > 0) {
            $lissage = ceil(($mins_at_32 / $hc_minutes_available) * 32);
            if ($lissage < 2) $lissage = 2;
            if ($lissage > 32) $lissage = 32;
        } else {
            $lissage = 32; 
        }

        return "[PLAN] Besoin de {$delta}% (~{$h_needed}h à 32A). HC dispo: {$h_hc}h. Lissage estimé: {$lissage}A.";
    }

    // ====================================================================
    // L'ÉCOUTEUR HAUTE VITESSE (LE CERVEAU SOLAIRE)
    // ====================================================================
    public static function solarListener($_event) {
        // Jeedom (listener::run) transmet les options à plat ; le cron les passe dans 'options' : on accepte les deux formes.
        $opt = (isset($_event['options']) && is_array($_event['options'])) ? $_event['options'] : array();
        $eqLogic_id = isset($_event['eqLogic_id']) ? $_event['eqLogic_id'] : (isset($opt['eqLogic_id']) ? $opt['eqLogic_id'] : null);
        if (!$eqLogic_id) return;
        
        $eqLogic = eqLogic::byId($eqLogic_id);
        if (!is_object($eqLogic) || $eqLogic->getIsEnable() != 1) return;

        // Un seul traitement à la fois par équipement : plusieurs capteurs qui publient ensemble lancent plusieurs processus en parallèle,
        // et l'anti-rebond de 10 s ci-dessous n'est pas atomique (ordres en double, quota API consommé). Le verrou est libéré à la sortie.
        $lock = false;
        try {
            $lock = @fopen(jeedom::getTmpFolder('teslasmartcharge') . '/solar_' . intval($eqLogic_id) . '.lock', 'c');
        } catch (Throwable $e) {
            $lock = false;
        }
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) return;

        $modeActuelVal = is_object($eqLogic->getCmd(null, 'mode_actuel')) ? $eqLogic->getCmd(null, 'mode_actuel')->execCmd() : '';
        if ($modeActuelVal != 'Solaire') return; // Si mode est 'Non actif' ou autre, le listener est inopérant.

        $isForced = !empty($_event['force']) || !empty($opt['force']);
        $lastActionTime = $eqLogic->getCache('last_solar_action', 0);
        $timeSinceLast = time() - $lastActionTime;

        if (!$isForced && ($timeSinceLast < 10)) return;
        $eqLogic->setCache('last_solar_action', time());
        if ($eqLogic->getCache('standby_already_logged', 0) != 0) $eqLogic->setCache('standby_already_logged', 0);

        // Une mesure configurée mais illisible vaut null (et non 0) : on garde alors la consigne (voir plus bas).
        $valeurHabitation = self::readSensor('infoHabitation', 0);
        $niveauBatMaison = self::readSensor('infoBatterieMaison', 100);
        $puissanceBatMaison = self::readSensor('infoPuissanceBatterieMaison', 0);
        $margeVal = is_object($eqLogic->getCmd(null, 'marge_actuelle')) ? floatval($eqLogic->getCmd(null, 'marge_actuelle')->execCmd()) : 0;
        $puissancePince = self::readSensor('infoPuissanceVoiture', 0);
        if ($puissancePince === null) $puissancePince = 0;

        $teslaEqId = $eqLogic->getConfiguration('tesla_eqlogic_id');
        if ($teslaEqId == '') return;
        $teslaEq = eqLogic::byId($teslaEqId);
        if (!is_object($teslaEq)) return;

        $priorite = is_object($eqLogic->getCmd(null, 'priorite_charge')) ? $eqLogic->getCmd(null, 'priorite_charge')->execCmd() : 'voiture';
        $seuilBat = is_object($eqLogic->getCmd(null, 'seuil_batterie_maison')) ? floatval($eqLogic->getCmd(null, 'seuil_batterie_maison')->execCmd()) : 80;

        // Mesure illisible : on ne décide pas sur une valeur inventée (0 W ou 100 %), la consigne en cours est conservée.
        $mesureIllisible = ($valeurHabitation === null || $puissanceBatMaison === null || ($niveauBatMaison === null && $priorite == 'maison'));
        if ($valeurHabitation === null) $valeurHabitation = 0;
        if ($puissanceBatMaison === null) $puissanceBatMaison = 0;
        if ($niveauBatMaison === null) $niveauBatMaison = 100;

        $cmdAwake = $teslaEq->getCmd(null, 'is_online');
        $cmdCable = $teslaEq->getCmd(null, 'charge_port_latch');
        $cmdAmps = $teslaEq->getCmd(null, 'charger_actual_current');
        $cmdWakeUpStr = $teslaEq->getCmd(null, 'wakeup');
        $cmdAmperageStr = $teslaEq->getCmd(null, 'charge_amps');
        $cmdControlStr = $teslaEq->getCmd(null, 'charge_onoff');
        $cmdRefresh = $teslaEq->getCmd(null, 'refresh'); 
        
        $cmdBat = $teslaEq->getCmd(null, 'battery_level');
        $batterieVal = is_object($cmdBat) ? floatval($cmdBat->execCmd()) : 0;
        $cibleCharge = is_object($eqLogic->getCmd(null, 'cible_charge')) ? floatval($eqLogic->getCmd(null, 'cible_charge')->execCmd()) : 100;

        if ($batterieVal >= $cibleCharge) {
            $eqLogic->checkAndUpdateCmd('mode_actuel', 'Stand-By');
            $eqLogic->setCache('standby_already_logged', 1);
            $logMsg = "[STAND-BY] Cible atteinte ({$cibleCharge}%). Bascule auto sur Stand-By.";
            $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($logMsg, 0, 127, 'UTF-8'));
            log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);

            if (is_object($cmdControlStr)) {
                self::sendOrder($eqLogic, $cmdControlStr, array('select' => '0'), "Envoi de l'ordre d'ARRÊT de la charge (0).");
                $eqLogic->setCache('last_cmd_amp', -1); // on quitte le mode Solaire : plus de consigne en attente de retour
            }
            return;
        }

        log::add('teslasmartcharge', 'debug', "🟢 [LECTURE LOCALE JEEDOM] Vérification des capteurs et états dans Solaire.");

        $isAwake = false;
        if (is_object($cmdAwake)) {
            $val = strtolower((string) $cmdAwake->execCmd());
            if (in_array($val, ['1', 'true', 'online', 'awake'])) $isAwake = true;
        }

        $isPlugged = false;
        if (is_object($cmdCable)) {
            $val = strtolower((string) $cmdCable->execCmd());
            if (in_array($val, ['engagé', 'engaged', 'connected', 'plugged', '1', 'true'])) $isPlugged = true;
        }

        if (!$isPlugged) {
            $eqLogic->checkAndUpdateCmd('mode_actuel', 'Stand-By');
            $eqLogic->setCache('standby_already_logged', 1);
            $eqLogic->setCache('action_pending_since', 0);
            $logMsg = "[STAND-BY] Voiture débranchée. Bascule auto sur Stand-By.";
            $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($logMsg, 0, 127, 'UTF-8'));
            log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);

            if (is_object($cmdControlStr)) {
                self::sendOrder($eqLogic, $cmdControlStr, array('select' => '0'), "Envoi de l'ordre d'ARRÊT de la charge (0) (Sécurité débranchement).");
                $eqLogic->setCache('last_cmd_amp', -1);
            }
            return;
        }

        $currentAmperage = is_object($cmdAmps) ? floatval($cmdAmps->execCmd()) : 0;
        $isCharging = ($currentAmperage > 0 || $puissancePince > 200);

        if ($mesureIllisible) {
            // On ne décide pas sur une valeur inventée : la consigne en cours est conservée... sauf si la batterie se décharge
            // (mesure de puissance batterie lisible) pendant la charge : la voiture ne doit jamais vivre de la batterie, on coupe.
            $dejaArrete = ((int) $eqLogic->getCache('last_cmd_amp', -1) === 0 && (time() - (int) $eqLogic->getCache('last_cmd_time', 0)) < 120);
            $battLue = self::readSensor('infoPuissanceBatterieMaison', 0);
            if ($battLue !== null && $battLue < -50 && $isCharging && is_object($cmdControlStr) && !$dejaArrete) {
                if (self::sendOrder($eqLogic, $cmdControlStr, array('select' => '0'), "Envoi de l'ordre d'ARRÊT de la charge (0) (mesure illisible, batterie qui se décharge).")) {
                    self::rememberOrder($eqLogic, 0);
                }
            }
            $eqLogic->checkAndUpdateCmd('statut_action', "[CAPTEUR] Mesure illisible : consigne conservée.");
            return;
        }

        $wakeupFailed = $eqLogic->getCache('wakeup_failed', 0);
        $wakeupPending = $eqLogic->getCache('wakeup_pending', 0);
        $lastWakeupTime = $eqLogic->getCache('last_wakeup_time', 0);

        // Voiture réveillée : plus de raison d'ignorer les ordres (même la coupure d'urgence) pendant 30 min.
        if ($isAwake) {
            if ($wakeupFailed == 1 || $wakeupPending == 1) {
                $eqLogic->setCache('wakeup_failed', 0);
                $eqLogic->setCache('wakeup_pending', 0);
            }
            $wakeupFailed = 0;
            $wakeupPending = 0;
        }

        if ($wakeupFailed == 1) {
            $elapsed = time() - $lastWakeupTime;
            if ($elapsed < 1800) {
                $logMsg = "[SECURITE] Ignore pdt ".(1800 - $elapsed)."s.";
                $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($logMsg, 0, 127, 'UTF-8'));
                log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                return;
            } else {
                $eqLogic->setCache('wakeup_failed', 0); 
            }
        }

        if ($wakeupPending == 1) {
            $elapsed = time() - $lastWakeupTime;
            if ($isAwake) {
                $eqLogic->setCache('wakeup_pending', 0);
            } else {
                if ($elapsed < 150) {
                    $lastRefresh = $eqLogic->getCache('last_refresh_time', 0);
                    if (time() - $lastRefresh >= 30) {
                        if (is_object($cmdRefresh)) {
                            self::sendOrder($eqLogic, $cmdRefresh, null, "Ordre de REFRESH (Actualisation API) envoyé.", 'info');
                        }
                        $eqLogic->setCache('last_refresh_time', time());
                    }
                    $logMsg = "[ATTENTE API] Interrogation Tesla en cours ({$elapsed}s/150s).";
                    $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($logMsg, 0, 127, 'UTF-8'));
                    log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                    return;
                } else {
                    $eqLogic->setCache('wakeup_failed', 1);
                    $eqLogic->setCache('wakeup_pending', 0);
                    $eqLogic->setCache('last_wakeup_time', time());
                    $logMsg = "[ECHEC] Tesla injoignable. Pause 30m.";
                    $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($logMsg, 0, 127, 'UTF-8'));
                    log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                    return;
                }
            }
        }

        // Priorité « maison » : sous le seuil de SOC, la batterie passe d'abord, donc sa puissance de charge n'est pas offerte à la voiture.
        // La voiture n'attend JAMAIS un niveau de batterie : elle prend tout ce que la batterie n'absorbe pas (l'injection) et charge dès
        // qu'il y en a assez (2 A). Au-dessus du seuil, ou en priorité « voiture », la charge de la batterie compte aussi dans son surplus.
        $batterieDabord = ($priorite == 'maison' && $niveauBatMaison < $seuilBat);
        // La décharge de la batterie compte toujours (la voiture ne doit pas être alimentée par la batterie, même en priorité « maison »).
        $apportBatterie = $batterieDabord ? min(0, $puissanceBatMaison) : $puissanceBatMaison;
        $surplusBrut = $apportBatterie - $valeurHabitation - $margeVal;
        $puissanceTotaleAllouable = $surplusBrut + $puissancePince;

        $ampereParfait = floor($puissanceTotaleAllouable / 230);
        if ($ampereParfait > 32) $ampereParfait = 32;
        if ($ampereParfait < 2) $ampereParfait = 0;

        // Dernière consigne envoyée : tant que la Tesla ne l'a pas répercutée (le courant lu est rafraîchi tard),
        // on ne la renvoie pas (quota d'API). Elle est oubliée dès que le courant lu la rejoint, ou après 120 s.
        $lastCmdAmp = (int) $eqLogic->getCache('last_cmd_amp', -1);
        $lastCmdTime = (int) $eqLogic->getCache('last_cmd_time', 0);
        if ($lastCmdAmp >= 0 && (int) $currentAmperage === $lastCmdAmp) {
            $eqLogic->setCache('last_cmd_amp', -1);
            $lastCmdAmp = -1;
        }
        $ordreEnAttente = ($lastCmdAmp >= 0 && (time() - $lastCmdTime) < 120 && (int) $ampereParfait === $lastCmdAmp);

        $logMsg = "";

        // (plus d'arrêt ni d'attente sur le niveau de batterie : voir $batterieDabord plus haut)
        {
            $actionUrgente = false;
            if ($puissanceBatMaison < -50 && $ampereParfait < $currentAmperage) {
                $actionUrgente = true;
            }

            if ($ordreEnAttente) {
                $logMsg = "[ATTENTE RETOUR] {$ampereParfait}A déjà demandés, attente de la Tesla.";
                $eqLogic->setCache('action_pending_since', 0);
                $eqLogic->setCache('action_direction', 0);
            }
            elseif ($actionUrgente) {
                $logMsg = "[URGENCE] Maison se vide. Act: {$ampereParfait}A.";
                $eqLogic->setCache('action_pending_since', 0);

                if ($ampereParfait == 0) {
                    if (is_object($cmdControlStr)) {
                        if (self::sendOrder($eqLogic, $cmdControlStr, array('select' => '0'), "Envoi de l'ordre d'ARRÊT de la charge (0).")) {
                            self::rememberOrder($eqLogic, 0);
                        }
                    }
                } else {
                    if (is_object($cmdAmperageStr)) {
                        if (self::sendOrder($eqLogic, $cmdAmperageStr, array('slider' => (int) $ampereParfait), "Modification de l'ampérage à {$ampereParfait}A.")) {
                            self::rememberOrder($eqLogic, $ampereParfait);
                        }
                    }
                }
            }
            elseif ($ampereParfait == $currentAmperage) {
                if ($currentAmperage == 0) {
                    $logMsg = "[SURVEILLANCE] Manque surplus (".round($puissanceTotaleAllouable)."W).";
                } else {
                    $logMsg = "[STABLE] {$ampereParfait}A.";
                }
                $eqLogic->setCache('action_pending_since', 0); 
                $eqLogic->setCache('action_direction', 0);
            } 
            else {
                $direction = ($ampereParfait > $currentAmperage) ? 1 : -1;
                $oldDirection = $eqLogic->getCache('action_direction', 0);
                $pendingSince = $eqLogic->getCache('action_pending_since', 0);
                
                if ($pendingSince == 0 || $direction != $oldDirection) {
                    $eqLogic->setCache('action_pending_since', time());
                    $eqLogic->setCache('action_direction', $direction);
                    
                    $mouvement = ($direction == 1) ? "HAUSSE" : "BAISSE";
                    if ($ampereParfait == 0) $mouvement = "COUPURE";
                    if ($currentAmperage == 0) $mouvement = "DEMARRAGE";
                    
                    $logMsg = "[DETECT {$mouvement}] Surplus: ".round($puissanceTotaleAllouable)."W. Cible: {$ampereParfait}A. Chrono lancé.";
                } 
                else {
                    $elapsed = time() - $pendingSince;
                    $delaiConfirmation = ($direction == 1) ? self::CONFIRMATION_HAUSSE : self::CONFIRMATION_BAISSE;
                    if ($elapsed < $delaiConfirmation) {
                        $logMsg = "[ATTENTE] Confirmation cible {$ampereParfait}A dans ".($delaiConfirmation - $elapsed)."s.";
                    } 
                    else {
                        $logMsg = "[ACTION] Application: {$ampereParfait}A.";
                        $eqLogic->setCache('action_pending_since', 0); 
                        
                        if ($ampereParfait == 0) {
                            if (is_object($cmdControlStr)) {
                                if (self::sendOrder($eqLogic, $cmdControlStr, array('select' => '0'), "Envoi de l'ordre d'ARRÊT de la charge (0).")) {
                                    self::rememberOrder($eqLogic, 0);
                                }
                            }
                        } else {
                            if (!$isAwake) {
                                $logMsg .= " -> [API TESLA] Envoi ordre réveil.";
                                if (is_object($cmdWakeUpStr)) {
                                    self::sendOrder($eqLogic, $cmdWakeUpStr, array('select' => '1'), "Ordre de RÉVEIL envoyé.");
                                }
                                $eqLogic->setCache('wakeup_pending', 1);
                                $eqLogic->setCache('last_wakeup_time', time());
                                $eqLogic->setCache('last_refresh_time', time());
                            } else {
                                if (!$isCharging) {
                                    if (is_object($cmdControlStr)) {
                                        self::sendOrder($eqLogic, $cmdControlStr, array('select' => '1'), "Envoi de l'ordre de DÉMARRAGE de la charge (1).");
                                    }
                                    sleep(2);
                                }
                                if (is_object($cmdAmperageStr)) {
                                    if (self::sendOrder($eqLogic, $cmdAmperageStr, array('slider' => (int) $ampereParfait), "Modification de l'ampérage à {$ampereParfait}A.")) {
                                        self::rememberOrder($eqLogic, $ampereParfait);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($logMsg, 0, 127, 'UTF-8'));
        if ($logMsg != "") log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
    }

    // ====================================================================
    // LE MOTEUR DE FOND (CRON)
    // ====================================================================
    // Jeedom appelle cron() sans argument (toutes les minutes). $_forceOff et $_onlyEqId servent aux appels internes d'un seul équipement.
    public static function cron($_forceOff = false, $_onlyEqId = null) {

        $minuteActuelle = intval(date('i'));
        $isDebugMode = config::byKey('debugMode', 'teslasmartcharge', 0);
        $forceLog = ($minuteActuelle % 5 == 0) || ($isDebugMode == 1 || $isDebugMode == '1');

        foreach (eqLogic::byType('teslasmartcharge', true) as $eqLogic) {
            unset($puissancePince); // ne pas hériter de la mesure de l'équipement précédent
            if ($_onlyEqId !== null && $eqLogic->getId() != $_onlyEqId) continue;
            if ($eqLogic->getIsEnable() != 1) continue;

            $modeActuelVal = is_object($eqLogic->getCmd(null, 'mode_actuel')) ? $eqLogic->getCmd(null, 'mode_actuel')->execCmd() : '';

            // --- COUPE-CIRCUIT ABSOLU : MODE NON ACTIF ---
            if ($modeActuelVal == 'Non actif') {
                continue; // Le plugin est totalement désactivé pour cet équipement.
            }

            // Mode vide ou inconnu : on ne touche à rien (sinon une charge manuelle serait coupée chaque minute).
            if (!in_array($modeActuelVal, array('Solaire', 'Planifié', 'Stand-By'), true)) {
                continue;
            }

            $teslaEqId = $eqLogic->getConfiguration('tesla_eqlogic_id');
            if ($teslaEqId == '') continue;
            $teslaEq = eqLogic::byId($teslaEqId);
            if (!is_object($teslaEq)) continue;

            // Détails de charge (énergie ajoutée, vitesse) : mis à jour dans tous les modes, y compris Solaire.
            $cmdDetails = $teslaEq->getCmd(null, 'DETAILS');
            if (is_object($cmdDetails)) {
                $rawJsonVal = $cmdDetails->execCmd();
                if (preg_match('/\{.*\}/s', (string) $rawJsonVal, $matches)) {
                    $data = json_decode($matches[0], true);
                    if (is_array($data)) {
                        if (isset($data['charge_energy_added'])) $eqLogic->checkAndUpdateCmd('energie_ajoutee', floatval($data['charge_energy_added']));
                        if (isset($data['charge_rate'])) $eqLogic->checkAndUpdateCmd('vitesse_charge', floatval($data['charge_rate']));
                    }
                }
            }

            if ($modeActuelVal == 'Solaire') {
                self::solarListener(array('options' => array('eqLogic_id' => $eqLogic->getId())));
                continue;
            }

            log::add('teslasmartcharge', 'debug', "🟢 [LECTURE LOCALE JEEDOM] Vérification des capteurs et états (cache local).");

            $cibleCharge = is_object($eqLogic->getCmd(null, 'cible_charge')) ? floatval($eqLogic->getCmd(null, 'cible_charge')->execCmd()) : 100;
            $cmdBat = $teslaEq->getCmd(null, 'battery_level');
            $batterieVal = is_object($cmdBat) ? floatval($cmdBat->execCmd()) : 0;
            
            $cmdAmps = $teslaEq->getCmd(null, 'charger_actual_current');
            $currentAmperage = is_object($cmdAmps) ? floatval($cmdAmps->execCmd()) : 0;

            $cmdDuration = $teslaEq->getCmd(null, 'charger_expected_duration_min');
            $expectedDuration = is_object($cmdDuration) ? floatval($cmdDuration->execCmd()) : 0;

            $ordreDeCharge = 'stop'; 
            $ampereAEnvoyer = 0;
            $msgStatut = "";

            $cmdCable = $teslaEq->getCmd(null, 'charge_port_latch');
            $isPlugged = false;
            if (is_object($cmdCable)) {
                $val = strtolower((string) $cmdCable->execCmd());
                if (in_array($val, ['engagé', 'engaged', 'connected', 'plugged', '1', 'true'])) $isPlugged = true;
            }

            if ($modeActuelVal == 'Planifié' && !$isPlugged) {
                $eqLogic->checkAndUpdateCmd('mode_actuel', 'Stand-By');
                $modeActuelVal = 'Stand-By'; 
                $eqLogic->setCache('standby_already_logged', 1);
                $logMsg = "[STAND-BY] Voiture débranchée. Bascule auto sur Stand-By.";
                $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($logMsg, 0, 127, 'UTF-8'));
                log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
            }

            if ($modeActuelVal == 'Stand-By' || $_forceOff == true) {
                $msgStatut = 'VEILLE.';

                if ($modeActuelVal == 'Stand-By' && !$_forceOff) {
                    $puissancePince = self::readSensor('infoPuissanceVoiture', 0);
                    if ($puissancePince === null) $puissancePince = 0;

                    if ($puissancePince > 150) {
                        if ($isPlugged) {
                            $eqLogic->checkAndUpdateCmd('mode_actuel', 'Solaire');
                            $logMsg = "[MODE] Rebranchement confirmé ({$puissancePince}W). Bascule auto en Solaire.";
                            $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($logMsg, 0, 127, 'UTF-8'));
                            log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                            
                            self::solarListener(array('options' => array('eqLogic_id' => $eqLogic->getId()), 'force' => true));
                            continue; 
                        } else {
                            $lastRefresh = $eqLogic->getCache('last_reconnect_refresh', 0);
                            if (time() - $lastRefresh > 60) {
                                $cmdRefresh = $teslaEq->getCmd(null, 'refresh');
                                if (is_object($cmdRefresh)) {
                                    self::sendOrder($eqLogic, $cmdRefresh, null, "Ordre de REFRESH (Vérification branchement).", 'info');
                                    $eqLogic->setCache('last_reconnect_refresh', time());
                                }
                                $logMsg = "[API TESLA] Conso détectée ({$puissancePince}W). Actualisation Tesla...";
                                $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($logMsg, 0, 127, 'UTF-8'));
                                log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                            }
                            continue; 
                        }
                    }
                }
            }
            else if ($modeActuelVal == 'Planifié') {
                $eqLogic->setCache('standby_already_logged', 0);

                $datePlanifieeVal = is_object($eqLogic->getCmd(null, 'date_planifiee')) ? $eqLogic->getCmd(null, 'date_planifiee')->execCmd() : '';
                $targetTime = strtotime((string) $datePlanifieeVal);

                if ($batterieVal >= $cibleCharge || ($currentAmperage > 0 && $expectedDuration == 0) || !$targetTime || $targetTime <= time()) {
                    $ordreDeCharge = 'stop';
                    $eqLogic->checkAndUpdateCmd('mode_actuel', 'Stand-By');
                    $modeActuelVal = 'Stand-By'; 
                    $eqLogic->setCache('standby_already_logged', 0); 
                    if ($targetTime && $targetTime <= time() && $batterieVal < $cibleCharge) {
                        $msg = "[ERREUR] Date limite dépassée. Le mode Planifié a été annulé.";
                        $eqLogic->checkAndUpdateCmd('statut_action', $msg);
                    }
                } else {
                    $now = time();
                    $minutes_available = max(1, round(($targetTime - $now) / 60));
                    
                    $hc_minutes_available = self::hcMinutesBetween($now, $targetTime);
                    $isHC_Now = self::isHcAt(intval(date('H', $now)) * 60 + intval(date('i', $now)));

                    if ($currentAmperage > 0 && $expectedDuration > 0) {
                        $ampRequiseHC = ($hc_minutes_available > 0) ? ceil(($expectedDuration * $currentAmperage) / $hc_minutes_available) : 999;
                        $ampRequiseGlobale = ceil(($expectedDuration * $currentAmperage) / $minutes_available);
                    } else {
                        $ampRequiseHC = ($hc_minutes_available > 0) ? 32 : 999;
                        $ampRequiseGlobale = 32;
                    }

                    $cachedAmpHC = $eqLogic->getCache('last_planned_amp_hc', 0);
                    if ($cachedAmpHC > 0 && abs($ampRequiseHC - $cachedAmpHC) <= 1) {
                        $ampRequiseHC = $cachedAmpHC; 
                    } else {
                        $eqLogic->setCache('last_planned_amp_hc', $ampRequiseHC); 
                    }

                    if ($ampRequiseHC <= 32) {
                        $ampOptimiseeHC = ($ampRequiseHC <= 20) ? 20 : $ampRequiseHC;
                        if ($isHC_Now) {
                            $ampereAEnvoyer = $ampOptimiseeHC;
                            $ordreDeCharge = 'start';
                            $msgStatut = "Lissé: {$ampOptimiseeHC}A.";
                        } else {
                            $ampereAEnvoyer = 0;
                            $ordreDeCharge = 'stop';
                            $msgStatut = "Attente HC.";
                        }
                    } else {
                        if ($isHC_Now) {
                            $ampereAEnvoyer = 32;
                            $ordreDeCharge = 'start';
                            $msgStatut = "Max: 32A (Retard).";
                        } else {
                            $ampereAEnvoyer = ($ampRequiseGlobale > 32) ? 32 : max(2, $ampRequiseGlobale);
                            $ordreDeCharge = 'start';
                            $msgStatut = "Urgence HP: {$ampereAEnvoyer}A.";
                        }
                    }
                }
            }

            $cmdAwake = $teslaEq->getCmd(null, 'is_online');
            $cmdWakeUpStr = $teslaEq->getCmd(null, 'wakeup');
            $cmdAmperageStr = $teslaEq->getCmd(null, 'charge_amps');
            $cmdControlStr = $teslaEq->getCmd(null, 'charge_onoff');
            $cmdRefresh = $teslaEq->getCmd(null, 'refresh');

            $isAwake = false;
            if (is_object($cmdAwake)) {
                $val = strtolower((string) $cmdAwake->execCmd());
                if (in_array($val, ['1', 'true', 'online', 'awake'])) $isAwake = true;
            }

            if (!isset($puissancePince)) {
                // variable seulement définie plus haut en mode Stand-By : sans elle, PHP émettait un avertissement à chaque passage
                $puissancePince = self::readSensor('infoPuissanceVoiture', 0);
                if ($puissancePince === null) $puissancePince = 0;
            }
            $isCharging = ($currentAmperage > 0 || $puissancePince > 200);
            $nextCheckStr = date('H:i:s', time() + 60);

            if ($ordreDeCharge == 'start') {
                if ($isAwake) {
                    $eqLogic->setCache('wakeup_pending', 0);
                    $eqLogic->setCache('wakeup_failed', 0);
                    $eqLogic->setCache('last_refresh_time', 0);
                } else {
                    $wakeupPending = $eqLogic->getCache('wakeup_pending', 0);
                    $wakeupFailed = $eqLogic->getCache('wakeup_failed', 0);
                    $lastWakeupTime = $eqLogic->getCache('last_wakeup_time', 0);
                    $timeSinceLastWakeup = time() - $lastWakeupTime;
                    
                    if ($wakeupFailed == 1) {
                        if ($timeSinceLastWakeup < 1800) {
                            if ($forceLog) {
                                $logMsg = "[SECURITE] Ignore pdt ".(1800 - $timeSinceLastWakeup)."s. | Chk: {$nextCheckStr}";
                                $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($logMsg, 0, 127, 'UTF-8'));
                                log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                            }
                            continue;
                        } else {
                            $eqLogic->setCache('wakeup_failed', 0);
                        }
                    }
            
                    if ($wakeupPending == 1) {
                        if ($timeSinceLastWakeup < 150) { 
                            $lastRefreshTime = $eqLogic->getCache('last_refresh_time', 0);
                            if (time() - $lastRefreshTime >= 30) {
                                if (is_object($cmdRefresh)) {
                                    self::sendOrder($eqLogic, $cmdRefresh, null, "Ordre de REFRESH (Actualisation API) envoyé.", 'info');
                                }
                                $eqLogic->setCache('last_refresh_time', time());
                            }
                            $logMsg = "[ATTENTE API] Interrogation Tesla en cours ({$timeSinceLastWakeup}s/150s)";
                            $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($logMsg, 0, 127, 'UTF-8'));
                            log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                            continue;
                        } else {
                            $eqLogic->setCache('wakeup_failed', 1);
                            $eqLogic->setCache('wakeup_pending', 0);
                            $eqLogic->setCache('last_wakeup_time', time());
                            $logMsg = "[ECHEC] Tesla injoignable. Pause 30m. | Chk: {$nextCheckStr}";
                            $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($logMsg, 0, 127, 'UTF-8'));
                            log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                            continue;
                        }
                    }
            
                    $eqLogic->setCache('wakeup_pending', 1);
                    $eqLogic->setCache('last_wakeup_time', time());
                    $eqLogic->setCache('last_refresh_time', time());
                    $logMsg = "[API TESLA] Envoi ordre réveil... | Chk: {$nextCheckStr}";
                    $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($logMsg, 0, 127, 'UTF-8'));
                    log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                    
                    if (is_object($cmdWakeUpStr)) {
                        self::sendOrder($eqLogic, $cmdWakeUpStr, array('select' => '1'), "Ordre de RÉVEIL envoyé.");
                    }
                    continue;
                }

                $startTimeCron = $eqLogic->getCache('starting_sequence_cron', 0);

                if (!$isCharging) {
                    if (time() - $startTimeCron > 180) { 
                        $finalMsg = "[DEMARRAGE] " . strip_tags($msgStatut) . " | Attente API | Chk: {$nextCheckStr}";
                        $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($finalMsg, 0, 127, 'UTF-8'));
                        log::add('teslasmartcharge', 'debug', "STATUT : " . $finalMsg);
                        
                        if (is_object($cmdControlStr)) {
                            self::sendOrder($eqLogic, $cmdControlStr, array('select' => '1'), "Envoi de l'ordre de DÉMARRAGE de la charge (1).");
                        }
                        $eqLogic->setCache('starting_sequence_cron', time());
                        sleep(2);
                        if (is_object($cmdAmperageStr)) {
                            if (self::sendOrder($eqLogic, $cmdAmperageStr, array('slider' => (int) $ampereAEnvoyer), "Modification de l'ampérage à {$ampereAEnvoyer}A.")) {
                                self::rememberOrder($eqLogic, $ampereAEnvoyer);
                            }
                        }
                    } else {
                        $finalMsg = "[DEMARRAGE] " . strip_tags($msgStatut) . " | Commande OK | Chk: {$nextCheckStr}";
                        $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($finalMsg, 0, 127, 'UTF-8'));
                        log::add('teslasmartcharge', 'debug', "STATUT : " . $finalMsg);
                    }
                } else {
                    $eqLogic->setCache('starting_sequence_cron', 0); 
                    
                    $etaStr = ($expectedDuration > 0) ? date('H:i', time() + (int) round($expectedDuration * 60)) : "--:--";
                    if ($batterieVal >= $cibleCharge) $etaStr = "OK";

                    $metrics = " | ".round($puissancePince)."W | Fin: {$etaStr} | Chk: {$nextCheckStr}";

                    // Dernière consigne envoyée : oubliée dès que le courant lu la rejoint ; pas de renvoi pendant 120 s sinon.
                    $lastCmdAmp = (int) $eqLogic->getCache('last_cmd_amp', -1);
                    $lastCmdTime = (int) $eqLogic->getCache('last_cmd_time', 0);
                    if ($lastCmdAmp >= 0 && (int) $currentAmperage === $lastCmdAmp) {
                        $eqLogic->setCache('last_cmd_amp', -1);
                        $lastCmdAmp = -1;
                    }
                    $ordreEnAttente = ($lastCmdAmp >= 0 && (time() - $lastCmdTime) < 120 && (int) $ampereAEnvoyer === $lastCmdAmp);

                    if ($currentAmperage != $ampereAEnvoyer && $ampereAEnvoyer > 0) {
                        $finalMsg = "[AJUST] " . strip_tags($msgStatut) . $metrics;
                        if ($ordreEnAttente) {
                            $finalMsg = "[ATTENTE RETOUR] " . strip_tags($msgStatut) . $metrics;
                        }
                        $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($finalMsg, 0, 127, 'UTF-8'));
                        log::add('teslasmartcharge', 'debug', "STATUT : " . $finalMsg);

                        if (is_object($cmdAmperageStr) && !$ordreEnAttente) {
                            if (self::sendOrder($eqLogic, $cmdAmperageStr, array('slider' => (int) $ampereAEnvoyer), "Modification de l'ampérage à {$ampereAEnvoyer}A.")) {
                                self::rememberOrder($eqLogic, $ampereAEnvoyer);
                            }
                        }
                    } else {
                        if ($forceLog) {
                            $finalMsg = "[STABLE] " . strip_tags($msgStatut) . $metrics;
                            $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($finalMsg, 0, 127, 'UTF-8'));
                            log::add('teslasmartcharge', 'debug', "STATUT : " . $finalMsg);
                        }
                    }
                }
                
            } else {
                if ($isAwake) {
                    if ($isCharging || $_forceOff) {
                        $finalMsg = "[ARRET] Ordre d'arrêt de la charge envoyé.";
                        $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($finalMsg, 0, 127, 'UTF-8'));
                        log::add('teslasmartcharge', 'debug', "STATUT : " . $finalMsg);
                        
                        if (is_object($cmdControlStr)) {
                            if (self::sendOrder($eqLogic, $cmdControlStr, array('select' => '0'), "Envoi de l'ordre d'ARRÊT de la charge (0).")) {
                                self::rememberOrder($eqLogic, 0);
                            }
                        }
                    } else {
                        if ($modeActuelVal == 'Stand-By') {
                            $alreadyLogged = $eqLogic->getCache('standby_already_logged', 0);
                            if ($alreadyLogged == 0) {
                                if ($batterieVal >= $cibleCharge) {
                                    $msg = "[STAND-BY] Cible atteinte ({$cibleCharge}%). Plugin en veille.";
                                } else {
                                    $msg = "[STAND-BY] Voiture arrêtée. Plugin en veille.";
                                }
                                $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($msg, 0, 127, 'UTF-8'));
                                log::add('teslasmartcharge', 'debug', "STATUT : " . $msg);
                                $eqLogic->setCache('standby_already_logged', 1);
                            }
                        } else {
                            if ($forceLog) {
                                $finalMsg = "[VEILLE] " . strip_tags($msgStatut) . " | Bat: {$batterieVal}% | Chk: {$nextCheckStr}";
                                $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($finalMsg, 0, 127, 'UTF-8'));
                                log::add('teslasmartcharge', 'debug', "STATUT : " . $finalMsg);
                            }
                        }
                    }
                } else {
                    if ($modeActuelVal == 'Stand-By') {
                        $alreadyLogged = $eqLogic->getCache('standby_already_logged', 0);
                        if ($alreadyLogged == 0) {
                            if ($batterieVal >= $cibleCharge) {
                                $msg = "[STAND-BY] Cible atteinte ({$cibleCharge}%). Plugin en veille.";
                            } else {
                                $msg = "[STAND-BY] Voiture endormie. Plugin en veille.";
                            }
                            $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($msg, 0, 127, 'UTF-8'));
                            log::add('teslasmartcharge', 'debug', "STATUT : " . $msg);
                            $eqLogic->setCache('standby_already_logged', 1);
                        }
                    } else {
                        if ($forceLog) {
                            $finalMsg = "[DODO] " . strip_tags($msgStatut) . " | Bat: {$batterieVal}% | Chk: {$nextCheckStr}";
                            $eqLogic->checkAndUpdateCmd('statut_action', mb_substr($finalMsg, 0, 127, 'UTF-8'));
                            log::add('teslasmartcharge', 'debug', "STATUT : " . $finalMsg);
                        }
                    }
                }
            }
        }
    }

    public function preRemove() {
        // Supprime le listener de l'équipement (identifiant en entier ou en chaîne) pour ne pas laisser de listener orphelin.
        foreach (array(intval($this->getId()), strval($this->getId())) as $idForm) {
            $l = listener::byClassAndFunction('teslasmartcharge', 'solarListener', array('eqLogic_id' => $idForm));
            if (is_object($l)) {
                $l->remove();
            }
        }
    }

    public function postSave() {
        // Le listener est cherché avec l'identifiant en entier ; un éventuel doublon créé avec l'identifiant en chaîne est supprimé.
        $listener = listener::byClassAndFunction('teslasmartcharge', 'solarListener', array('eqLogic_id' => intval($this->getId())));
        $legacy = listener::byClassAndFunction('teslasmartcharge', 'solarListener', array('eqLogic_id' => strval($this->getId())));
        if (is_object($legacy) && (!is_object($listener) || $legacy->getId() != $listener->getId())) {
            $legacy->remove();
        }
        if (!is_object($listener)) {
            $listener = new listener();
            $listener->setClass('teslasmartcharge');
            $listener->setFunction('solarListener');
        }
        $listener->setOption(array('eqLogic_id' => intval($this->getId())));
        $listener->emptyEvent();

        log::add('teslasmartcharge', 'debug', ">>> Configuration des Listeners de l'équipement " . $this->getName());

        foreach (array('infoHabitation', 'infoBatterieMaison', 'infoPuissanceBatterieMaison', 'infoPuissanceVoiture') as $sensorKey) {
            $sensorCmd = self::cmdFromString(config::byKey($sensorKey, 'teslasmartcharge'));
            if (is_object($sensorCmd)) { $listener->addEvent($sensorCmd->getId()); }
        }

        $listener->save();

        $cmd = $this->getCmd(null, 'api_appels_infos');
        if (!is_object($cmd)) { $cmd = new teslasmartchargeCmd(); $cmd->setLogicalId('api_appels_infos'); $cmd->setEqLogic_id($this->getId()); $cmd->setName('Appels Infos (24h)'); $cmd->setType('info'); $cmd->setSubType('numeric'); $cmd->save(); $cmd->event(0); }

        $cmd = $this->getCmd(null, 'api_appels_ordres');
        if (!is_object($cmd)) { $cmd = new teslasmartchargeCmd(); $cmd->setLogicalId('api_appels_ordres'); $cmd->setEqLogic_id($this->getId()); $cmd->setName('Appels Ordres (24h)'); $cmd->setType('info'); $cmd->setSubType('numeric'); $cmd->save(); $cmd->event(0); }

        $oldCmd1 = $this->getCmd(null, 'heure_planifiee');
        if (is_object($oldCmd1)) $oldCmd1->remove();
        $oldCmd2 = $this->getCmd(null, 'set_heure_planifiee');
        if (is_object($oldCmd2)) $oldCmd2->remove();

        $cmd = $this->getCmd(null, 'priorite_charge');
        if (!is_object($cmd)) { $cmd = new teslasmartchargeCmd(); $cmd->setLogicalId('priorite_charge'); $cmd->setEqLogic_id($this->getId()); $cmd->setName('Priorité Charge'); $cmd->setType('info'); $cmd->setSubType('string'); $cmd->save(); $cmd->event('voiture'); }

        $cmd = $this->getCmd(null, 'set_priorite_charge');
        if (!is_object($cmd)) { $cmd = new teslasmartchargeCmd(); $cmd->setLogicalId('set_priorite_charge'); $cmd->setEqLogic_id($this->getId()); $cmd->setName('Définir Priorité'); $cmd->setType('action'); $cmd->setSubType('select'); $cmd->setConfiguration('listValue', "voiture|1. Voiture / 2. Maison;maison|1. Maison / 2. Voiture"); $cmd->setValue($this->getCmd(null, 'priorite_charge')->getId()); $cmd->save(); }

        $cmd = $this->getCmd(null, 'seuil_batterie_maison');
        if (!is_object($cmd)) { $cmd = new teslasmartchargeCmd(); $cmd->setLogicalId('seuil_batterie_maison'); $cmd->setEqLogic_id($this->getId()); $cmd->setName('Seuil Bat Maison'); $cmd->setType('info'); $cmd->setSubType('numeric'); $cmd->setUnite('%'); $cmd->save(); $cmd->event(80); }

        $cmd = $this->getCmd(null, 'set_seuil_batterie_maison');
        if (!is_object($cmd)) { 
            $cmd = new teslasmartchargeCmd(); 
            $cmd->setLogicalId('set_seuil_batterie_maison'); 
            $cmd->setEqLogic_id($this->getId()); 
        }
        $cmd->setName('Définir Seuil Bat Maison'); 
        $cmd->setType('action'); 
        $cmd->setSubType('slider'); 
        $cmd->setTemplate('dashboard', 'teslasmartcharge::tsc_battery_v');
        $cmd->setTemplate('mobile', 'teslasmartcharge::tsc_battery_v');
        $cmd->setConfiguration('minValue', 0);
        $cmd->setConfiguration('maxValue', 100); 
        $cmd->setConfiguration('step', 1); 
        $cmd->setValue($this->getCmd(null, 'seuil_batterie_maison')->getId()); 
        $cmd->save();

        $cmd = $this->getCmd(null, 'cible_charge');
        if (!is_object($cmd)) { $cmd = new teslasmartchargeCmd(); $cmd->setLogicalId('cible_charge'); $cmd->setEqLogic_id($this->getId()); $cmd->setName('Cible Charge Max'); $cmd->setType('info'); $cmd->setSubType('numeric'); $cmd->setUnite('%'); $cmd->save(); $cmd->event(100); }

        $cmd = $this->getCmd(null, 'set_cible_charge');
        if (!is_object($cmd)) { 
            $cmd = new teslasmartchargeCmd(); 
            $cmd->setLogicalId('set_cible_charge'); 
            $cmd->setEqLogic_id($this->getId()); 
        }
        $cmd->setName('Définir Cible Charge'); 
        $cmd->setType('action'); 
        $cmd->setSubType('slider'); 
        $cmd->setTemplate('dashboard', 'teslasmartcharge::tsc_battery');
        $cmd->setTemplate('mobile', 'teslasmartcharge::tsc_battery');
        $cmd->setConfiguration('minValue', 10); 
        $cmd->setConfiguration('maxValue', 100); 
        $cmd->setConfiguration('step', 1); 
        $cmd->setValue($this->getCmd(null, 'cible_charge')->getId()); 
        $cmd->save();

        $cmd = $this->getCmd(null, 'date_planifiee');
        if (!is_object($cmd)) { 
            $cmd = new teslasmartchargeCmd(); 
            $cmd->setLogicalId('date_planifiee'); 
            $cmd->setEqLogic_id($this->getId()); 
            $cmd->setName('Date Planifiée Info'); 
            $cmd->setType('info'); 
            $cmd->setSubType('string'); 
            $cmd->setIsVisible(0); 
            $cmd->save(); 
            $cmd->event(date('Y-m-d H:i', strtotime('+1 day 07:00'))); 
        }

        $cmd = $this->getCmd(null, 'set_date_planifiee');
        if (!is_object($cmd)) { 
            $cmd = new teslasmartchargeCmd(); 
            $cmd->setLogicalId('set_date_planifiee'); 
            $cmd->setEqLogic_id($this->getId()); 
        }
        $cmd->setName('Prêt le :'); 
        $cmd->setType('action'); 
        $cmd->setSubType('message');
        $cmd->setConfiguration('title_disable', 1);
        $cmd->setTemplate('dashboard', 'teslasmartcharge::tsc_datetime');
        $cmd->setTemplate('mobile', 'teslasmartcharge::tsc_datetime');
        $cmd->setValue($this->getCmd(null, 'date_planifiee')->getId()); 
        $cmd->save(); 

        $cmd = $this->getCmd(null, 'energie_ajoutee');
        if (!is_object($cmd)) { $cmd = new teslasmartchargeCmd(); $cmd->setLogicalId('energie_ajoutee'); $cmd->setEqLogic_id($this->getId()); $cmd->setName('Énergie Ajoutée'); $cmd->setType('info'); $cmd->setSubType('numeric'); $cmd->setUnite('kWh'); $cmd->save(); }

        $cmd = $this->getCmd(null, 'vitesse_charge');
        if (!is_object($cmd)) { $cmd = new teslasmartchargeCmd(); $cmd->setLogicalId('vitesse_charge'); $cmd->setEqLogic_id($this->getId()); $cmd->setName('Vitesse de Charge'); $cmd->setType('info'); $cmd->setSubType('numeric'); $cmd->setUnite('km/h'); $cmd->save(); }

        $cmd = $this->getCmd(null, 'statut_action');
        if (!is_object($cmd)) { $cmd = new teslasmartchargeCmd(); $cmd->setLogicalId('statut_action'); $cmd->setEqLogic_id($this->getId()); $cmd->setName('Statut Détaillé'); $cmd->setType('info'); $cmd->setSubType('string'); $cmd->save(); }

        $cmd = $this->getCmd(null, 'mode_actuel');
        if (!is_object($cmd)) { $cmd = new teslasmartchargeCmd(); $cmd->setLogicalId('mode_actuel'); $cmd->setEqLogic_id($this->getId()); $cmd->setName('Mode Actuel'); $cmd->setType('info'); $cmd->setSubType('string'); $cmd->save(); }

        $cmd = $this->getCmd(null, 'marge_actuelle');
        if (!is_object($cmd)) { $cmd = new teslasmartchargeCmd(); $cmd->setLogicalId('marge_actuelle'); $cmd->setEqLogic_id($this->getId()); $cmd->setName('Marge Actuelle'); $cmd->setType('info'); $cmd->setSubType('numeric'); $cmd->setUnite('W'); $cmd->save(); }

        // --- NOUVELLE LISTE DES MODES ---
        $cmd = $this->getCmd(null, 'set_mode');
        if (!is_object($cmd)) { 
            $cmd = new teslasmartchargeCmd(); 
            $cmd->setLogicalId('set_mode'); 
            $cmd->setEqLogic_id($this->getId()); 
        }
        $cmd->setName('Définir Mode'); 
        $cmd->setType('action'); 
        $cmd->setSubType('select'); 
        $cmd->setConfiguration('listValue', "Solaire|1. Solaire;Planifié|2. Planifié;Stand-By|3. Stand-By;Non actif|4. Non actif"); 
        $cmd->setValue($this->getCmd(null, 'mode_actuel')->getId()); 
        $cmd->save(); 

        $cmd = $this->getCmd(null, 'set_marge');
        if (!is_object($cmd)) { 
            $cmd = new teslasmartchargeCmd(); 
            $cmd->setLogicalId('set_marge'); 
            $cmd->setEqLogic_id($this->getId()); 
        }
        $cmd->setName('Définir Marge'); 
        $cmd->setType('action'); 
        $cmd->setSubType('slider'); 
        $cmd->setTemplate('dashboard', 'teslasmartcharge::tsc_marge');
        $cmd->setTemplate('mobile', 'teslasmartcharge::tsc_marge');
        $cmd->setConfiguration('minValue', -300); 
        $cmd->setConfiguration('maxValue', 300); 
        $cmd->setConfiguration('step', 50); 
        $cmd->setValue($this->getCmd(null, 'marge_actuelle')->getId()); 
        $cmd->save();
    }
}

class teslasmartchargeCmd extends cmd {
    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();

        switch ($this->getLogicalId()) {
            case 'set_priorite_charge':
                $val = isset($_options['select']) ? $_options['select'] : '';
                if (!in_array($val, array('voiture', 'maison'), true)) {
                    log::add('teslasmartcharge', 'warning', "Priorité de charge invalide ignorée : " . (is_scalar($val) ? $val : gettype($val)));
                    break;
                }
                $eqLogic->checkAndUpdateCmd('priorite_charge', $val);
                $logMsg = "[RÉGLAGE] Priorité définie sur : {$val}.";
                $eqLogic->checkAndUpdateCmd('statut_action', $logMsg);
                log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                break;
                
            case 'set_seuil_batterie_maison':
                if (!isset($_options['slider']) || !is_numeric($_options['slider'])) {
                    log::add('teslasmartcharge', 'warning', "Seuil de batterie maison invalide ignoré.");
                    break;
                }
                $val = max(0, min(100, floatval($_options['slider'])));
                $eqLogic->checkAndUpdateCmd('seuil_batterie_maison', $val);
                $logMsg = "[RÉGLAGE] Seuil batterie maison défini à {$val}%.";
                $eqLogic->checkAndUpdateCmd('statut_action', $logMsg);
                log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                break;
                
            case 'set_cible_charge':
                if (!isset($_options['slider']) || !is_numeric($_options['slider'])) {
                    log::add('teslasmartcharge', 'warning', "Cible de charge invalide ignorée.");
                    break;
                }
                $val = max(10, min(100, floatval($_options['slider'])));
                $eqLogic->checkAndUpdateCmd('cible_charge', $val);
                
                $modeActuelVal = is_object($eqLogic->getCmd(null, 'mode_actuel')) ? $eqLogic->getCmd(null, 'mode_actuel')->execCmd() : '';
                if ($modeActuelVal == 'Planifié') {
                    $planMsg = teslasmartcharge::getChargingPlan($eqLogic);
                    $logMsg = "[RÉGLAGE] Cible modifiée à {$val}%. " . $planMsg;
                } else {
                    $logMsg = "[RÉGLAGE] Cible de charge modifiée à {$val}%.";
                }
                
                $eqLogic->checkAndUpdateCmd('statut_action', $logMsg);
                log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                if ($modeActuelVal == 'Planifié') teslasmartcharge::cron(false, $eqLogic->getId());
                break;

            case 'set_date_planifiee':
                $val = isset($_options['message']) ? $_options['message'] : (isset($_options['slider']) ? $_options['slider'] : '');
                $targetTime = strtotime((string) $val);
                
                if ($targetTime) {
                    if ($targetTime <= time()) {
                        $logMsg = "[ERREUR] Date invalide (dans le passé). Saisie ignorée.";
                        $eqLogic->checkAndUpdateCmd('statut_action', $logMsg);
                        log::add('teslasmartcharge', 'warning', "STATUT : " . $logMsg);
                        
                        $modeActuelVal = is_object($eqLogic->getCmd(null, 'mode_actuel')) ? $eqLogic->getCmd(null, 'mode_actuel')->execCmd() : '';
                        if ($modeActuelVal == 'Planifié') {
                            $eqLogic->checkAndUpdateCmd('mode_actuel', 'Stand-By');
                            $logMsgOff = "[ERREUR] Date limite dépassée. Le mode Planifié a été annulé.";
                            $eqLogic->checkAndUpdateCmd('statut_action', $logMsgOff);
                            log::add('teslasmartcharge', 'warning', "STATUT : " . $logMsgOff);
                        }
                    } else {
                        $formattedDate = date('Y-m-d H:i', $targetTime);
                        $eqLogic->checkAndUpdateCmd('date_planifiee', $formattedDate);
                        
                        $modeActuelVal = is_object($eqLogic->getCmd(null, 'mode_actuel')) ? $eqLogic->getCmd(null, 'mode_actuel')->execCmd() : '';
                        if ($modeActuelVal == 'Planifié') {
                            $planMsg = teslasmartcharge::getChargingPlan($eqLogic);
                            $logMsg = "[RÉGLAGE] Objectif au " . date('d/m/Y H\hi', $targetTime) . ". " . $planMsg;
                        } else {
                            $logMsg = "[RÉGLAGE] Objectif planifié au " . date('d/m/Y à H\hi', $targetTime) . ".";
                        }
                        
                        $eqLogic->checkAndUpdateCmd('statut_action', $logMsg);
                        log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                        if ($modeActuelVal == 'Planifié') teslasmartcharge::cron(false, $eqLogic->getId());
                    }
                }
                break;
                
            case 'set_marge':
                $marge = isset($_options['slider']) ? floatval($_options['slider']) : 0;
                $marge = round($marge / 50) * 50; 
                if ($marge < -300) $marge = -300;
                if ($marge > 300) $marge = 300;
                $eqLogic->checkAndUpdateCmd('marge_actuelle', $marge);
                $logMsg = "[RÉGLAGE] Marge définie sur {$marge}W.";
                $eqLogic->checkAndUpdateCmd('statut_action', $logMsg);
                log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                break;
                
            case 'set_mode': 
                $val = isset($_options['select']) ? $_options['select'] : '';
                if (!in_array($val, array('Solaire', 'Planifié', 'Stand-By', 'Non actif'), true)) {
                    log::add('teslasmartcharge', 'warning', "Mode invalide ignoré : " . (is_scalar($val) ? $val : gettype($val)));
                    break;
                }
                $cibleCharge = is_object($eqLogic->getCmd(null, 'cible_charge')) ? floatval($eqLogic->getCmd(null, 'cible_charge')->execCmd()) : 100;

                if ($val == 'Planifié') {
                    $datePlanifieeVal = is_object($eqLogic->getCmd(null, 'date_planifiee')) ? $eqLogic->getCmd(null, 'date_planifiee')->execCmd() : '';
                    $targetTime = strtotime((string) $datePlanifieeVal);
                    
                    if (!$targetTime || $targetTime <= time()) {
                        $eqLogic->checkAndUpdateCmd('mode_actuel', 'Stand-By');
                        $logMsg = "[ERREUR] Date limite dépassée. Le mode Planifié a été annulé.";
                        $eqLogic->checkAndUpdateCmd('statut_action', $logMsg);
                        log::add('teslasmartcharge', 'warning', "STATUT : " . $logMsg);
                        break; 
                    }
                }

                $eqLogic->checkAndUpdateCmd('mode_actuel', $val); 

                if ($val == 'Solaire') {
                    $margeVal = is_object($eqLogic->getCmd(null, 'marge_actuelle')) ? floatval($eqLogic->getCmd(null, 'marge_actuelle')->execCmd()) : 0;
                    $prioVal = is_object($eqLogic->getCmd(null, 'priorite_charge')) ? $eqLogic->getCmd(null, 'priorite_charge')->execCmd() : 'voiture';
                    $seuilVal = is_object($eqLogic->getCmd(null, 'seuil_batterie_maison')) ? floatval($eqLogic->getCmd(null, 'seuil_batterie_maison')->execCmd()) : 80;
                    
                    $logMsg = "[MODE] Solaire activé (Cible: {$cibleCharge}%, Marge: {$margeVal}W, Prio: {$prioVal}, Seuil Bat: {$seuilVal}%).";
                    $eqLogic->checkAndUpdateCmd('statut_action', $logMsg);
                    log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                    teslasmartcharge::solarListener(array('options' => array('eqLogic_id' => $eqLogic->getId()), 'force' => true));
                } 
                elseif ($val == 'Planifié') {
                    $datePlanifieeVal = is_object($eqLogic->getCmd(null, 'date_planifiee')) ? $eqLogic->getCmd(null, 'date_planifiee')->execCmd() : '';
                    $targetTime = strtotime((string) $datePlanifieeVal);
                    $heureStr = date('d/m/Y à H\hi', $targetTime);

                    $planMsg = teslasmartcharge::getChargingPlan($eqLogic);

                    $logMsg = "[MODE] Planifié activé (Cible: {$cibleCharge}%, Prêt le: {$heureStr}). " . $planMsg;
                    $eqLogic->checkAndUpdateCmd('statut_action', $logMsg);
                    log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                    teslasmartcharge::cron(false, $eqLogic->getId());
                } 
                elseif ($val == 'Stand-By') {
                    $logMsg = "[MODE] Bascule manuelle en Stand-By.";
                    $eqLogic->checkAndUpdateCmd('statut_action', $logMsg);
                    log::add('teslasmartcharge', 'debug', "STATUT : " . $logMsg);
                    $eqLogic->setCache('standby_already_logged', 0); 
                    teslasmartcharge::cron(true, $eqLogic->getId());
                }
                elseif ($val == 'Non actif') {
                    $logMsg = "[MODE] Plugin TOTALEMENT DÉSACTIVÉ (Mode manuel). Aucune action ne sera entreprise.";
                    $eqLogic->checkAndUpdateCmd('statut_action', $logMsg);
                    log::add('teslasmartcharge', 'info', "STATUT : " . $logMsg);
                    $eqLogic->setCache('standby_already_logged', 0);
                    $eqLogic->setCache('starting_sequence_cron', 0);
                    $eqLogic->setCache('action_pending_since', 0);
                }
                break;
        }
    }
}