<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}
?>
<form class="form-horizontal">
    <fieldset>
        <legend><i class="fas fa-home"></i> {{Sondes et Capteurs Globaux}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Info : Habitation (W)}}</label>
            <div class="col-sm-3">
                <input type="text" class="configKey form-control" data-l1key="infoHabitation" placeholder="#[Maison][Compteur][Puissance]#"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Info : Batterie Maison (%)}}</label>
            <div class="col-sm-3">
                <input type="text" class="configKey form-control" data-l1key="infoBatterieMaison" placeholder="#[Maison][Batterie][Niveau]#"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Info : Batterie Maison (W)}}</label>
            <div class="col-sm-3">
                <input type="text" class="configKey form-control" data-l1key="infoPuissanceBatterieMaison" placeholder="#[Maison][Batterie][Puissance]#"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Puissance de charge voiture (W)}}</label>
            <div class="col-sm-3">
                <input type="text" class="configKey form-control" data-l1key="infoPuissanceVoiture" placeholder="#[Maison][Pince Tesla][Puissance]#"/>
            </div>
        </div>

        <legend><i class="fas fa-moon"></i> {{Périodes Tarifaires}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Heure de début Heures Creuses}}</label>
            <div class="col-sm-2">
                <input type="text" class="configKey form-control" data-l1key="hc_start" placeholder="22:00"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Heure de fin Heures Creuses}}</label>
            <div class="col-sm-2">
                <input type="text" class="configKey form-control" data-l1key="hc_end" placeholder="06:00"/>
            </div>
        </div>

        <legend><i class="fas fa-bug"></i> {{Débogage et Télémétrie}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Mode Télémétrie Intensive}}</label>
            <div class="col-sm-6">
                <label class="checkbox-inline">
                    <input type="checkbox" class="configKey" data-l1key="debugMode" />
                    <i>{{Activer le Mode Debug : Force l'écriture d'un statut Jeelog chaque minute (au lieu de 5 min en temps normal).}}</i>
                </label>
            </div>
        </div>
    </fieldset>
</form>