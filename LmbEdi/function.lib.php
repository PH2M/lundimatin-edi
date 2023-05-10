<?php

namespace LundiMatin\EDI\LmbEdi;

use lmbedi_pid;

function logme($msg) {
    global $log_file;

    if (empty($log_file)) {
        $log_file = "undefined.log";
    }
    $fp = fopen(LMBEDI_LOG_DIR . "/" . $log_file, "a");
    if ($fp) {
        fwrite($fp, date("Y-m-d H:i:s") . " - " . $msg . "\n");
        fclose($fp);
    }
}

function trace($suffixe_dest, $message) {
    $suffixe_dest = preg_replace('#[^\da-z-_]#i', '', $suffixe_dest);
    $fp = fopen(LMBEDI_LOG_DIR . "/trace_" . $suffixe_dest, "a");
    if ($fp) {
        fwrite($fp, date("Y-m-d H:i:s") . " - " . $message . "\n");
        fclose($fp);
    }
}

function trace_error($suffixe_dest, $message) {
    $d = debug_backtrace();
    $fp = fopen(LMBEDI_LOG_DIR . "/error_" . $suffixe_dest, "a");
    if ($fp) {
        fwrite($fp, "[ " . date("Y-m-d H:i:s")
                . sprintf(" %s %4s %16s] ", substr(@$d[0]['file'], -32), "" . @$d[0]['line'], @$d[1]['function'])
                . $message . "\n");
        fclose($fp);
    }
}

/**
 * 
 * @param string $suffixe_dest suffixe du log - ce sera le titre affiché dans la page debug
 * @param any $message 
 * @param str $title assertion que tu veux faire - interrogrations
 */
function trace_r($suffixe_dest, $message, $title = '') {
    trace($suffixe_dest, $title . ($title ? ' ' : '') . print_r($message, true));
}

/**
 * 
 * @param string $suffixe_dest suffixe du log - ce sera le titre affiché dans la page debug
 * @param any $message 
 * @param str $title assertion que tu veux faire - interrogrations
 */
function trace_debug($suffixe_dest, $message, $title) {
    trace('debug_' . $suffixe_dest, $title . '? `' . print_r($message, true) . '`');
}

/**
 * Utilise logger de Magento pour logger debug.
 * 
 * @param mixed $message (est affiché au maximum 10000 caractEres)
 * @param string $title
 * @param string $level : emergency/critical/alert/error/warning/notice/info/log/debug
 */
function o($message, $title = '', $level = 'debug') {
    $d = debug_backtrace();

    if (in_array($level, ['emergency', 'critical', 'alert', 'error', 'warning']))
        $title = '/!\ ' . strtoupper($title) . '/!\ ';

    \Magento\Framework\App\ObjectManager::getInstance()->get(\Psr\Log\LoggerInterface::class)->debug(
            sprintf("%s %3d %12s | %s `%s`" . "\n", //
                    date("md Hi:s"), //
                    $d[0]['line'], //
                    substr($d[0]['file'], -12), //
                    ($title ? $title . '|' : ''), //
                    substr(print_r($message, 1), 0, 10000)) //
    );
}

/**
 * Tell whether all members of $array validate the $predicate.
 *
 * all(array(1, 2, 3),   'is_numeric'); -> true
 * all(array(1, 2, 'a'), 'is_numeric'); -> false
 */
function all($array, $predicate) {
    return array_filter($array, $predicate) === $array;
}

/**
 * Tell whether any member of $array validates the $predicate.
 *
 * any(array(1, 'a', 'b'),   'is_int'); -> true
 * any(array('a', 'b', 'c'), 'is_int'); -> false
 */
function any($array, $predicate) {
    return array_filter($array, $predicate) !== [];
}

/* Cette fonction appelle l'url passé en paramètre comme un nouveau processus
 * @param $fin_url - l'url de la page est appelée depuis la racine du module exemple "pile/_process_messages_recu_queue.php"
 * @param $chemin_pid - chemin du fichier de pid depuis la racine du module
 */

function new_process($fin_url, $chemin_pid) {
    //************************************************************
    // TEST SI UN PROCESSUS N'EST PAS DEJA EN COURS D'EXECUTION
    $pid = new lmbedi_pid($chemin_pid);
    if ($pid->issetPid()) {
        return true;
    }
    //***********************************
    // CREATION D'UN NOUVEAU PROCESSUS
    trace("process", "Nouveau process $chemin_pid");
    if (lmbedi_pid::stopIsset()) {
        if ($chemin_pid != "diagnostic.pid") {
            logme("Arrét forcé de la file par fichier pid/stop !");
            return true;
        }
    }

    //$url = \lmbedi_config::GET_PARAM('RACINE_URL')."LmbEdi/pile/" . $fin_url;
    $url = \lmbedi_config::RACINE_URL()."lmbedi/pile/" . $fin_url."/?time=".time();
	
    // Méthode avec fsockopen
    /* $parts = parse_url($url);

      $fp = fsockopen($parts['host'],
      isset($parts['port'])?$parts['port']:80,
      $errno, $errstr, 10);
      if (!$fp) {
      trace("process","Erreur connexion process $chemin_pid");
      return false;
      }
      $out = "GET ".$parts['path']." HTTP/1.1\r\n";
      $out.= "Host: ".$parts['host']."\r\n";
      $out.= "Connection: Close\r\n\r\n";
      if(!fwrite($fp, $out)){
      trace("process","Erreur création process $chemin_pid");
      return false;
      }
      fclose($fp); */

    // Methode avec cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if (!ini_get('open_basedir') && !ini_get('safe_mode'))
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4'))
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);    //Autorise au plus 5 redirections (par sécurité)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    //curl_setopt($ch , CURLOPT_TIMEOUT_MS, 500);      //Coupe la connexion au bout de 500ms
    $timeout = 1;
    if ($chemin_pid == "diagnostic.pid") {
        $timeout = 30;
    }
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);      //Coupe la connexion au bout de 1s
    if (\lmbedi_config::HTTP_AUTH()) {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, \lmbedi_config::HTTP_AUTH());
    }
    $response = curl_exec($ch);

    if ($chemin_pid == "diagnostic.pid") {
        $retour = array(
            "reponse" => $response,
            "errno" => curl_errno($ch),
            "infos" => curl_getinfo($ch),
            "pid_stop" => lmbedi_pid::stopIsset()
        );

        curl_close($ch);
        return $retour;
    }

    if (curl_errno($ch) && curl_errno($ch) != 28) {
        trace('process', 'Erreur (' . curl_errno($ch) . ') création process $chemin_pid : ' . curl_error($ch));
        return false;
    }
    curl_close($ch);

    trace('process', 'Process $chemin_pid démarré...');
	trace('process', $url);
    return true;
}

function ht2ttc($pu_ht, $tva) {
    $pu_ttc = round($pu_ht * (1 + $tva / 100), 2);

    return $pu_ttc;
}

function ttc2ht($pu_ttc, $tva) {
    $pu_ht = $pu_ttc / (1 + $tva / 100);

    return $pu_ht;
}

function myimageCut($srcFile, $destFile, $destWidth, $destHeight, $fileType, $srcWidth, $srcHeight) {
    if (!isset($srcFile['tmp_name']) OR ! file_exists($srcFile['tmp_name']))
        return false;

    // Source infos
    $srcInfos = getimagesize($srcFile['tmp_name']);
    $src['width'] = $srcInfos[0];
    $src['height'] = $srcInfos[1];
    $src['ressource'] = createSrcImage($srcInfos[2], $srcFile['tmp_name']);

    // Destination infos
    $dest['x'] = 0;
    $dest['y'] = 0;
    $dest['width'] = $destWidth != NULL ? $destWidth : $src['width'];
    $dest['height'] = $destHeight != NULL ? $destHeight : $src['height'];
    $dest['ressource'] = createDestImage($dest['width'], $dest['height']);

    $white = imagecolorallocate($dest['ressource'], 255, 255, 255);

    if ($srcWidth / $dest['width'] > $srcHeight / $dest['height']) {
        $dest['height'] = ($dest['width'] / $srcWidth) * $srcHeight;
    } else {
        $dest['width'] = ($dest['height'] / $srcHeight) * $srcWidth;
    }

    imagecopyresampled($dest['ressource'], $src['ressource'], 0, 0, 0, 0, $dest['width'], $dest['height'], $srcWidth, $srcHeight);
    imagecolortransparent($dest['ressource'], $white);
    $return = returnDestImage($fileType, $dest['ressource'], $destFile);
    return ($return);
}

function url_exists($url) {
    if (ini_get("allow_url_fopen") && ini_get("allow_url_fopen") == "On") {
        $hdrs = @get_headers($url);
    } else {
        $parts = parse_url($url);
        $fp = fsockopen($parts['host'], isset($parts['port']) ? $parts['port'] : 80, $errno, $errstr, 10);
        if (!$fp) {
            trace("reception", "Erreur connexion distante $url");
            return false;
        }
        $out = "GET " . $parts['path'] . " HTTP/1.1\r\n";
        $out .= "Host: " . $parts['host'] . "\r\n";
        $out .= "Connection: Close\r\n\r\n";
        if (!fwrite($fp, $out)) {
            trace("reception", "Erreur de la requete");
            return false;
        }
        $data = "";
        while (!feof($fp)) {
            $data .= fgets($fp, 9999);
        }
        fclose($fp);
        $reponse = explode("\r\n\r\n", $data);
        $head = $reponse[0];
        $hdrs = explode("\r\n", $head);
    }
    return is_array($hdrs) ? preg_match('/^HTTP\\/\\d+\\.\\d+\\s+2\\d\\d\\s+.*$/', $hdrs[0]) : false;
}

function copy_from_url($url, $dest) {
    $fdest = fopen($dest, 'w+');
    $parts = parse_url($url);

    $fp = fsockopen($parts['host'], isset($parts['port']) ? $parts['port'] : 80, $errno, $errstr, 10);
    if (!$fp) {
        trace("reception", "Erreur connexion distante $url");
        return false;
    }
    $out = "GET " . $parts['path'] . " HTTP/1.1\r\n";
    $out .= "Host: " . $parts['host'] . "\r\n";
    $out .= "Connection: Close\r\n\r\n";
    if (!fwrite($fp, $out)) {
        trace("reception", "Erreur de la requete");
        return false;
    }
    $data = "";
    while (!feof($fp)) {
        $data .= fgets($fp, 9999);
    }
    fclose($fp);
    $reponse = explode("\r\n\r\n", $data);
    $head = $reponse[0];
    $hdrs = explode("\r\n", $head);
    if (preg_match('/^HTTP\\/\\d+\\.\\d+\\s+2\\d\\d\\s+.*$/', $hdrs[0])) {
        $img = $reponse[1];
        fputs($fdest, $img);
        fclose($fdest);
        return true;
    } else {
        return false;
    }
}

function lmb_grab_image($url, $dest) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    if (!ini_get('open_basedir') && !ini_get('safe_mode'))
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
    $raw = curl_exec($ch);

    $error_no = curl_errno($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpCode == 404) {
        return false;
    }

    if (!empty($error_no))
        return false;

    $fp = fopen($dest, 'x');
    fwrite($fp, $raw);
    fclose($fp);
    return true;
}

function trailingslashit($path) {
    return properDirectorySeparatorIt($path);
}

function untrailingslashit($path) {
    return substr(properDirectorySeparatorIt($path), 0, -1);
}

/**
 * Ajoute DIRECTORY_SEPARATOR à la fin
 * replace les / et \ par le bon DIRECTORY_SEPARATOR 
 * replace les // et /// et \\ et \\\ par UN bon DIRECTORY_SEPARATOR 
 * 
 * @param type $path
 * @return type
 */
function properDirectorySeparatorIt($path) {
    return str_replace(['\\', '//', '//', '/'], ['/', '/', '/', DIRECTORY_SEPARATOR], $path . '/');
}

function json_decode_utf8($input) {
    $input = iconv('UTF-8', 'UTF-8//IGNORE', utf8_encode($input));
    return json_decode($input, TRUE);
}
