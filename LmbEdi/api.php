<?php
namespace LundiMatin\EDI\LmbEdi;
//
//require_once(__DIR__ . '/../../../wp-load.php');
//
//if (!class_exists("LmbEdi")) {
//    die("Module non installé ou non activé !");
//}
//
//class TestAPI {
//    
//    const ERROR_MISSING_PARAMS = 1;
//    const ERROR_EXCEPTION = -1;
//    const ERROR_FATAL = -9;
//    const SUCCESS = 0;
//    
//    public function __construct() {
//        error_reporting(E_ALL);
//        set_error_handler(array("TestAPI", "fatalErrorHandler"));
//        register_shutdown_function(array("TestAPI", "fatalErrorHandler"));
//    }
//    
//    public static function fatalErrorHandler() {
//        $e = error_get_last();
//        if (empty($e)) return false;
//        else $message = $e["message"]." | ".$e["file"]." | ".$e["line"];
//        $response = array(
//            "exit_status" => self::ERROR_FATAL,
//            "content" => $message
//        );
//        die(json_encode($response));
//    }
//    
//    public function __call($method, $params) {
//        if (method_exists($this, $method)) return $this->{$method}($params);
//        else throw new Exception("Action inconnue.");
//    }
//    
//    public function parseRequest($params) {
//        if (!isset($params["action"])) throw new Exception("Aucune action spécifiée.");
//        if (!isset($params["token"])) throw new Exception("Le code de connexion est nécessaire.");
//        $local_token = lmbedi_config::CODE_CONNECTION();
//        if ($local_token !== $params["token"]) throw new Exception("Code de connexion invalide...");
//        else {
//            $action = $params["action"];
//            unset($params["action"], $params["token"]);
//            return array(
//                "action" => $action,
//                "params" => $params
//            );
//        }
//    }
//    
//    public static function handleRequest() {
//        $code = self::ERROR_MISSING_PARAMS;
//        $content = "Paramètres d'appel invalides.";
//        try {
//            $api = new TestAPI();
//            $data = $api->parseRequest($_POST);
//            $code = self::SUCCESS;
//            $content = $api->{$data["action"]}($data["params"]);
//        } catch (Exception $e) {
//            $code = self::ERROR_EXCEPTION;
//            $content = $e->getMessage()." | ".$e->getFile()." | ".$e->getLine();
//        }
//        return json_encode(array(
//            "exit_status" => $code,
//            "content" => $content
//        ));
//    }
//    
//    protected function get_categ($params) {
//        $id = $params["id_categ"];
//        $api_articles = new LMB_API_Products(new LMB_API_Server());
//        $result = $api_articles->get_product_category($id);
//        if (!is_array($result)) throw new Exception("Catégorie non trouvée.");
//        else return $result["product_category"];
//    }
//    
//    protected function get_product($params) {
//        $id = $params["id_product"];
//        $api_articles = new LMB_API_Products(new LMB_API_Server());
//        $result = $api_articles->get_product($id);
//        if (!is_array($result)) throw new Exception("Article non trouvé.");
//        else return $result["product"];
//    }
//}
//
//exit(TestAPI::handleRequest());