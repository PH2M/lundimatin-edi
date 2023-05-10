<?php
//
//if (!empty($_REQUEST['type'])) {
//    require('_dir.inc.php');
//
//    $GLOBALS['log_file'] = "transaction_pmrq.log";
//
//    ignore_user_abort(true);
//    @set_time_limit(0);
//    require_once($DIR_MODULE . "config_bdd.inc.php");
//    require_once($DIR_MODULE . 'include.inc.php');
//
//    error_reporting(E_ALL);
//    set_error_handler("edi_error");
//    set_exception_handler('edi_exception');
//
//    switch ($_REQUEST['type']) {
//        case "images":
//            InfoBDD::infosImages();
//            break;
//        case "articles":
//            if (!empty($_REQUEST["element"])) {
//                $params["id_product"] = $_REQUEST["element"];
//                $params["variante"] = !empty($_REQUEST["variante"]) ? true : false;
//                $params["action"] = $_REQUEST["action"];
//                InfoBDD::infosArticle($params);
//            } else {
//                InfoBDD::infosProducts();
//            }
//            break;
//        case "articles_json":
//            InfoBDD::infosArticle_json();
//            break;
//        case "marques":
//            InfoBDD::infosMarques();
//            break;
//        case "artcategs":
//        case "getCatagories":
//            InfoBDD::infosArtcategs();
//            break;
//        case "artcategs_json":
//            InfoBDD::infosArtcategs_json();
//            break;
//        case "articles_doublons_json":
//            InfoBDD::infosArticle_doublons_json();
//            break;
//        default:
//            break;
//    }
//}
//
//class InfoBDD {
//
//    public static function infosCategs() {
//        
//    }
//
//    public static function infosArticle_doublons_json() {
//        global $bdd;
//
//        if ($_REQUEST['serial_code'] == config::CODE_CONNECTION()) {
//            $lst_articles = array();
//
//            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
//                if (isset($_SERVER['CONTENT_TYPE'])) {
//                    $content = file_get_contents('php://input');
//                    $content = json_decode($content, true);
//
//                    if (json_last_error() != JSON_ERROR_NONE) {
//                        $lst_articles = array();
//                    } else {
//                        if (!empty($content['lst_articles']) && is_array($content['lst_articles'])) {
//                            foreach ($content['lst_articles'] as $article) {
//                                if ($article['variante'] != "1") {
//                                    $query = "SELECT GROUP_CONCAT(CAST(p.id_product AS CHAR)) id, pl.name, COUNT(*) nb 
//												FROM " . module_liaison::$TABLE_PREFIX . "product p
//												INNER JOIN " . module_liaison::$TABLE_PREFIX . "product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = '" . config::ID_LANG() . "'
//												WHERE pl.name = '" . trim($article['lib_article']) . "'
//												GROUP BY pl.name HAVING nb > 1";
//
//                                    $res = $bdd->query($query);
//
//                                    if (is_object($res)) {
//                                        if ($product = $res->fetchObject()) {
//                                            if (!isset($lst_articles[$article['ref_lmb']]))
//                                                $lst_articles[$article['ref_lmb']] = array();
//
//                                            $lst_articles[$article['ref_lmb']]['libelle'] = array("valeur" => trim($article['lib_article']), "nb" => $product->nb, "id" => $product->id);
//                                        }
//                                        $res->closeCursor();
//                                    }
//
//
//                                    $query = "SELECT GROUP_CONCAT(CAST(p.id_product AS CHAR)) id, p.reference, COUNT(*) nb 
//												FROM " . module_liaison::$TABLE_PREFIX . "product p
//												WHERE p.reference = '" . trim($article['reference']) . "'
//												GROUP BY p.reference HAVING nb > 1";
//
//                                    $res = $bdd->query($query);
//
//                                    if (is_object($res)) {
//                                        if ($product = $res->fetchObject()) {
//                                            if (!isset($lst_articles[$article['ref_lmb']]))
//                                                $lst_articles[$article['ref_lmb']] = array();
//
//                                            $lst_articles[$article['ref_lmb']]['reference'] = array("valeur" => trim($article['reference']), "nb" => $product->nb, "id" => $product->id);
//                                        }
//                                        $res->closeCursor();
//                                    }
//                                }
//                            }
//                        }
//                    }
//                }
//            }
//
//            header("Content-type: application/json");
//            die(json_encode($lst_articles));
//        } else {
//            LmbEdi\trace_error("infos", "ERREUR DE CODE DE CONNEXION !");
//            exit("ERREUR DE CODE DE CONNEXION !");
//        }
//    }
//
//    public static function infosArticle_json() {
//        global $bdd;
//
//        if ($_REQUEST['serial_code'] == config::CODE_CONNECTION()) {
//            $lst_articles = array();
//
//            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
//                if (isset($_SERVER['CONTENT_TYPE'])) {
//                    $content = file_get_contents('php://input');
//                    $content = json_decode($content, true);
//
//                    if (json_last_error() != JSON_ERROR_NONE) {
//                        $lst_articles = array();
//                    } else {
//                        if (!empty($content['lst_articles']) && is_array($content['lst_articles'])) {
//                            foreach ($content['lst_articles'] as $article) {
//                                if (empty($article['ref_externe'])) {
//                                    $lst_articles[$article['ref_lmb']] = array("ref_externe" => 0);
//                                    continue;
//                                }
//
//                                if ($article['variante'] != "1") {
//                                    $product = new Product($article['ref_externe']);
//                                    if (empty($product->id)) {
//                                        $lst_articles[$article['ref_lmb']] = array("ref_externe" => 0);
//                                        continue;
//                                    } else {
//                                        $lst_articles[$article['ref_lmb']] = array("ref_externe" => $product->id, "lib_externe" => (!empty($product->name[config::ID_LANG()]) ? $product->name[config::ID_LANG()] : ""));
//                                    }
//                                } else {
//                                    if (!empty($article['ref_externe_parent'])) {
//                                        $product_parent = new Product($article['ref_externe_parent']);
//                                        if (empty($product_parent->id)) {
//                                            $lst_articles[$article['ref_lmb']] = array("ref_externe" => 0, "message" => "Article parent non synchronis�");
//                                            continue;
//                                        } else {
//                                            $product_attributes = $product_parent->getAttributeCombinations(config::ID_LANG());
//                                            if (count($product_attributes) > 0) {
//                                                $product_attribute = reset($product_attributes);
//                                                if (empty($product_attribute['id_product_attribute'])) {
//                                                    $lst_articles[$article['ref_lmb']] = 0;
//                                                    continue;
//                                                } else {
//                                                    $lst_articles[$article['ref_lmb']] = array("ref_externe" => $product_attribute['id_product_attribute'], "lib_externe" => "", "variante" => 1);
//                                                }
//                                            }
//                                        }
//                                    } else {
//                                        $lst_articles[$article['ref_lmb']] = array("ref_externe" => 0);
//                                    }
//                                }
//                            }
//                        }
//                    }
//                }
//            }
//
//            header("Content-type: application/json");
//            die(json_encode($lst_articles));
//        } else {
//            LmbEdi\trace_error("infos", "ERREUR DE CODE DE CONNEXION !");
//            exit("ERREUR DE CODE DE CONNEXION !");
//        }
//    }
//
//    public static function infosImages() {
//        global $DIR;
//        if ($_REQUEST['serial_code'] == config::CODE_CONNECTION()) {
//            header("Content-type: application/xml");
//            $doc = new DOMDocument();
//            $imgs = $doc->createElement('images');
//
//            $images = Image::getAllImages();
//
//            foreach ($images as &$image) {
//                $img_xml = $doc->createElement('image');
//                $img_xml->setAttribute("id", $image['id_image']);
//                $img_xml->setAttribute("id_product", $image['id_product']);
//                $img_xml->setAttribute("position", "");
//
//                $image['file'] = "";
//                if (_PS_VERSION_ < "1.4") {
//                    $image_dest = module_liaison::$RACINE_URL . '/img/p/' . $image['id_product'] . '-' . $image['id_image'] . ".jpg";
//                    if (is_file($DIR . '/img/p/' . $image['id_product'] . '-' . $image['id_image'] . ".jpg")) {
//                        $image['file'] = '/img/p/' . $image['id_product'] . '-' . $image['id_image'] . ".jpg";
//                    }
//                } else {
//                    $p = "";
//                    $image['id_image'] = strval($image['id_image']);
//                    for ($i = 0; $i < strlen($image['id_image']); $i++) {
//                        $p .= $image['id_image'][$i] . "/";
//                    }
//                    $image_dest = module_liaison::$RACINE_URL . '/img/p/' . $p . $image['id_image'] . ".jpg";
//                    if (!is_file($DIR . '/img/p/' . $p . $image['id_image'] . ".jpg")) {
//                        $image_dest = module_liaison::$RACINE_URL . '/img/p/' . $image['id_product'] . '-' . $image['id_image'] . ".jpg";
//                        if (is_file($DIR . '/img/p/' . $image['id_product'] . '-' . $image['id_image'] . ".jpg")) {
//                            $image['file'] = '/img/p/' . $image['id_product'] . '-' . $image['id_image'] . ".jpg";
//                        }
//                    } else {
//                        $image['file'] = '/img/p/' . $p . $image['id_image'] . ".jpg";
//                    }
//                }
//                $img_xml->setAttribute("file", $image['file']);
//
//                $image['url'] = "";
//                if (!empty($image['file'])) {
//                    $image['url'] = $image_dest;
//                }
//
//                $ref_xml = $doc->createElement('url');
//                $ref_xml_dat = $doc->createCDATASection($image['url']);
//                $ref_xml->appendChild($ref_xml_dat);
//                $img_xml->appendChild($ref_xml);
//
//                $imgs->appendChild($img_xml);
//            }
//
//            $doc->appendChild($imgs);
//            echo $doc->saveXML();
//        } else {
//            LmbEdi\trace_error("infos", "ERREUR DE CODE DE CONNEXION !");
//            exit("ERREUR DE CODE DE CONNEXION !");
//        }
//    }
//
//    public static function infosProducts() {
//        if ($_REQUEST['serial_code'] == config::CODE_CONNECTION()) {
//            header("Content-type: application/xml");
//            $doc = new DOMDocument();
//            $products = $doc->createElement('products');
//
//            $prods = Product::getProducts(config::ID_LANG(), 0, 0, 'id_product', 'ASC');
//
//            foreach ($prods as $prod) {
//                $product = new Product($prod['id_product']);
//                $prod_xml = $doc->createElement('product');
//                $prod_xml->setAttribute("id", $product->id);
//                $prod_xml->setAttribute("statut", $product->active);
//                $prod_xml->setAttribute("variante", 0);
//                $ref_xml = $doc->createElement('reference');
//                $ref_xml_dat = $doc->createCDATASection($product->reference);
//                $ref_xml->appendChild($ref_xml_dat);
//                $prod_xml->appendChild($ref_xml);
//                $ref_xml = $doc->createElement('name');
//                $ref_xml_dat = $doc->createCDATASection($product->name[config::ID_LANG()]);
//                $ref_xml->appendChild($ref_xml_dat);
//                $prod_xml->appendChild($ref_xml);
//                $ref_xml = $doc->createElement('prix');
//                $ref_xml_dat = $doc->createCDATASection($product->price);
//                $ref_xml->appendChild($ref_xml_dat);
//                $prod_xml->appendChild($ref_xml);
//
//                $products->appendChild($prod_xml);
//                $declis = $product->getAttributeCombinaisons(config::ID_LANG());
//                foreach ($declis as $decl) {
//                    $prod_xml = $doc->createElement('product');
//                    $prod_xml->setAttribute("id", $decl["id_product_attribute"]);
//                    $prod_xml->setAttribute("statut", $product->active);
//                    $prod_xml->setAttribute("variante", 1);
//                    $prod_xml->setAttribute("id_parent", $product->id);
//                    $ref_xml = $doc->createElement('reference');
//                    $ref_xml_dat = $doc->createCDATASection($decl["reference"]);
//                    $ref_xml->appendChild($ref_xml_dat);
//                    $prod_xml->appendChild($ref_xml);
//                    $ref_xml = $doc->createElement('name');
//                    $ref_xml_dat = $doc->createCDATASection($product->name[config::ID_LANG()] . " - " . $decl["attribute_name"]);
//                    $ref_xml->appendChild($ref_xml_dat);
//                    $prod_xml->appendChild($ref_xml);
//                    $ref_xml = $doc->createElement('prix');
//                    $ref_xml_dat = $doc->createCDATASection($product->price + $decl["price"]);
//                    $ref_xml->appendChild($ref_xml_dat);
//                    $prod_xml->appendChild($ref_xml);
//
//                    $products->appendChild($prod_xml);
//                }
//            }
//
//            $doc->appendChild($products);
//            echo $doc->saveXML();
//        } else {
//            LmbEdi\trace_error("infos", "ERREUR DE CODE DE CONNEXION !");
//            exit("ERREUR DE CODE DE CONNEXION !");
//        }
//    }
//
//    public static function infosMarques() {
//        global $DIR;
//        if ($_REQUEST['serial_code'] == config::CODE_CONNECTION()) {
//            header("Content-type: application/xml");
//            $doc = new DOMDocument();
//            $imgs = $doc->createElement('marques');
//
//            $marques = Manufacturer::getManufacturers();
//
//            foreach ($marques as $marque) {
//                $fabricant = new Manufacturer($marque['id_manufacturer']);
//                $marque_xml = $doc->createElement('marque');
//                $marque_xml->setAttribute("id", $fabricant->id);
//
//                $ref_xml = $doc->createElement('nom');
//                $ref_xml_dat = $doc->createCDATASection($fabricant->name);
//                $ref_xml->appendChild($ref_xml_dat);
//                $marque_xml->appendChild($ref_xml);
//
//                $imgs->appendChild($marque_xml);
//            }
//
//            $doc->appendChild($imgs);
//            echo $doc->saveXML();
//        } else {
//            LmbEdi\trace_error("infos", "ERREUR DE CODE DE CONNEXION !");
//            exit("ERREUR DE CODE DE CONNEXION !");
//        }
//    }
//
//    public static function infosArtcategs() {
//        global $bdd;
//        if ($_REQUEST['serial_code'] == config::CODE_CONNECTION()) {
//            header("Content-type: application/xml");
//
//            $doc = new DOMDocument();
//            $categories = $doc->createElement('categories');
//
//            $query = "SELECT c.id_category, c.id_parent, cl.name, cl.description, c.active
//                            FROM " . module_liaison::$TABLE_PREFIX . "category c
//                            LEFT JOIN " . module_liaison::$TABLE_PREFIX . "category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = '" . config::ID_LANG() . "'
//                                    WHERE c.id_category != '1' 
//                                    ORDER BY level_depth;";
//
//            $res = $bdd->query($query);
//
//            if (is_object($res)) {
//                while ($categ = $res->fetchObject()) {
//                    $categ_xml = $doc->createElement('category');
//                    $categ_xml->setAttribute("id", $categ->id_category);
//                    $categ_xml->setAttribute("parent_id", $categ->id_parent);
//                    $categ_xml->setAttribute("active", $categ->active);
//                    $ref_xml = $doc->createElement('nom');
//                    $ref_xml_dat = $doc->createCDATASection($categ->name);
//                    $ref_xml->appendChild($ref_xml_dat);
//                    $categ_xml->appendChild($ref_xml);
//                    $categories->appendChild($categ_xml);
//                }
//                $res->closeCursor();
//            }
//
//            $doc->appendChild($categories);
//            echo $doc->saveXML();
//        } else {
//            LmbEdi\trace_error("infos", "ERREUR DE CODE DE CONNEXION !");
//            exit("ERREUR DE CODE DE CONNEXION !");
//        }
//    }
//
//    public static function infosArtcategs_json() {
//        global $bdd;
//        if ($_REQUEST['serial_code'] == config::CODE_CONNECTION()) {
//            $lst_category = array();
//
//            $query = "SELECT c.id_category, c.id_parent, cl.name, cl.description, c.active
//                            FROM " . module_liaison::$TABLE_PREFIX . "category c
//                            LEFT JOIN " . module_liaison::$TABLE_PREFIX . "category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = '" . config::ID_LANG() . "'
//                                    WHERE c.id_category != '1' 
//                                    ORDER BY level_depth;";
//
//            $res = $bdd->query($query);
//
//            if (is_object($res)) {
//                while ($categ = $res->fetchObject()) {
//                    $lst_category[$categ->id_category] = $categ->name;
//                }
//                $res->closeCursor();
//            }
//
//            header("Content-type: application/json");
//            die(json_encode($lst_category));
//        } else {
//            LmbEdi\trace_error("infos", "ERREUR DE CODE DE CONNEXION !");
//            exit("ERREUR DE CODE DE CONNEXION !");
//        }
//    }
//
//    public static function infosArticle($params) {
//        $bdd = $GLOBALS['bdd'];
//
//        if ($_REQUEST['serial_code'] == config::CODE_CONNECTION()) {
//            header("Content-type: application/xml");
//            $doc = new DOMDocument();
//
//            switch ($params['action']) {
//                case 'article_soft' :
//                    $price_var = 0;
//                    if ($params['variante'] == 1) {
//                        $query = "SELECT * FROM " . module_liaison::$TABLE_PREFIX . "product_attribute 
//									WHERE id_product_attribute = " . $bdd->quote($params['id_product']);
//                        $res = $bdd->query($query);
//                        $decli = $res->fetchObject();
//                        if ($decli) {
//                            $price_var = $decli->price;
//                            $qte_stock = $decli->quantity;
//                            $product = new Product($decli->id_product);
//                        } else {
//                            $product = new Product();
//                        }
//                    } else {
//                        $product = new Product($params['id_product']);
//                        $qte_stock = $product->quantity;
//                        if (_PS_VERSION_ >= "1.5") {
//                            $link = Context::getContext()->link;
//                            $product_link = $link->getProductLink(
//                                    $product->id, $product->link_rewrite[config::ID_LANG()], Category::getLinkRewrite($product->id_category_default, config::ID_LANG())
//                            );
//                        } else {
//                            $link = new Link();
//                            $product_link = $link->getProductLink(
//                                    $product->id, $product->link_rewrite[config::ID_LANG()], Category::getLinkRewrite($product->id_category_default, config::ID_LANG())
//                            );
//                        }
//                    }
//
//                    $prod_xml = $doc->createElement('article');
//                    if ($product->id) {
//                        $prod_xml->setAttribute("id", $params['id_product']);
//                    } else {
//                        $prod_xml->setAttribute("id", "");
//                    }
//                    if ($params['variante']) {
//                        $prod_xml->setAttribute("id_parent", $decli->id_product);
//                    }
//                    $prod_xml->setAttribute("variante", $params['variante']);
//                    $prod_xml->setAttribute("statut", $product->active);
//                    $ref_xml = $doc->createElement('name');
//                    $ref_xml_dat = $doc->createCDATASection($product->name[config::ID_LANG()]);
//                    $ref_xml->appendChild($ref_xml_dat);
//                    $prod_xml->appendChild($ref_xml);
//                    if (!empty($product_link)) {
//                        $ref_xml = $doc->createElement('product_link');
//                        $ref_xml_dat = $doc->createCDATASection($product_link);
//                        $ref_xml->appendChild($ref_xml_dat);
//                        $prod_xml->appendChild($ref_xml);
//                    }
//                    $ref_xml = $doc->createElement('prix');
//                    $ref_xml_dat = $doc->createCDATASection((isset($product->street_price) ? $product->street_price : $product->price) + $price_var);
//                    $ref_xml->appendChild($ref_xml_dat);
//                    $prod_xml->appendChild($ref_xml);
//                    $ref_xml = $doc->createElement('prix_achat');
//                    $ref_xml_dat = $doc->createCDATASection($product->wholesale_price);
//                    $ref_xml->appendChild($ref_xml_dat);
//                    $prod_xml->appendChild($ref_xml);
//                    $ref_xml = $doc->createElement('stock_virtuel');
//                    $ref_xml_dat = $doc->createCDATASection($qte_stock);
//                    $ref_xml->appendChild($ref_xml_dat);
//                    $prod_xml->appendChild($ref_xml);
//                    $doc->appendChild($prod_xml);
//                    break;
//
//                case 'article_complet' :
//                    break;
//            }
//            echo $doc->saveXML();
//        } else {
//            LmbEdi\trace_error("infos", "ERREUR DE CODE DE CONNEXION !");
//            exit("ERREUR DE CODE DE CONNEXION !");
//        }
//    }
//
//}
