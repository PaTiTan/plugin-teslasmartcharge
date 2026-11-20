// Fonction obligatoire pour le Core (évite le crash au chargement de la page)
function printEqLogic(_eqLogic) {
}

// Déclencheur pour actualiser les sous-types quand on change le type d'une commande
$('#table_cmd tbody').on('change', '.cmdAttr[data-l1key=type]', function () {
    jeedom.cmd.changeType($(this).closest('.cmd'), $(this).closest('.cmd').find('.cmdAttr[data-l1key=subType]').value());
});

// Construction du tableau de commandes
function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        var _cmd = {};
    }
    if (!isset(_cmd.configuration)) {
        _cmd.configuration = {};
    }

    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    
    // Colonne 1 : ID caché et Nom (Épuré)
    tr += '<td>';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="id" style="display : none;">';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="name" style="width : 100%;" placeholder="{{Nom de la commande}}">';
    tr += '</td>';

    // Colonne 2 : Type et Sous-Type
    tr += '<td>';
    tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span> ';
    tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>';
    tr += '</td>';

    // Colonne 3 : Paramètres (Unité + Afficher + Historiser côte à côte)
    tr += '<td>';
    if (init(_cmd.type) == 'info' && init(_cmd.subType) == 'numeric') {
        tr += '<input class="cmdAttr form-control tooltips input-sm" data-l1key="unite" style="width: 80px; display: inline-block; margin-right: 15px;" placeholder="{{Unité}}" title="{{Unité}}">';
    }
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/> {{Afficher}}</label> ';
    if (init(_cmd.type) == 'info') {
        tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" /> {{Historiser}}</label>';
    }
    tr += '</td>';

    // Colonne 4 : Actions et Boutons
    tr += '<td>';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction expertModeVisible" data-action="configure"><i class="fas fa-cogs"></i></a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> ';
    }
    tr += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove"></i>';
    tr += '</td>';
    
    tr += '</tr>';

    // Ajout au tableau HTML
    $('#table_cmd tbody').append(tr);
    var trLast = $('#table_cmd tbody tr:last');
    
    // Application des valeurs récupérées depuis le Core
    trLast.setValues(_cmd, '.cmdAttr');

    // Initialisation du type et sous-type
    if (isset(_cmd.type)) {
        trLast.find('.cmdAttr[data-l1key=type]').value(init(_cmd.type));
    }
    jeedom.cmd.changeType(trLast, init(_cmd.subType));
    
    // Initialisation des tooltips (infobulles)
    initTooltips();
}