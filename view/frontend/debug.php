<?php
ob_start();
/**
 * l'accès à ce fichier via le web doit être restreint via un .htaccess password
 * ex :
AuthType Basic
AuthName "Password Protected Area"
AuthUserFile /path/to/.htpasswd
Require valid-user
 */

use \LundiMatin\EDI\LmbEdi;

if (!class_exists("\LundiMatin\EDI\LmbEdi\LmbEdi")) {
    die("Module non installé ou non activé !");
}

LmbEdi\LmbEdi::instance();

$auth = false;

if (isset($params["serial_code"]) && $params["serial_code"] == \lmbedi_config::CODE_CONNECTION()){
    $auth = true;
}
else {
    $auth = !empty($_SESSION["edi_auth"]);
}

if (!$auth){
    ?>
	
<form method="GET" action="">
    <label for="serial_code">Password :</>
    <input id ="serial_code" name="serial_code" /><br/>
    <input type="submit" value="Valider" name="submit"/>
</form>
<?php
}else{

$_SESSION["edi_auth"] = true;

if (!empty($params['get_config'])) {
    header('Content-type: text/xml');
    echo file_get_contents('config.xml');
    die();
}

$memory = ini_get('memory_limit');
$alertes[] = 'Memory limit : ' . $memory;

//if (!function_exists('set_time_limit'))
//    $alertes[] = 'set_time_limit() n'existe pas !';
//if (!@ini_set('memory_limit', '128M'))
//    $alertes[] = 'memory_limit ne peut être modifié (' . @ini_get('memory_limit') . ')';
//if (!@ini_set('max_execution_time', '60'))
//    $alertes[] = 'max_execution_time ne peut être modifié (' . @ini_get('max_execution_time') . ')';
//if (!@ini_set('max_input_time', '60'))
//    $alertes[] = 'max_input_time ne peut être modifié (' . @ini_get('max_input_time') . ')';
//@ini_set('memory_limit', 1000000000);
//script url
$url = explode('?', \Magento\Framework\App\ObjectManager::getInstance()
                        ->get('Magento\Framework\UrlInterface')->getCurrentUrl()
        )[0];



$bdd = LmbEdi\LmbEdi::getBDD();
if (isset($_POST['action']) && isset($_POST['id'])) {
    $action = $_POST['action'];
    $id = $_POST['id'];
    if ($id == "__system__") {
        $file = LMBEDI_LOG_DIR . '/../system.log';
    }
    else {
        $file = LMBEDI_LOG_DIR . '/' . $id;        
    }
    $answer = array(
        'value' => 'error',
        'content' => ''
    );
    switch ($action) {
        case 'purge':
            @file_put_contents($file, '');
            $answer['value'] = 'success';
            break;
        case 'refresh':
            $content = @file_get_contents($file, false, null, -100000);
            $answer['value'] = 'success';
            $answer['content'] = '<pre>' . $content . '</pre>';
            break;
        default:
            break;
    }
    exit(json_encode($answer));
}
if (isset($params['purge_events'])) {
    $query = 'TRUNCATE TABLE `' . LmbEdi\LmbEdi::getPrefix() . 'edi_events_queue`;';
    $bdd->exec($query);
}
if (isset($params['purge_mess_recu'])) {
    $query = 'TRUNCATE TABLE `' . LmbEdi\LmbEdi::getPrefix() . 'edi_messages_recu_queue`;';
    $bdd->exec($query);
}
if (isset($params['purge_mess_envoi'])) {
    $query = 'TRUNCATE TABLE `' . LmbEdi\LmbEdi::getPrefix() . 'edi_messages_envoi_queue`;';
    $bdd->exec($query);
}
if (isset($params['unlock_pid'])) {
    $pid = new lmbedi_pid($params['unlock_pid']);
    $pid->unsetPid();
}
if (isset($params['clear_logs'])) {
    $log_dir = opendir(LMBEDI_LOG_DIR . '/');
    while ($file = readdir($log_dir)) {
        if ($file != '.' && $file != '..' && strpos($file, '.') !== 0)
            unlink(LMBEDI_LOG_DIR . '/' . $file);
    }
}
if (isset($params['stop_process'])) {
    $pid = new lmbedi_pid('stop');
    if ($params['stop_process'] == true)
        $pid->setPid();
    else
        $pid->deletePid();
}

if (isset($params['start_process'])) {
	$tentative = 0;
    while (!LmbEdi\new_process('envoi', \lmbedi_message_envoi::$process)) {
        sleep(2);
        if ($tentative++ > 3) {
            LmbEdi\trace_error('create_process', "Le process d'envoi n'a pas pu être relancé après 3 tentatives");
			break;
        }
    }
	$tentative = 0;
    while (!LmbEdi\new_process('event', \lmbedi_event::$process)) {
        sleep(2);
        if ($tentative++ > 3) {
            LmbEdi\trace_error('create_process', "Le process d'event n'a pas pu être relancé après 3 tentatives");
			break;
        }
    }
	$tentative = 0;
    while (!LmbEdi\new_process('reception', lmbedi_message_recu::$process)) {
        sleep(2);
        if ($tentative++ > 3) {
            LmbEdi\trace_error('create_process', "Le process de reception n'a pas pu être relancé après 3 tentatives");
			break;
        }
    }
}

if (isset($params['change_etat'])) {
    $table_name = false;
    switch ($params['change_etat']) {
        case lmbedi_message_envoi::$process:
            $table_name = LmbEdi\LmbEdi::getPrefix() . 'edi_messages_envoi_queue';
            break;
        case lmbedi_message_recu::$process:
            $table_name = LmbEdi\LmbEdi::getPrefix() . 'edi_messages_recu_queue';
            break;
        case lmbedi_event::$process:
            $table_name = LmbEdi\LmbEdi::getPrefix() . 'edi_events_queue';
            break;
    }
    if ($table_name) {
        if (!empty($params['all_error'])) {
            $query = "UPDATE $table_name SET etat='0' WHERE etat='9'";
            $bdd->exec($query);
        } else {
            $query = "UPDATE $table_name SET etat='" . $params['etat_mess'] . "' WHERE id='" . $params['id_mess'] . "'";
            $bdd->exec($query);
        }
    }
}

$params = \Magento\Framework\App\ObjectManager::getInstance()
                ->get('Magento\Framework\App\RequestInterface')->getParams();

// 302 => methode obtenue après le " Location ... " header
if (!empty($_GET) && 302 == $_SERVER['REQUEST_METHOD']) {
    header('Location: ' . $url); // 302 redirect
}
?>
<html> 
    <head>
        <meta charset="utf-8">
        <style type="text/css">
            *{
                transition: background-color 0.2s ease-out, color 0.2s ease-out, opacity 0.2s ease-out;;
            }

            input, a{opacity:0.7;}

            input:hover, a:hover{opacity:1;}

            body{
                background-color: rgba(0, 0, 100, 0.06);
            }
            table {
                margin: auto;
                border: thin solid #6495ed;
                border-collapse: collapse;
            }

            table, .log_content{color : #300;}

            th {
                font-family: monospace;
                border: thin solid #6495ed;
                padding: 5px;
                background-color: #D0E3FA;
            }
            table.pids tr td:first-child{
                font-weight: bold;
            }

            .log {
                font-family: monospace;
                border: thin solid #6495ed;
                padding: 10px;
                background-color: #D0E3FA;
            }		

            .log2 {
                font-family: monospace;
                padding: 10px;
                background-color: #D0E3FA;
            }

            td {
                font-family: monospace;
                border: thin solid #6495ed;
                padding: 5px;
                text-align: left;
                background-color: #ffffff;
            }
            caption {
                font-family: sans-serif;
            }	
            .tdaligncenter{
                font-family: monospace;
                border: thin solid #6495ed;
                padding: 5px;
                text-align: center;
                background-color: #ffffff;
            }

            .bouton {
                font-family: Arial,sans-serif; 
                font-size: 0.9em; 
                width: 30px; 
                height: 30px; 
                padding-top: 1px; /*permet le centrage vertical*/ 
                text-align: center; 
                color: #FFF; 
                background: #6495ed;
                border-radius: 8px;
                text-shadow: 0px 1px 0px rgba( 255, 255, 255, 0.2);
            }		

            .btlog {
                font-family: Arial,sans-serif; 
                width: 220px; 
                height: 30px; 
                padding-top: 5px; /*permet le centrage vertical*/ 
                text-align: center; 
                color: #FFF; 
                background: #6495ed;
                border-radius: 8px;
                text-shadow: 0px 1px 0px rgba( 0, 0, 0, 0.2);
            }

            .btlog2 {
                font-family: Arial,sans-serif; 
                font-size: 0.8em; 
                padding-top: 3px; /*permet le centrage vertical*/
                width: 30px; 
                height: 30px; 
                text-align: center; 
                color: #FFF; 
                background: #6495ed;
                border-radius: 8px;
                text-shadow: 0px 1px 0px rgba( 0, 0, 0, 0.2);
            }

            .infosmodule {
                font-family: Arial,sans-serif; 
                font-size: 0.6em; 
                width: 80px; 
                height: 15px; 
                padding-top: 5px; /*permet le centrage vertical*/ 
                text-align: center; 
                color: #FFF; 
                background: #6495ed;
                border-radius: 8px;
                text-shadow: 0px 1px 0px rgba( 0, 0, 0, 0.2);
            }			

            .btpurge {
                font-family: Arial,sans-serif; 
                width: 30px; 
                height: 30px; 
                padding-top: 5px; /*permet le centrage vertical*/ 
                text-align: center; 
                color: #FFF; 
                background: red;
                border-radius: 8px;
                text-shadow: 0px 1px 0px rgba( 0, 0, 0, 0.2);
            }		

            .btclearlogs {
                font-family: Arial,sans-serif; 
                width: 120px; 
                height: 30px; 
                padding-top: 5px; /*permet le centrage vertical*/ 
                text-align: center; 
                color: #FFF; 
                background: #6495ed;
                border-radius: 8px;
                text-shadow: 0px 1px 0px rgba( 0, 0, 0, 0.2);
            }		

            .btrefresh {
                font-family: Arial,sans-serif; 
                width: 120px; 
                height: 30px; 
                padding-top: 5px; /*permet le centrage vertical*/ 
                text-align: center; 
                color: #FFF; 
                background: #6495ed;
                border-radius: 8px;
                text-shadow: 0px 1px 0px rgba( 0, 0, 0, 0.2);
            }       


            .btarbre {
                font-family: Arial,sans-serif; 
                font-size: 0.9em; 
                width: 20px; 
                height: 20px; 
                padding-top: 1px; /*permet le centrage vertical*/ 
                text-align: center; 
                color: #FFF; 
                background: #6495ed;
                border-radius: 9px;
                text-shadow: 0px 1px 0px rgba( 255, 255, 255, 0.2);
            }

            .divlog{
                height: 200px; 
                overflow: auto; 
                border: thin solid #6495ed;
                display:none;
                background-color: #D0E3FA;
            }

            .afflog{
                font-family: monospace;
                padding: 5px;
                background-color: #D0E3FA;
            }

            input:active {
                background-color: #545dd3;
            }  
			
			.lienPratique{
				text-decoration: none;
				font-size: 1.3em; 
			}
			
            /*
             * 0,1,9 correspond aux constantes ETAT_REPOS = 0, ETAT_RUN = 1, ETAT_ERROR = 9  de pid.php
             */
            /*etat normal*/
            .etat0{color:green;font-weight: bold;}
            /*processing*/
            .etat1{color:blue;}
            /*error/alert*/
            .etat9{color:orangered;}

        </style>
        <script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
    </head>
    <body>
        <h1 style="text-align: center; display: block;"><a href="<?php echo $url; ?>">Etat du module</a></h1>
        <table border=1 cellpadding="5" style = 'width:80%'>

            <?php
            $table_name_me = LmbEdi\LmbEdi::getPrefix() . "edi_messages_envoi_queue";
            $query_me = "SELECT COUNT(id) nb FROM $table_name_me WHERE etat = " . lmbedi_queue::OK_CODE;
            $resultset_me = $bdd->query($query_me);
            $result_me = $resultset_me->fetchObject();
            $nombre['envoie'] = $result_me ? $result_me->nb : 0;

            $table_name_mr = LmbEdi\LmbEdi::getPrefix() . "edi_messages_recu_queue";
            $query_mr = "SELECT COUNT(id) nb FROM $table_name_mr WHERE etat = " . lmbedi_queue::OK_CODE;
            $resultset_mr = $bdd->query($query_mr);
            $result_mr = $resultset_mr->fetchObject();
            $nombre['recu'] = $result_mr ? $result_mr->nb : 0;

            $table_name_e = LmbEdi\LmbEdi::getPrefix() . "edi_events_queue";
            $query_e = "SELECT COUNT(id) nb FROM $table_name_e WHERE etat = " . lmbedi_queue::OK_CODE;
            $resultset_e = $bdd->query($query_e);
            $result_e = $resultset_e->fetchObject();
            $nombre['events'] = $result_e ? $result_e->nb : 0;
            
            ?>
            <!--</tr>-->
            <tr>
                <td>version du module EDI = <?php echo \lmbedi_config::$VERSION ?></td>
                <td>Magento v <?php
                    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                    $productMetadata = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');
                    echo $version = $productMetadata->getVersion();
                    ?></td>
                <td><?php echo date('d M Y - H:i:s'); ?></td>
            </tr>
            <tr>
                <td>site_distant = <?php echo LmbEdi\LmbEdi::getDistantURL() ?></td>
                <td>racine_URL = <?php echo LmbEdi\LmbEdi::getRacineURL() ?></td>
                <td>code = <?php echo \lmbedi_config::CODE_CONNECTION() ?></td>
            </tr>
            <tr>
                <td>table_prefix = <?php echo LmbEdi\LmbEdi::getPrefix() ?></td>
                <td>id_lang = <?php echo \lmbedi_config::ID_LANG() ?></td>
                <td>mail d'alerte = <?php echo \lmbedi_config::MAIL_ALERT() ?></td>
            </tr>
<?php foreach ($alertes as $alerte): ?>
                <tr>
                    <td colspan="3"><?php echo $alerte ?></td>
                </tr>
        <?php endforeach; ?>
        </table>
        <?php

        /**
         * 
         * @param string $name
         * @return string
         */
        function getLibPid($name) {
            switch ($name) {
                case 'peq.pid': return 'Events (interne)';
                case 'pmeq.pid':return 'Envoi (sortant)';
                case 'pmrq.pid':return 'Recu (entrant)';
                default:return $name;
            }
        }

        function getNombreDansPile($name, $nombre) {
            switch ($name) {
                case 'peq.pid': return $nombre['events'];
                case 'pmeq.pid': return $nombre['envoie'];
                case 'pmrq.pid': return $nombre['recu'];
                default: return 0;
            };
        }
        
        function humanTime($secondes) {
            $s = $secondes % 60;
            $retour = $s."s";
            if ($secondes >= 60) {
                $minutes = floor($secondes / 60);
                $m = $minutes % 60;
                $retour = $m."min ".$retour;
                if ($minutes >= 60) {
                    $heures = floor($minutes / 60);
                    $h = $heures % 24;
                    $retour = $h."h ".$retour;
                    if ($heures >= 24) {
                        $jours = floor($heures / 24);
                        $retour = $jours."j ".$retour;
                    }
                }
            }
            
            return $retour;
        }
        ?>
        <div class="divbtaction" style="text-align:center;padding:10px;">
            <div class="divbtprocesslmb">

                <?php if (!lmbedi_pid::stopIsset()): ?>
                    <input class='btlog' style='margin-right:20px;' type="button" value="Forcer l'arret de tous les process" onclick="document.location = '<?php echo $url ?>?stop_process=1'" />
                <?php else: ?>
                    <input class='btlog' style='margin-right:20px;' type="button" value="Autoriser la reprise des process" onclick="document.location = '<?php echo $url ?>?stop_process=0'" />
<?php endif; ?>
                <input class='btlog'  style='margin-right:20px;' type="button" value="Redemarrer tous les process" onclick="document.location = '<?php echo $url ?>?start_process=1'" />
            </div>
        </div>
        <div style="text-align:center;padding:10px;">
        </div>
        <table border=1 cellpadding="5" style = 'width:80%' class="pids">
            <tr align="center">
                <th>Nom de Pile</th>
                <th>Messages<br>à traiter</th>
                <th>Purger</th>
                <th>PID</th>
                <th>Temps<br />d'execution</th>
                <th>D&eacute;bloquer<br>le process</th>
                <th>Temps<br/>d'inactivité</th>
                <th>Modification<br/>des messages</th>
            </tr>
            <?php
            $pids = lmbedi_pid::getList();
            foreach ($pids as $pid):
                $s = $pid->etat;
                $n = $pid->name;
                ?>
                <tr>
    <?php $pid = new lmbedi_pid($pid->name); ?>
                    <!-- Nom --> 
                    <td><?php echo getLibPid($pid->getName()) ?></td>

                    <!-- Messages à traiter --> 
                    <td class="tdaligncenter <?php
                    if (getNombreDansPile($pid->getName(), $nombre) > 10)
                        echo 'etat9';
                    if (getNombreDansPile($pid->getName(), $nombre) == 0)
                        echo 'etat0';
                    ?>
                        ">
    <?php echo getNombreDansPile($pid->getName(), $nombre); ?>
                    </td>

                    <!-- Purger --> 
                    <td class="tdaligncenter">

                        <?php
                        //bouton pour purger la pile

                        if (//si on est sur la ligne de la pile, et que la pile n'est pas vide
                                ($pid->getName() == 'peq.pid' && $nombre['events']) ||
                                ($pid->getName() == 'pmeq.pid' && $nombre['envoie']) ||
                                ($pid->getName() == 'pmrq.pid' && $nombre['recu'])):
                            ?>
                            <input class='btpurge' style='margin-right:20px;' type="button" value="&darr;" onclick="document.location = '<?php
                            echo $url . '?';
                            switch ($pid->getName()) {
                                case 'peq.pid': if ($nombre['events'])
                                        echo 'purge_events';
                                    break;
                                case 'pmeq.pid':if ($nombre['envoie'])
                                        echo 'purge_mess_envoi';
                                    break;
                                case 'pmrq.pid':if ($nombre['recu'])
                                        echo 'purge_mess_recu';
                                    break;
                                default:
                                    break;
                            };
                            ?>'" />
    <?php endif; ?>
                    </td>

                    <!-- Etat --> 
                    <td class="tdaligncenter etat<?php echo $pid->getEtat() ?>"><?php echo $pid->getLibEtat() ?></td>

                    <!-- Temps d'execution -->
                    <td class="tdaligncenter"><?php echo $pid->getEtat() == lmbedi_pid::ETAT_RUN ? humanTime(time() - $pid->getTime()) : "n/a" ?></td>
                    <!-- Débloquer le process -->
                    <td class="tdaligncenter">
                        <?php if ('Inactif' != $pid->getLibEtat() || ($pid->getEtat() == lmbedi_pid::ETAT_RUN && (time() > $pid->getTime()))): ?>
                            <input class='btlog2' type='button' value='&rarr;' onclick='document.location = "<?php echo $url ?>?unlock_pid=<?php echo $pid->getName() ?>"' />
    <?php endif; ?>
                    </td>
                    <!-- Temps d'inactivité -->
                    <td class="tdaligncenter"><?php echo $pid->getEtat() != lmbedi_pid::ETAT_RUN ? humanTime(time() - $pid->getTime()) : "n/a" ?></td>
                    <!-- Modification des messages -->
                    <td style="width:270px; padding-top:20px;">
                        <form action="<?php echo $url ?>" method="get">
                            <input type="hidden" name="change_etat" value="<?php echo $n ?>" />
                            <input name="id_mess" type="text" placeholder="#ID" style="width:40px;"/>&nbsp;
                            <select name="etat_mess">
                                <option value="0">Non traité</option>
                                <option value="1">Traité</option>
                                <option value="9">En erreur</option>
                            </select>
                            <input type="checkbox" name="all_error" value="1" title="Relancer toutes les erreurs" />
                            <input type="submit" value="Modifier l'état" />
                        </form>
                    </td>
                </tr>
<?php endforeach; ?>
        </table>
        <br/>
        <!--boutons pour logs-->
        <div class="divbtaction" style="text-align:center;padding:10px;">
            <div class="divbtactionlmb">
                <input class='btclearlogs' style='margin-right:20px;' type="button" value="Purger les logs" onclick="document.location = '<?php echo $url ?>?clear_logs'"/>
                <input class='btrefresh'  type="button" onclick='$(".refresh").click();' value="Rafraîchir les logs" />
            </div>
        </div>
        <br/>
        <?php
        /* Affichage des logs et PIDs */
        $logs = $pids = [];
        $logs[] = "__system__";
        if (is_dir(LMBEDI_LOG_DIR)) {
            $log_dir = opendir(LMBEDI_LOG_DIR . "/");
            while ($file = readdir($log_dir)) {
                if ($file != "." && $file != ".." && strpos($file, ".") !== 0)
                    $logs[] = $file;
            }
            sort($logs);
            ?>
            <?php
            foreach ($logs as $log) {
                // $s = @file_get_contents(LMBEDI_LOG_DIR . "/" . $log);
                ?>
                <button class="toggle" data-log="<?php echo $log; ?>">+</button>&nbsp;
                <b><?php echo $log ?></b>&nbsp;
                <button class="log_action purge" data-action="purge" data-log="<?php echo $log; ?>">Purger</button>&nbsp;
                <button class="log_action refresh" data-action="refresh" data-log="<?php echo $log; ?>">Rafraîchir</button>
                <br/>
                <br/>
                <div class="log_content" id="<?php echo str_replace('.', '_', $log); ?>" style="display:none; height: 200px; overflow: auto; border: solid 1px; resize: vertical; background: white; border: 1px solid #6495ed;">
                </div><br/><br/>
                <?php
            }
        }
        ?>
		<a class="lienPratique" href="/lmbedi/import">Page d'import</a><br><br>
    </body>
    <script type="text/javascript" src="https://code.jquery.com/jquery-1.11.3.min.js"></script>
    <script>
                    $(".toggle").click(function () {
                        var file = $(this).data("log");
                        var id = file.replace(".", "_");
                        $("#" + id).toggle();
                        if (id.startsWith('trace_debu') || id.startsWith('mess_envoi') || id.startsWith('mess_recu')) {//debug
                            $("#" + id).css('height', 'auto');
                        }
                        var text = $("#" + id).css("display") == "none" ? "+" : "-";
                        $(this).html(text);
                        if ($("#" + id).css("display") != "none") {
                            $(".refresh[data-log='" + file + "']").click();
                        }
                    })
                    $(".log_action").click(function () {
                        var data = {
                            action: $(this).data("action"),
                            id: $(this).data("log")
                        };
                        $.post("<?php echo $url; ?>", data, function (response) {
                            response = JSON.parse(response);
                            var file = data.id;
                            if (response.value == "success") {
                                var div = $("#" + file.replace(".", "_"));
                                div.html(response.content.replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/&lt;(\/*)pre&gt;/g, "<$1pre>"));
                                div.scrollTop(div[0].scrollHeight);
                            } else {
                                alert("Error !");
                            }
                        });
                    });
    </script>
</html>
<?php

//Fin auth
}
