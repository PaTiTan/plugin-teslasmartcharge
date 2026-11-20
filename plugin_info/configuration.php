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

        <legend><i class="fas fa-moon"></i> {{Heures creuses}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Plages d'heures creuses}}</label>
            <div class="col-sm-6">
                <div id="tscSlots"></div>
                <a class="btn btn-default btn-sm" id="tscAddSlot"><i class="fas fa-plus-circle"></i> {{Ajouter une plage}}</a>
                <input type="hidden" class="configKey" data-l1key="hc_slots"/>
                <div class="help-block">{{Le mode Planifié répartit la charge sur l'ensemble de ces plages. Le reste du temps est en heures pleines.}}</div>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Utiliser les plages d'energyManager}}</label>
            <div class="col-sm-6">
                <label class="checkbox-inline">
                    <input type="checkbox" class="configKey" data-l1key="hc_use_energy" />
                    <i>{{Si le plugin energyManager est installé et que ses plages sont renseignées, elles remplacent celles ci-dessus.}}</i>
                </label>
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

<script>
(function () {
    var legacy = {
        s: <?php echo json_encode((string) config::byKey('hc_start', 'teslasmartcharge', '22:00')); ?>,
        e: <?php echo json_encode((string) config::byKey('hc_end', 'teslasmartcharge', '06:00')); ?>
    };
    var $store = $('.configKey[data-l1key=hc_slots]');
    var $box = $('#tscSlots');

    function norm(v) { // "22:00", "22h00" ou "2200" -> "22h00"
        var m = String(v).replace(/[:h]/g, '').replace(/\D/g, '');
        m = ('0000' + m).slice(-4);
        return m.slice(0, 2) + 'h' + m.slice(2);
    }
    function sync() {
        var list = [];
        $box.find('.tscSlot').each(function () {
            list.push({ s: $(this).find('.tscS').val().replace('h', ':'), e: $(this).find('.tscE').val().replace('h', ':') });
        });
        $store.val(JSON.stringify(list)).trigger('change');
    }
    function picker($input) {
        $input.clockTimePicker({
            alwaysSelectHoursFirst: true, autosize: false, separator: 'h', precision: 5,
            minimum: '00h00', maximum: '23h59',
            colors: { buttonTextColor: '#0797FF', clockFaceColor: '#EEEEEE', clockInnerCircleTextColor: '#888888',
                clockInnerCircleUnselectableTextColor: '#CCCCCC', clockOuterCircleTextColor: '#000000',
                clockOuterCircleUnselectableTextColor: '#CCCCCC', hoverCircleColor: '#DDDDDD', popupBackgroundColor: '#FFFFFF',
                popupHeaderBackgroundColor: '#0797FF', popupHeaderTextColor: '#FFFFFF', selectorColor: '#0797FF', selectorNumberColor: '#FFFFFF' },
            onChange: function () { sync(); }
        });
    }
    function addRow(s, e) {
        var $r = $('<div class="tscSlot" style="display:flex;gap:8px;align-items:center;margin-bottom:6px">' +
            '<span>{{De}}</span><input type="text" class="tscS form-control" style="width:90px;text-align:center;font-weight:bold">' +
            '<span>{{à}}</span><input type="text" class="tscE form-control" style="width:90px;text-align:center;font-weight:bold">' +
            '<a class="btn btn-danger btn-xs tscDel"><i class="fas fa-trash"></i></a></div>');
        $r.find('.tscS').val(norm(s));
        $r.find('.tscE').val(norm(e));
        $box.append($r);
        picker($r.find('.tscS'));
        picker($r.find('.tscE'));
    }
    function load(v) {
        var list = [];
        try { list = JSON.parse(v) || []; } catch (x) { list = []; }
        if (!list.length) { list = [legacy]; }
        $box.empty();
        list.forEach(function (sl) { addRow(sl.s, sl.e); });
    }
    $('#tscAddSlot').off('click').on('click', function () { addRow('00:00', '06:00'); sync(); });
    $box.off('click', '.tscDel').on('click', '.tscDel', function () { $(this).closest('.tscSlot').remove(); sync(); });

    $.getScript('plugins/teslasmartcharge/core/template/dashboard/jquery-clock-timepicker.min.js', function () {
        var tries = 0;
        var t = setInterval(function () { // attend que Jeedom ait rempli les champs de configuration
            tries++;
            var v = $store.val();
            if (v || tries > 15) { clearInterval(t); load(v || ''); }
        }, 200);
    });
})();
</script>
