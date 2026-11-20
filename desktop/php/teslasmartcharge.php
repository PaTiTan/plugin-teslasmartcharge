<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('teslasmartcharge');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i><br><span>{{Ajouter}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i><br><span>{{Configuration}}</span>
            </div>
        </div>
        
        <legend><i class="fas fa-car"></i> {{Mes Chargeurs Tesla}}</legend>
        <div class="eqLogicThumbnailContainer">
            <?php
            foreach ($eqLogics as $eqLogic) {
                $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                echo '<div class="eqLogicDisplayCard cursor '.$opacity.'" data-eqLogic_id="' . $eqLogic->getId() . '">';
                
                $imgPath = $plugin->getPathImgIcon(); 
                $teslaEqId = $eqLogic->getConfiguration('tesla_eqlogic_id');
                
                if ($teslaEqId != '') {
                    $teslaEq = eqLogic::byId($teslaEqId);
                    if (is_object($teslaEq)) {
                        $carImg = $teslaEq->getConfiguration('image');
                        if ($carImg != '') {
                            $imgPath = '/' . ltrim($carImg, '/'); 
                        }
                    }
                }
                
                echo '<img src="' . $imgPath . '" style="max-height: 80px; object-fit: contain; margin-top: 10px;"/><br>';
                echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
                echo '</div>';
            }
            ?>
        </div>
    </div>

    <div class="col-xs-12 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex">
            <span class="input-group-btn">
                <a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
                <a class="btn btn-danger btn-sm eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
            </span>
        </div>
        
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
            <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Equipement}}</a></li>
            <li role="presentation"><a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fas fa-list-alt"></i> {{Commandes}}</a></li>
        </ul>
        
        <div class="tab-content" style="height:calc(100% - 50px);overflow:auto;overflow-x: hidden;">
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <br/>
                <form class="form-horizontal">
                    <fieldset>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Nom de l'équipement}}</label>
                            <div class="col-sm-3">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display : none;" />
                                <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de la configuration}}"/>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label" >{{Objet parent}}</label>
                            <div class="col-sm-3">
                                <select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
                                    <option value="">{{Aucun}}</option>
                                    <?php
                                    foreach (jeeObject::all() as $object) {
                                        echo '<option value="' . $object->getId() . '">' . $object->getName() . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Etat}}</label>
                            <div class="col-sm-3">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked/>{{Activer}}</label>
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked/>{{Visible}}</label>
                            </div>
                        </div>

                        <legend><i class="fas fa-car"></i> {{Configuration Tesla}}</legend>
                        <div class="row">
                            <div class="col-sm-8">
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Véhicule Tesla (Équipement lié)}}</label>
                                    <div class="col-sm-5">
                                        <div class="input-group">
                                            <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="tesla_eqlogic_id" placeholder="{{ID de l'équipement Tesla}}"/>
                                            <span class="input-group-btn">
                                                <a class="btn btn-default cursor" title="Rechercher l'équipement" id="bt_selectTeslaEq"><i class="fas fa-list-alt"></i></a>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Assistant Batterie (Optionnel)}}</label>
                                    <div class="col-sm-8">
                                        <div class="row">
                                            <div class="col-sm-4">
                                                <select id="sel_tesla_model" class="form-control">
                                                    <option value="">-- Modèle --</option>
                                                </select>
                                            </div>
                                            <div class="col-sm-4">
                                                <select id="sel_tesla_version" class="form-control" disabled>
                                                    <option value="">-- Version --</option>
                                                </select>
                                            </div>
                                            <div class="col-sm-4">
                                                <select id="sel_tesla_year" class="form-control" disabled>
                                                    <option value="">-- Année --</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Capacité Totale Batterie (kWh)}}</label>
                                    <div class="col-sm-3">
                                        <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="capaciteBatterie" placeholder="{{Ex: 60}}"/>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-4 text-center">
                                <img id="tesla_car_picture" src="" style="max-width: 100%; max-height: 150px; display: none; filter: drop-shadow(0px 5px 5px rgba(0,0,0,0.5));" />
                            </div>
                        </div>
                    </fieldset>
                </form>
            </div>
            
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <br/>
                <table id="table_cmd" class="table table-bordered table-condensed">
                    <thead>
                        <tr>
                            <th>{{Nom}}</th>
                            <th>{{Type}}</th>
                            <th>{{Paramètres}}</th>
                            <th>{{Action}}</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include_file('desktop', 'teslasmartcharge', 'js', 'teslasmartcharge'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>

<script>
    // --- BASE DE DONNÉES BATTERIE (INJECTÉE) ---
    var teslaBatteryData = {
      "Tesla": {
        "Model 3": {
          "RWD": {
            "2021": { "nominal_kWh": 60, "usable_kWh": 55, "chemistry": "LFP" },
            "2022": { "nominal_kWh": 60, "usable_kWh": 57, "chemistry": "LFP" },
            "2023": { "nominal_kWh": 62, "usable_kWh": 59, "chemistry": "LFP" },
            "2024": { "nominal_kWh": 66, "usable_kWh": 62, "chemistry": "LFP" },
            "2025": { "nominal_kWh": 62, "usable_kWh": 59, "chemistry": "LFP" },
            "2026": { "nominal_kWh": 66, "usable_kWh": 62, "chemistry": "LFP" }
          },
          "Standard Range Plus": {
            "2019": { "nominal_kWh": 54, "usable_kWh": 50, "chemistry": "NCA" },
            "2020": { "nominal_kWh": 54, "usable_kWh": 50, "chemistry": "NCA" },
            "2021": { "nominal_kWh": 55, "usable_kWh": 51, "chemistry": "NCA" }
          },
          "Long Range": {
            "2017": { "nominal_kWh": 75, "usable_kWh": 72, "chemistry": "NCA" },
            "2018": { "nominal_kWh": 75, "usable_kWh": 72, "chemistry": "NCA" },
            "2019": { "nominal_kWh": 75, "usable_kWh": 72, "chemistry": "NCA" },
            "2020": { "nominal_kWh": 78, "usable_kWh": 74, "chemistry": "NCA" },
            "2021": { "nominal_kWh": 82, "usable_kWh": 76, "chemistry": "NCA" },
            "2022": { "nominal_kWh": 82, "usable_kWh": 76, "chemistry": "NCA" },
            "2023": { "nominal_kWh": 82, "usable_kWh": 76, "chemistry": "NCA" },
            "2024": { "nominal_kWh": 82, "usable_kWh": 76, "chemistry": "NCA" },
            "2025": { "nominal_kWh": 78, "usable_kWh": 75, "chemistry": "NCA" },
            "2026": { "nominal_kWh": 82, "usable_kWh": 76, "chemistry": "NCA" }
          },
          "Performance": {
            "2018": { "nominal_kWh": 75, "usable_kWh": 72, "chemistry": "NCA" },
            "2019": { "nominal_kWh": 75, "usable_kWh": 72, "chemistry": "NCA" },
            "2020": { "nominal_kWh": 78, "usable_kWh": 74, "chemistry": "NCA" },
            "2021": { "nominal_kWh": 82, "usable_kWh": 76, "chemistry": "NCA" },
            "2022": { "nominal_kWh": 82, "usable_kWh": 76, "chemistry": "NCA" },
            "2023": { "nominal_kWh": 82, "usable_kWh": 76, "chemistry": "NCA" },
            "2024": { "nominal_kWh": 82, "usable_kWh": 76, "chemistry": "NCA" },
            "2025": { "nominal_kWh": 82, "usable_kWh": 78, "chemistry": "NCA" },
            "2026": { "nominal_kWh": 82, "usable_kWh": 78, "chemistry": "NCA" }
          }
        },
        "Model Y": {
          "RWD": {
            "2022": { "nominal_kWh": 60, "usable_kWh": 57, "chemistry": "LFP" },
            "2023": { "nominal_kWh": 62, "usable_kWh": 59, "chemistry": "LFP" },
            "2024": { "nominal_kWh": 64, "usable_kWh": 60, "chemistry": "LFP" },
            "2025": { "nominal_kWh": 62, "usable_kWh": 59, "chemistry": "LFP" },
            "2026": { "nominal_kWh": 66, "usable_kWh": 62, "chemistry": "LFP" }
          },
          "Long Range": {
            "2020": { "nominal_kWh": 75, "usable_kWh": 72, "chemistry": "NCA" },
            "2021": { "nominal_kWh": 78, "usable_kWh": 74, "chemistry": "NCA" },
            "2022": { "nominal_kWh": 82, "usable_kWh": 76, "chemistry": "NCA" },
            "2023": { "nominal_kWh": 82, "usable_kWh": 76, "chemistry": "NCA" },
            "2024": { "nominal_kWh": 80, "usable_kWh": 75, "chemistry": "NCA" },
            "2025": { "nominal_kWh": 80, "usable_kWh": 75, "chemistry": "NCA" },
            "2026": { "nominal_kWh": 80, "usable_kWh": 75, "chemistry": "NCA" }
          },
          "Performance": {
            "2020": { "nominal_kWh": 75, "usable_kWh": 72, "chemistry": "NCA" },
            "2021": { "nominal_kWh": 78, "usable_kWh": 74, "chemistry": "NCA" },
            "2022": { "nominal_kWh": 82, "usable_kWh": 76, "chemistry": "NCA" },
            "2023": { "nominal_kWh": 82, "usable_kWh": 76, "chemistry": "NCA" },
            "2024": { "nominal_kWh": 82, "usable_kWh": 78, "chemistry": "NCA" },
            "2025": { "nominal_kWh": 81, "usable_kWh": 78, "chemistry": "NCA" },
            "2026": { "nominal_kWh": 82, "usable_kWh": 79, "chemistry": "NCA" }
          }
        },
        "Model S": {
          "Standard / Legacy": {
            "2012": { "nominal_kWh": 60, "usable_kWh": 55 },
            "2013": { "nominal_kWh": 60, "usable_kWh": 55 },
            "2014": { "nominal_kWh": 60, "usable_kWh": 55 }
          },
          "Long Range": {
            "2015": { "nominal_kWh": 70, "usable_kWh": 65 },
            "2016": { "nominal_kWh": 75, "usable_kWh": 70 },
            "2017": { "nominal_kWh": 75, "usable_kWh": 70 },
            "2018": { "nominal_kWh": 75, "usable_kWh": 70 },
            "2019": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2020": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2021": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2022": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2023": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2024": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2025": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2026": { "nominal_kWh": 100, "usable_kWh": 95 }
          },
          "Performance / Plaid": {
            "2016": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2017": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2018": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2019": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2020": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2021": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2022": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2023": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2024": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2025": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2026": { "nominal_kWh": 100, "usable_kWh": 95 }
          }
        },
        "Model X": {
          "Long Range": {
            "2016": { "nominal_kWh": 75, "usable_kWh": 70 },
            "2017": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2018": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2019": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2020": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2021": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2022": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2023": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2024": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2025": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2026": { "nominal_kWh": 100, "usable_kWh": 95 }
          },
          "Performance / Plaid": {
            "2016": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2017": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2018": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2019": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2020": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2021": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2022": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2023": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2024": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2025": { "nominal_kWh": 100, "usable_kWh": 95 },
            "2026": { "nominal_kWh": 100, "usable_kWh": 95 }
          }
        },
        "Cybertruck": {
          "AWD": {
            "2024": { "nominal_kWh": 123, "usable_kWh": 118, "chemistry": "NMC" },
            "2025": { "nominal_kWh": 123, "usable_kWh": 118, "chemistry": "NMC" },
            "2026": { "nominal_kWh": 123, "usable_kWh": 118, "chemistry": "NMC" }
          },
          "Cyberbeast": {
            "2024": { "nominal_kWh": 180, "usable_kWh": 170, "chemistry": "NMC" },
            "2025": { "nominal_kWh": 180, "usable_kWh": 170, "chemistry": "NMC" },
            "2026": { "nominal_kWh": 180, "usable_kWh": 170, "chemistry": "NMC" }
          }
        }
      }
    };

    // Initialisation du Modèle
    $.each(teslaBatteryData.Tesla, function(model, data) {
        $('#sel_tesla_model').append('<option value="' + model + '">' + model + '</option>');
    });

    // Écouteur sur Modèle
    $('#sel_tesla_model').on('change', function() {
        var model = $(this).val();
        $('#sel_tesla_version').empty().append('<option value="">-- Version --</option>').prop('disabled', true);
        $('#sel_tesla_year').empty().append('<option value="">-- Année --</option>').prop('disabled', true);
        
        if (model && teslaBatteryData.Tesla[model]) {
            $('#sel_tesla_version').prop('disabled', false);
            $.each(teslaBatteryData.Tesla[model], function(version, data) {
                $('#sel_tesla_version').append('<option value="' + version + '">' + version + '</option>');
            });
        }
    });

    // Écouteur sur Version
    $('#sel_tesla_version').on('change', function() {
        var model = $('#sel_tesla_model').val();
        var version = $(this).val();
        $('#sel_tesla_year').empty().append('<option value="">-- Année --</option>').prop('disabled', true);
        
        if (model && version && teslaBatteryData.Tesla[model][version]) {
            $('#sel_tesla_year').prop('disabled', false);
            $.each(teslaBatteryData.Tesla[model][version], function(year, data) {
                $('#sel_tesla_year').append('<option value="' + year + '">' + year + '</option>');
            });
        }
    });

    // Écouteur sur Année (Action d'écriture automatique)
    $('#sel_tesla_year').on('change', function() {
        var model = $('#sel_tesla_model').val();
        var version = $('#sel_tesla_version').val();
        var year = $(this).val();
        
        if (model && version && year && teslaBatteryData.Tesla[model][version][year]) {
            var usableKwh = teslaBatteryData.Tesla[model][version][year].usable_kWh;
            if (usableKwh) {
                $('.eqLogicAttr[data-l1key=configuration][data-l2key=capaciteBatterie]').val(usableKwh).trigger('change');
            }
        }
    });

    $('.listCmdActionOther').on('click', function() {
        var inputField = $(this).closest('.input-group').find('input');
        var typeCmd = $(this).attr('data-type'); 
        jeedom.cmd.getSelectModal({cmd: {type: typeCmd}}, function (result) {
            inputField.val(result.human);
        });
    });

    function updateTeslaImage() {
        var eqId = $('.eqLogicAttr[data-l1key=configuration][data-l2key=tesla_eqlogic_id]').val();
        if (eqId != '') {
            jeedom.eqLogic.byId({
                id: eqId,
                success: function(result) {
                    if (result && result.configuration && result.configuration.image) {
                        var imgPath = result.configuration.image;
                        if (!imgPath.startsWith('/')) imgPath = '/' + imgPath; 
                        $('#tesla_car_picture').attr('src', imgPath).fadeIn();
                    } else {
                        $('#tesla_car_picture').hide();
                    }
                }
            });
        } else {
            $('#tesla_car_picture').hide();
        }
    }

    $('#bt_selectTeslaEq').on('click', function() {
        jeedom.eqLogic.getSelectModal({}, function (result) {
            $('.eqLogicAttr[data-l1key=configuration][data-l2key=tesla_eqlogic_id]').val(result.id);
            updateTeslaImage(); 
        });
    });

    $('.eqLogicAttr[data-l1key=configuration][data-l2key=tesla_eqlogic_id]').on('change', function() {
        updateTeslaImage();
    });

    var originalPrintEqLogic = printEqLogic;
    printEqLogic = function(_eqLogic) {
        originalPrintEqLogic(_eqLogic);
        setTimeout(function() {
            updateTeslaImage();
        }, 200); 
    };
</script>