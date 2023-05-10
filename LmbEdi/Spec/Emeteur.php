<?php

namespace LundiMatin\EDI\LmbEdi\Spec;

use \LundiMatin\EDI\LmbEdi;
use \Magento\Catalog\Model\Category;

/**
 * Nomenclature dans ce connecteur (dans Emeteur.php et Recepteur.php) :
 *  - snake case pour les méthodes applés par la pile
 *  - camel case pour les autres méthodes
 */
class Emeteur extends Connecteur {

    private $bdd;
    private $catCollection;
    private static $preTraite;
    public static $preTraiteClassName = "LundiMatin\\EDI\\LmbEdi\\Spec\\Emeteur_pretrait";
    private static $postTraite;
    public static $postTraiteClassName = "LundiMatin\\EDI\\LmbEdi\\Spec\\Emeteur_posttrait";

    public function __construct() {
        $this->bdd = LmbEdi\LmbEdi::getBDD();
    }

    /**
     * 
     * @param type $fct
     * @param type $param
     * @return type
     */
    public static function envoi_LMB($fct, $param) {
        $url_distant = LmbEdi\LmbEdi::getDistantURL() . "modules/edi/liaison/distant.php";
        $query = "serial_code=" . \lmbedi_config::CODE_CONNECTION() . "&id_canal=" . \lmbedi_config::ID_CANAL();
        $url_distant .= "?" . $query;
        $mes = new \lmbedi_message_envoi();
        $mes->set_destination($url_distant);
        $mes->set_fonction($fct, $param);
        $return = $mes->save();
        $tentative = 0;
        while (!\LundiMatin\EDI\LmbEdi\new_process("envoi", \lmbedi_message_envoi::$process)) {
        if ($tentative++ > 3) {
            LmbEdi\trace_error('create_process', self::getProcess()." n'a pas pu être relancé après 3 tentatives");
            break;
        }
            sleep(2);
        }

        return $return;
    }
    
    protected function dateStringConversion($date, $store) {
        $date_retour = $date;
        try {
            if (is_numeric($store)) {
                $store = \Magento\Framework\App\ObjectManager::getInstance()
                        ->create('\Magento\Store\Model\StoreManagerInterface')
                        ->getStore($store);
            }
            
            $datetime = new \DateTime($date, new \DateTimeZone('UTC'));
            $timezone = new \DateTimeZone($store->getConfig('general/locale/timezone'));
            $datetime->setTimezone($timezone);
            $date_retour = $datetime->format('Y-m-d H:i:s');
        }
        catch (\Exception $e) {
            LmbEdi\trace_error("date_conversion", "Échec de la conversion de la date ".print_r($date, true));
            LmbEdi\trace_error("date_conversion", $e->getMessage());
        }
        
        return $date_retour;
    }

    /**
     * créer des event pour chaque catégorie
     * @return boolean
     */
    public function recup_commandes($commandes = null) {
        LmbEdi\trace("event", "***************RECUPERATION DES COMMANDES****************");
        if(is_array($commandes)){// TRAITEMENT SUR LES CATEGORIES DONNEES EN PARAMETRE
            //creation des events
            foreach($commandes as $id) {
                \lmbedi_event::create("create_order", $id);
            }
        } else { // TRAITEMENT SUR TOUTES LES CATEGORIES
            //get category collection using objectmanager
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $ordersCollection = $objectManager->create('\Magento\Sales\Model\ResourceModel\Order\Collection');
            $ordersCollection->load();
            //creation des events
            foreach ($ordersCollection as $order) {
                LmbEdi\trace("event","commande -> ".$order->getId());
                \lmbedi_event::create("create_order", $order->getIncrementId());
            }
        }
        LmbEdi\trace("event", "***************FIN RECUPERATION DES COMMANDES****************");
        return true;
    }

    /**
     * créer le messge pour créer une commande (=recup_commande)
     * 
     * @param string $orderIncrementalId "incremental id" de la commande
     * @return array contenant les informations de créatino de commande, recup_commande
     * @throws \Exception
     */
    public function create_order($orderIncrementalId) {
        // Si le connecteur va trop vite, le client n'est pas encore créé
        $delay = \lmbedi_config::GET_PARAM('order_delay');
        if (!empty($delay)){
            sleep($delay);
        }
        
        LmbEdi\trace("create_order", "***************DEBUT CREATE ORDER $orderIncrementalId****************");

        if (empty($orderIncrementalId)) {
            LmbEdi\trace_error("create_order", "Commande $orderIncrementalId inexistante !");
            throw new \Exception("Commande $orderIncrementalId inexistante !");
        }

        $sync = [];
        $this->shopInit(); //use autoload from Magento

        $order = \Magento\Framework\App\ObjectManager::getInstance()->create('\Magento\Sales\Model\Order')->loadByIncrementId($orderIncrementalId);

        if (empty($order)) {
            LmbEdi\trace_error("create_order", "Commande $orderIncrementalId innexistante !");
            throw new \Exception("Commande $orderIncrementalId innexistante !");
        }

        $userId = $order->getCustomerId();
        $user = $order->getCustomer();

        $shippingAddress = $order->getShippingAddress() ?: $order->getBillingAddress();

        $shippingAddressFormatted = $this->formatAddress($shippingAddress, $userId);
        $billingAddressFormatted = $this->formatAddress($order->getBillingAddress(), $userId);

        //-Addresses
        $sync += [
            'contact' => $this->formatContact($order),
            'adresses' => [
                'livraison' => [
                    'adresse' => $shippingAddressFormatted,
                    'coordonnee' => $this->formatCoordonnee($shippingAddress, $order) + ['ref_coord' => $shippingAddressFormatted['ref_adresse']],
                ],
                'facturation' => [
                    'adresse' => $billingAddressFormatted,
                    'coordonnee' => $this->formatCoordonnee($order->getBillingAddress(), $order) + ['ref_coord' => $billingAddressFormatted['ref_adresse']],
                ],
            ],
        ];

        $local = \Magento\Framework\App\ObjectManager::getInstance()
                ->get('Magento\Framework\App\Config\ScopeConfigInterface')
                ->getValue('general/locale/code', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $order->getStore()->getStoreId());
        
        $sync += [
            'commande' => [
                'mode_rgmt' => $order->getPayment()->getMethod(), //\Magento\Sales\Api\Data\OrderPaymentInterface |
                'id_livraison_mode' => $order->getShippingMethod(),
                'devise' => $order->getOrderCurrency()->getCode(), // Magento\\Directory\\Model\\Currency
                'taux_devise' => $order->getOrderCurrency()->getRate('EUR'),
                'commentaire' => $order->getCustomerNote(),
                'id_lang' =>
                        \Magento\Framework\App\ObjectManager::getInstance()
                        ->get('Magento\Framework\App\Config\ScopeConfigInterface')
                        ->getValue('general/locale/code', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $order->getStore()->getStoreId()),
                'montant_ht' => $order->getGrandTotal() - $order->getTaxAmount(), //according to this page, this is tax free : https://magento.stackexchange.com/questions/159853/order-total-not-showing-including-tax-in-shopping-cart
                'montant_total' => $order->getGrandTotal()  // Magento : grand_total/base_grand_total :: grand_total = current currency grand total // base_grand_total = store base currency grand total
                ,
                'ref_doc' => $orderIncrementalId,
                'id_boutique' => $order->getStore()->getGroupId(),
                'ref_contact' => $order->getCustomerId(),
                'ref_adresse_livraison' => $shippingAddress->getId(),
                'ref_adr_contact' => $order->getBillingAddressId(),
                'date_creation_doc' => $this->dateStringConversion($order->getCreatedAt(), $order->getStore()),
            ] // getTrackingNumbers
        ];
        
        $shippingMethod = $order->getShippingMethod();
        if (!empty($shippingMethod)) {
            $datas = array();
            switch($shippingMethod) {
                case "colissimo_pickup" :
                    try {
                        $id_pickup = $shippingAddress->getColissimo_pickup_id();
                        $shipping_type = $shippingAddress->getColissimo_product_code();
                    }
                    catch (Exception $e) {
                        break;
                    }
                    
                    if (!empty($id_pickup)) {
                        $datas["prid"] = $id_pickup;
                        $datas["type"] = $shipping_type;
                    }
                    break;
                case "colissimo_pr" :
                    try {
                        $id_pickup = $order->getLpc_relay_id();
                        $shipping_type = $order->getLpc_relay_type();
                    }
                    catch (Exception $e) {
                        break;
                    }
                    
                    if (!empty($id_pickup)) {
                        $datas["prid"] = $id_pickup;
                        $datas["type"] = $shipping_type;
                    }
                    break;
                case "chronorelais_chronorelais" :
                    try {
                        $id_pickup = $order->getRelais_id();
                    }
                    catch (Exception $e) {
                        break;
                    }
                    
                    if (!empty($id_pickup)) {
                        $datas["prid"] = $id_pickup;
                    }
                    break;
            }
            
            if (!empty($datas)) {
                $sync["commande"]["infos_transport"] = array(
                    'name' => $shippingMethod,
                    'datas' => $datas
                );
            }
        }
        
        $ordre = 0;
        $numLine = -1;
        $docsLines = [];

        $parentItem = null;

        // GESTION DES LIGNES DE LA COMMANDE
        foreach ($order->getItemsCollection() as $item) { // Magento\Sales\Model\Order\Item
            $product = $item->getProduct();
            if (empty($product)) {
                continue;
            }

            //si c'est un article parent, on enregistrer ses données, et on l'utilise à la ligne/item d'après
            //  (magento donne toujours deux lignes quand il s'agit d'articles enfants : une ligne pour son parent, et une ligne pour l'enfant)
            if (self::LMB_PRODUCT_TYPE_VARIABLE === $this->convertVariationTypeToLmb($product->getTypeId(), $product->getId())) {
                // enregistrer ses données pour la ligne d'après :)
                $parentItem = clone $item;
                continue;
            }

            ++$numLine;
            ++$ordre;

            $itemPourMontant = $parentItem ?: $item;

            /*
            LmbEdi\o([
                'getPrice' => $product->getPrice(),
                'FinalPric' => $product->getFinalPrice(),
                'item PriceInfo' => $itemPourMontant->getPrice(),
                'item getOriginalPrice' => $itemPourMontant->getOriginalPrice(),
                'item getPriceInclTax' => $itemPourMontant->getPriceInclTax(),
            ]);
             */


            $docsLines[$numLine] = [
                'ref_doc_line' => $item->getId(),
                'ref_doc' => $orderIncrementalId,
                'ref_article' => $item->getProduct()->getId(),
                'ref_interne' => $item->getSku(),
                'variante' => $this->convertVariationTypeToLmb($product->getTypeId(), $product->getId()),
                'lib_article' => $item->getName(),
                'desc_article' => html_entity_decode(strip_tags($product->getShortDescription())),
                'qte' => $item->getQtyOrdered(),
                'remise' => $this->getDiscountPercent($item, $parentItem),
                //(float) (1 - $itemPourMontant->getPriceInclTax() / $this->getPriceInclAndExclTax($item->getProduct())['incl']) * 100,
                /* ne marche pas 'remise' => $itemPourMontant->getDiscountPercent(), */
                'pu_ht' => $itemPourMontant->getPrice(), // $itemPourMontant->getOriginalPrice(), //prix unitaire sans remise    //$this->getPriceInclAndExclTax($item->getProduct())['excl'],
                /* valeurs non utiles pour le connecteur :
                  'pu_ttc' => $this->getPriceInclAndExclTax($item->getProduct())['incl'],
                  'montant_ht' => $itemPourMontant->getPriceInclTax() / (1 + ($itemPourMontant->getTaxPercent() / 100)), //getFinalPrice
                 */
                'montant_ttc' => $itemPourMontant->getPriceInclTax(), // valeur non utile pour le connecteur
                'tva' => $itemPourMontant->getTaxPercent(),
                'ordre' => $ordre,
                'id_doc_line_parent' => null,
                'personnalisations' => $item->getProductOptions()
            ];

            // Eco Tax / Fixed Product Tax / weee tax (weee tax est le nom de la taxe dans Magento)
            if (0 != $item->getBaseWeeeTaxAppliedAmount()) {
                ++$numLine;
                $docsLines[$numLine] = [
                    'ref_doc_line' => $numLine,
                    'ref_doc' => $orderIncrementalId,
                    'ref_article' => 'TAXE',
                    'lib_article' => 'Ecotaxe',
                    'desc_article' => '',
                    'qte' => 1,
                    'pu_ht' => $item->getWeeeTaxAppliedAmount(),
                    'tva' => 0,
                    'ordre' => $ordre,
                    'id_doc_line_parent' => null
                ];
            }

            //si l'article d'avant était un parent
            if (self::LMB_PRODUCT_TYPE_VARIABLE !== $this->convertVariationTypeToLmb($product->getTypeId(), $product->getId()) && $parentItem) {
                $parentItem = null;
            }
        }

        /**
         * bon_reduction
         * 
         *  getDiscountAmount()
         *     public function getDiscountDescription()
         *     public function getDiscountTaxCompensationAmount()
         *     public function getDiscountTaxCompensationInvoiced()
         *     public function getDiscountTaxCompensationRefunded()
         */
        if ($discount = $order->getDiscountAmount()) {
            $code_promo = @$order->getCoupon_code();
            if (empty($code_promo)) {
                $code_promo = @html_entity_decode(strip_tags($order->getDiscountDescription()));
            }
            
            $promo_globale_0 = \lmbedi_config::GET_PARAM('promo_globale_0');
            if (empty($promo_globale_0)) {
                ++$numLine;
                ++$ordre;

                $discount_taxe = $order->getDiscountTaxCompensationAmount();
                $discount_ttc = - $discount;
                $discount_ht = $discount_ttc - $discount_taxe;
                $docsLines[$numLine] = [
                    'ref_doc_line' => $numLine,
                    'ref_doc' => $orderIncrementalId,
                    'ref_article' => 'bon_reduction',
                    'lib_article' => 'Bon de reduction',
                    'desc_article' => html_entity_decode(strip_tags($order->getDiscountDescription())),
                    'qte' => 1,
                    'pu_ht' => $discount_ht,
                    'pu_ttc' => $discount_ttc,
                    'ordre' => $ordre,
                    'id_doc_line_parent' => null
                ];
            }
        }

        //gestion des frais de ports
        ++$numLine;
        ++$ordre;

        //frais de port offert ?
        $pourcentageFraisPortOffert = (float) $order->getShippingAmount() ? 100 * ($order->getShippingDiscountAmount() / (float) $order->getShippingAmount()) : 100;
        $fraisPortOffert = (100 == $pourcentageFraisPortOffert);
        
        // Force la TVA sur les frais de port si elle n'est pas nulle pour éviter les erreurs d'arrondi
        /*$tva_port_force = \lmbedi_config::GET_PARAM('tva_port_force');
        $tva_port = ($order->getShippingAmount() != 0) ? abs(round($order->getShippingTaxAmount() / $order->getShippingAmount() * 100, 1)) : 0;
        if (!empty($tva_port_force) && $tva_port > 0) {
            $tva_port = $tva_port_force;
        }*/
        $tva_port = $this->getTvaShipping($order->getId());
        $payment = $order->getPayment();
        $payment_methode = !empty($payment) ? $payment->getMethod() : "inconnu";
        $docsLines[$numLine] = [
            'ref_doc_line' => '',
            'ref_doc' => $orderIncrementalId,
            'ref_article' => 'frais_port',
            'lib_article' => 'Frais de ports' . ($fraisPortOffert ? ' offerts' : ''),
            'desc_article' => "Transporteur : " . $order->getShippingDescription() . "\nMode de paiement attendu : " . $payment_methode,
            'qte' => '1',
            'pu_ht' => $order->getShippingInclTax() - $order->getShippingTaxAmount(),
            'pu_ttc' => $order->getShippingInclTax(),
            'tva' => $tva_port,
            'ordre' => $ordre,
            'id_doc_line_parent' => null
        ];
        
        if (!empty($code_promo)) {
            $docsLines[$numLine]["desc_article"] .= "\nBon de réduction : " . $code_promo;
        }
        
        if ($pourcentageFraisPortOffert) {
            $docsLines[$numLine]['remise'] = $pourcentageFraisPortOffert;
        }


        /*
         *  A Mettre ?? 
         * 
          foreach ($shopOrderData['shipping_lines'] as $mg2OrderItemShipping){
          //....
          $commande['infos_transport']['name'=>$mg2OrderItemShipping->get_name();
          $commande['infos_transport']['datas'=>json_encode([
          'type'=>$mg2OrderItemShipping->get_type(),
          'titre'=>$mg2OrderItemShipping->get_method_title(),
          'total'=>$mg2OrderItemShipping->get_method_total(),//MG2_Order_Item_Shipping::get_method_total()
          'taxes'=>$mg2OrderItemShipping->get_method_taxes(),
          ]);
          }

          if (1 < count($order['shipping_lines'])) {
          LmbEdi\trace_error("mode_livraison", "livraison multiple détectée. Ceci n'est pas géré");
          }
         */


        $sync['commande']['docs_lines'] = $docsLines;
        $sync['_evt_name_'] = "recup_commande";

        LmbEdi\trace("create_order", "***************FIN   CREATE ORDER $orderIncrementalId****************");

        /* /
          LmbEdi\o($sync, '$sync data');
          throw new \Exception('Stop for development purposes');
          // */

        return $sync;
    }

    /**
     * 
     * @param int $orderIncrementalId
     * @return array contenant les informations de créatino de commande, create_reglement
     */
    public function create_payment($orderIncrementalId) {
        // Si le connecteur va trop vite, le montant n'est pas encore inscrit sur le paiement
        $delay = \lmbedi_config::GET_PARAM('payment_delay');
        if (!empty($delay)){
            sleep($delay);
        }
        
        LmbEdi\trace('create_payment', "***************DEBUT CREATE PAYMENT $orderIncrementalId****************");
        $this->shopInit();
        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $order = $om->create('\Magento\Sales\Model\Order')->loadByIncrementId($orderIncrementalId);
        $payment = $order->getPayment();
        if (!empty($payment) && $payment->getId()) {
            $transaction = $om->create('\Magento\Sales\Model\Order\Payment\Transaction')->load($payment->getTransactionId());

            if ($transaction && $transaction->getCreatedAt()) {
                $date = $this->dateStringConversion($transaction->getCreatedAt(), $order->getStore());
            } elseif ($payment->getCreatedAt()) {
                $date = $this->dateStringConversion($payment->getCreatedAt(), $order->getStore());
            } elseif ($order->getCreatedAt()) {
                $date = $this->dateStringConversion($order->getCreatedAt(), $order->getStore());
            }

            $montant = $payment->getAmountPaid();
            $reglement_methode = \lmbedi_config::GET_PARAM('reglement_methode');
            if (!empty($reglement_methode)) {
                try {
                    $montant = $payment->$reglement_methode();
                }
                catch (\Exception $e) {}
            }
            
            $sync = [
                'id_order' => $orderIncrementalId,
                'id_payment' => $payment->getId(),
                'type' => $payment->getMethod(),
                'montant' => $montant,
                'id_boutique' => $order->getStore()->getGroupId(),
                'date' => $date,
                    //info additionnelle non utilisée
                    /*
                      '__taux_devise' => $order->getOrderCurrency()->getRate('EUR'),
                      '__devise' => $order->getOrderCurrency(),
                      '__id_payment' => $payment->getId(),
                      '__transaction_id' => $order->getTransactionId(),
                     */
            ];
            
            $force_montant = \lmbedi_config::GET_PARAM('use_order_amount_if_empty');
            if (empty($sync['montant']) && !empty($force_montant)) {
                $sync['montant'] = $payment->getAmountOrdered();
            }

            LmbEdi\trace("create_payment", "***************FIN CREATE PAYMENT $orderIncrementalId****************");
            $sync['_evt_name_'] = "create_reglement";
            return $sync;
        }
        
        return true;
    }

    ///////////////////////////////////////////////////////////////////////////////
    //////////////////// evenements issus de l'import//////////////////////////////
    ///////////////////////////////////////////////////////////////////////////////

    /**
     *  créer MESSAGE pour la catégorie
     * @param type $categ_id
     * @return array
     */
    public function create_categorie($categ_id) {

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $cat = $objectManager->create('\Magento\Catalog\Model\Category');
        $cat->load($categ_id);

        if (empty($cat->getId())) { //en cas d'un ID inexistant
            LmbEdi\trace_error("envoi", "Creation de categorie tentée avec une categorie id problematique :`" . print_r($categ_id, 1) . "`");
            return;
        }
        
        if ($cat->getId() == Category::TREE_ROOT_ID) {
            return true;
        }
        
        $category = array( 
            "_evt_name_" => 'create_categorie',
            "ref_art_categ" => $cat["entity_id"],
            "lib_art_categ" => $cat->getName(),
            "description" => $cat->getDescription(),
            "ref_art_categ_parent" => $cat["parent_id"],
            "active" => !empty($cat->getIsActive())
        );
        LmbEdi\trace("event","Create categ >>> ".$cat->getName());
        
        /*
        return true;
        /*/
        return $category;
        //*/
    }

    /**
     * créer des event pour chaque catégorie
     * @return boolean
     */
    public function recup_categs($categories_a_traiter) {
        LmbEdi\trace("event", "***************RECUPERATION DES CATEGORIES D'ARTICLE****************");
        if(!empty($categories_a_traiter)){// TRAITEMENT SUR LES CATEGORIES DONNEES EN PARAMETRE
            //creation des events
            foreach ($categories_a_traiter as $id) {
                \lmbedi_event::create("create_categorie", $id);
            }
        }else{ // TRAITEMENT SUR TOUTES LES CATEGORIES
            //get category collection using objectmanager
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $categoryCollection = $objectManager->create('\Magento\Catalog\Model\ResourceModel\Category\Collection');
            $categoryCollection->load();
            //creation des events
            foreach ($categoryCollection as $categorie) {
                LmbEdi\trace("event","categ >-> ".$categorie->getName());
                \lmbedi_event::create("create_categorie", $categorie->getId());
            }
        }
        LmbEdi\trace("event", "***************FIN RECUPERATION DES CATEGORIES D'ARTICLE****************");
        return true;
    }

        public function create_article($article_id) {
        
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $art = $objectManager->create('\Magento\Catalog\Model\Product');
        $art->load($article_id);
        
        if (empty($art->getId())) { //en cas d'un ID inexistant
            LmbEdi\trace_error("envoi", "Creation d'article tentée avec un id problematique :`" . print_r($article_id, 1) . "`");
            return;
        }
        //-----------------------------------------------------------
        //gestion parent et variante
        $parentId = $objectManager->create('Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable')->getParentIdsByChild($article_id);
        if(!empty($parentId)){
            $ID_parent = $parentId[0]; //récupération de l'ID (sans typage Array)
            $variante = 1; //si l'article a un parent = variante 1 = article enfant sur LMB
        }else{
            $ID_parent = "";
            $variante = 0; //si l'article n'a pas de parent = variante 0 = article simple sur LMB
        }
        if($art->getTypeId() == "configurable"){
            $variante = 2; //2 = parent sur LMB
        }
        
        // gestion categ
        $IDS_cat = $art->getCategoryIds();
        if(!empty($IDS_cat)){
            $ID_cat = $IDS_cat[0];
        }else{
            $ID_cat = "";
            $IDS_cat = "";
            LmbEdi\trace("event", $art->getName()." n'a aucune catégorie");
        }
        
        // gestion tva
        if(!empty(\lmbedi_config::GET_PARAM('taux_tva'))){
            $taux_tva = \lmbedi_config::GET_PARAM('taux_tva');
        }else{
            $taux_tva = 0;
        }
        // gestion prix
        if(!empty(\lmbedi_config::GET_PARAM('apptarifs_ttc'))){
            $pp_ht = $art->getPrice()/(1+$taux_tva/100);
        }else{
            $pp_ht = $art->getPrice();
        }

        // Gestion du prix d'achat
        $pa_ht = $art->getCost() ?? 0;

        // Gestion de prix spéciaux (promos)
        $special_price = $art->getSpecialPrice();
        
        // gestion code barre
        if(!empty(\lmbedi_config::GET_PARAM('code_barre_code'))){
            $code_barre = $art->getData(\lmbedi_config::GET_PARAM('code_barre_code'));
            if(empty($code_barre)) {
                $code_barre = $art->getBarcode();
            }
        }else{
            $code_barre = "";
        }
        // gestion marque
        if(!empty(\lmbedi_config::GET_PARAM('marque_code'))){
            $nom_marque = $art->getAttributeText(\lmbedi_config::GET_PARAM('marque_code'));
            $ID_marque = $art->getData(\lmbedi_config::GET_PARAM('marque_code'));
        }else{
            $ID_marque = "";
            $nom_marque = "";
        }
        // gestion active/status
        $active = 2 - $art->getData('status'); // addaptation en (actif=1 et archivé=0) pour LMB car (actif=1 et archivé=2) dans magento 
        // gestion stock
        $qte = 0;
        $resource = $objectManager->create('Magento\CatalogInventory\Model\ResourceModel\Stock\Item');
        $select = $resource->getConnection()->select()
                ->from($resource->getMainTable())
                ->where('`product_id` = '.$article_id);
        $stockItems = $resource->getConnection()
                ->fetchAll($select);
        
        foreach($stockItems as $stockItem) {
            $qte = $stockItem["qty"];
            break;
        }
        
        
        //gestion des autres caracs ------------------------------------------------------------- |

        //caracs variantes
        if(!empty($ID_parent)){ 
            $artConfigurable = $objectManager->get('Magento\ConfigurableProduct\Model\Product\Type\Configurable');
            $parent = $objectManager->create('\Magento\Catalog\Model\Product');
            $parent->load($ID_parent);
            $parentAttConfigurable = $artConfigurable->getConfigurableAttributesAsArray($parent);
            foreach($parentAttConfigurable as $attribut){
                $var_codes[] = $attribut['attribute_code']; //permettra de retrancher les att variants aux att non variants
                $function_getConfigurableAttribut = "get".ucfirst($attribut['label']);
                $optionId = $art->$function_getConfigurableAttribut();
                if(!empty($optionId)){
                    foreach($attribut['options'] as $option){
                        if($option['value'] == $optionId){
                            $optionLabel = $option['label'];
                            break;
                        }
                    }
                    if(!empty($optionLabel)){
                        $carac = array(
                            "ref_carac" => $attribut['attribute_code'],
                            "lib_carac" => $attribut['label'],
                            "val_carac" => $optionLabel
                        );
                        $caracs_variantes[$attribut['attribute_code']] = $carac;
                    }
                }

            }
        }else{
            $artConfigurable = $objectManager->get('Magento\ConfigurableProduct\Model\Product\Type\Configurable');
            $AttConfigurable = $artConfigurable->getConfigurableAttributesAsArray($art);
            foreach($AttConfigurable as $attribut){
                $var_codes[] = $attribut['attribute_code']; //permettra de retrancher les att variants aux att non variants
                    foreach($attribut['options'] as $option){
                        $opt = array(
                                    "lib_option" => $option['label'],
                                    "val_option" => $option['value']
                                    );
                        $options[$option['label']] = $opt;
                    }
                 
                $carac = array(
                                "ref_carac" => $attribut['attribute_code'],
                                "lib_carac" => $attribut['label'],
                                "options" => $options
                                );
                $caracs_variantes[$attribut['attribute_code']] = $carac;

            }
        }

        if(empty($caracs_variantes)){
            $caracs_variantes = array();
            $var_codes = array();
        }
        
        $attributes = $art->getAttributes();
        foreach ($attributes as $attribute) { // caracs non variantes
        
            if (in_array($attribute->getAttributeCode(), $var_codes) //on filtre la liste d'attribut (notament avec var_codes)
                    || $attribute->getIsUnique()
                    || !$attribute->getData("is_user_defined")
                    || $attribute->getAttributeCode() == 'url_key'
                    || $attribute->getAttributeCode() == 'barcode'
                    || $attribute->getAttributeCode() == \lmbedi_config::GET_PARAM('marque_code')
                    || $attribute->getAttributeCode() == \lmbedi_config::GET_PARAM('code_barre_code')
                    || $attribute->getAttributeCode() == \lmbedi_config::GET_PARAM('prix_achat_code')
                    || $attribute->getFrontend()->getInputType() == 'gallery'
                    || $attribute->getFrontend()->getInputType() == 'media_image'
                    || !$attribute->getIsVisible()) {
                continue;
            }
            
            if (in_array($attribute->getFrontendInput(), array('boolean', 'select', 'multiselect'))) {
                $valeur = $art->getAttributeText($attribute->getAttributeCode());
            }else {
                $valeur = $art->getData($attribute->getAttributeCode());
            }
            if (!empty($valeur)) {
                $lib = $attribute->getFrontend()->getLabel();
                $carac = array (
                    'ref_carac' => $attribute->getAttributeCode(),
                    'lib_carac' => $lib,
                    'val_carac' => $valeur
                );
                    $caracs_non_variantes[$attribute->getAttributeCode()] = $carac;
            }
        }

        if(empty($caracs_non_variantes)){
            $caracs_non_variantes = array();
        }

        $options = [];
        foreach($art->getOptions() as $option) {
            $options[$option->getId()] = [
                "ref_carac" => $option->getSku(),
                "lib_carac" => $option->getTitle(),
                "type" => $option->getType(),
                "required" => $option->getIs_require()
            ];

            foreach($option->getValues() as $value) {
                $options[$option->getId()]['valeurs'][] = [
                    "sku" => $value->getSku(),
                    "lib_value" => $value->getTitle(),
                    "value" => $value->getTitle(),
                    "ordre" => $value->getSort_order()
                ];
            }
        }
        
        // affectation des valeurs dans product ----------------------------------------------- |
        $product = array(
            "_evt_name_" => 'create_article',
            "id_edi_canal" => \lmbedi_config::GET_PARAM('idcanal'),
            "ref_article" => $article_id,
            "ref_article_parent" => $ID_parent,
            "variante" => $variante,
            "active" => $active,
            "lib_article" => $art->getName(),
            "desc_longue" => $art->getDescription(),
            "desc_courte" => $art->getShortDescription(),
            "ref_art_categ" => $ID_cat,
            "ref_art_categs" => $IDS_cat,
            "id_marque" => $ID_marque,
            "marque" => $nom_marque,
            "code_barre" => $code_barre,
            "reference" => $art->getSku(),
            "qte" => $qte,
            "pp_ht" => $pp_ht,
            "pu_ht" => $pp_ht,
            "pa_ht" => $pa_ht, // PA d'achat
            "tva" => $taux_tva,
            "poids" => $art->getWeight(),
            "caracs_variantes" => $caracs_variantes,
            "caracs_non_variantes" => $caracs_non_variantes,
            "personnalisations" => $options
        );

        if(!empty($special_price)) {
            $product['promo_ht'] = $special_price;
            $product['promo_debut'] = $art->getSpecialFromDate();
            $product['promo_fin'] = $art->getSpecialToDate();
        }

        LmbEdi\trace("event", ">> ".print_r($product,1));
        return $product;
    }
    
    /**
     * générer un message pour un produit
     * @return boolean
     */
    public function recup_products($articles_a_traiter) {
        LmbEdi\trace("event", "***************RECUPERATION DES ARTICLES****************");
        if(!empty($articles_a_traiter)){// TRAITEMENT SUR LES ARTICLES DONNEES EN PARAMETRE
            //creation des events
            foreach ($articles_a_traiter as $id) {
                \lmbedi_event::create("create_article", $id);
            }
        }else{ // TRAITEMENT SUR TOUT LES ARTICLES
            //get product collection using objectmanager
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $articlesCollection = $objectManager->create('\Magento\Catalog\Model\ResourceModel\Product\Collection');
            $articlesCollection->load();
            //creation des events
            foreach ($articlesCollection as $article) {
                \lmbedi_event::create("create_article", $article->getId());
            }
        }
        LmbEdi\trace("event", "***************FIN RECUPERATION DES ARTICLES****************");
        return true;
    }

        /**
     *  créer MESSAGE pour l'image
     * @param type $image_id
     * @return array
     */
    public function recup_images_article($image_id) {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $art = $objectManager->create('\Magento\Catalog\Model\Product');
        $art->load($image_id);
        
        if (empty($art->getId())) { //en cas d'un ID inexistant
            LmbEdi\trace_error("envoi", "Creation d'image tentée avec un id d'aticle problematique :`" . print_r($image_id, 1) . "`");
            return;
        }
        $images = $art->getMediaGalleryImages();
        if(empty($images)){
            LmbEdi\trace("event","Create image >>> pas d'image...");
            return false;
        }
        $tab_images = [];
        foreach ($images as $image) { // possiblilité d'avoir plusieurs images pour le même article
            if($image['media_type'] == "image"){
                //gestion parent et variante
                $parentId = $objectManager->create('Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable')->getParentIdsByChild($image_id);
                if(!empty($parentId)){
                    $ID_parent = $parentId[0]; //récupération de l'ID (sans typage Array)
                    $variante = 1; //si l'article a un parent = variante 1 = article enfant sur LMB
                }else{
                    $ID_parent = "";
                    $variante = 0; //si l'article n'a pas de parent = variante 0 = article simple sur LMB
                }
                if($art->getTypeId() == "configurable"){
                    $variante = 2; //2 = parent sur LMB
                }
                //affectation des valeurs dans image
                $image = array(
                    "ref_article" => $image_id,
                    "id_image" => $image['id'],
                    "variante" => $variante,
                    "url" => $image['url']
                );
                $tab_images[] = $image;
                LmbEdi\trace("event","Create image >>> ID(".$image_id.") ".$image['url']);
            }
        }
        $tab_images['_evt_name_'] = 'recup_images';
        return $tab_images;
    }
    
    
    /**
     * générer un message pour une image
     * @return boolean
     */
    public function recup_images($images_a_traiter) {
        LmbEdi\trace("event", "***************RECUPERATION DES IMAGES****************");
        if(!empty($images_a_traiter)){// TRAITEMENT SUR LES ARTICLES/IMAGES DONNEES EN PARAMETRE
            //creation des events
            foreach ($images_a_traiter as $id) {
                \lmbedi_event::create("recup_images_article", $id);
            }
        }else{ // TRAITEMENT SUR TOUTES LES IMAGES
            //get product collection using objectmanager
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $imagesCollection = $objectManager->create('\Magento\Catalog\Model\ResourceModel\Product\Collection');
            $imagesCollection->load();
            //creation des events
            foreach ($imagesCollection as $image) {
                \lmbedi_event::create("recup_images_article", $image->getId());
            }
        }
        LmbEdi\trace("event", "***************FIN RECUPERATION DES IMAGES****************");
        return true;
    }

    
    /**
     *  créer MESSAGE pour la marque
     * @param type $image_id
     * @return array
     */
    public function create_marque($mrq) {
        $marque = array(
            "_evt_name_" => 'create_marque',
            "id_marque" => $mrq['value'],
            "lib_marque" => $mrq['label']
        );
        LmbEdi\trace("event", "Create Marque >>> ".$marque['lib_marque']);
        return $marque;
    }
    
            
        /**
         * générer un message pour une marque
         * @return boolean
         */
    public function recup_marques() {
        LmbEdi\trace("event", "***************RECUPERATION DES MARQUES****************");
        // TRAITEMENT SUR TOUTES LES MARQUES
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $mrq = $objectManager->get(\Magento\Catalog\Api\ProductAttributeRepositoryInterface::class)->get(\lmbedi_config::GET_PARAM('marque_code'));
        foreach($mrq->getOptions() as $option) {
            if(!empty($option->getLabel()) && !empty($option->getValue())){
                \lmbedi_event::create("create_marque", $option->getData());
                LmbEdi\trace("envoi", "Import de la marque ".print_r($option->getData(),1));
            }else{
                LmbEdi\trace_error("envoi", "Echec de l'import de la marque : ID ou label vide");
            }
        }
        LmbEdi\trace("event", "***************FIN RECUPERATION DES MARQUES****************");
        return true;
    }

    /**
     * générer un message pour un produit
     * @return boolean
     */
    public function recup_attributSet($articles_a_traiter) {
        LmbEdi\trace("event", "***************RECUPERATION DES JEUX D'ATTRIBUTS****************");
        if(!empty($articles_a_traiter)){// TRAITEMENT SUR LES ARTICLES DONNEES EN PARAMETRE
            //creation des events
            foreach ($articles_a_traiter as $id) {
                \lmbedi_event::create("create_attributSet", $id);
            }
        }else{ // TRAITEMENT SUR TOUT LES ARTICLES
            //get product collection using objectmanager
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $articlesCollection = $objectManager->create('\Magento\Catalog\Model\ResourceModel\Product\Collection');
            $articlesCollection->load();
            //creation des events
            foreach ($articlesCollection as $article) {
                \lmbedi_event::create("create_attributSet", $article->getId());
            }
        }
        LmbEdi\trace("event", "***************FIN RECUPERATION DES JEUX D'ATTRIBUTS****************");
        return true;
    }

    public function create_attributSet($article_id){
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $art = $objectManager->create('\Magento\Catalog\Model\Product');
        $art->load($article_id);

        if (empty($art->getId())) { //en cas d'un ID inexistant
            LmbEdi\trace_error("envoi", "Creation d'un jeu d'attribut tentée avec un id d'article problematique :`" . print_r($article_id, 1) . "`");
            return;
        }
        if(empty($art->getAttributeSetId())) { //en cas d'un ID de jeu d'attribut inexistant
            LmbEdi\trace_error("envoi", "l'article " . $article_id . " n'as pas de jeu d'attribut");
            return;
        }
        $setRepository = $objectManager->create('Magento\Eav\Api\AttributeSetRepositoryInterface');
        $set = $setRepository->get($art->getAttributeSetId());
        //gestion parent et variante
        $parentId = $objectManager->create('Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable')->getParentIdsByChild($article_id);
        if(!empty($parentId)){
            $ID_parent = $parentId[0]; //récupération de l'ID (sans typage Array)
            $variante = 1; //si l'article a un parent = variante 1 = article enfant sur LMB
        }else{
            $ID_parent = "";
            $variante = 0; //si l'article n'a pas de parent = variante 0 = article simple sur LMB
        }
        if($art->getTypeId() == "configurable"){
            $variante = 2; //2 = parent sur LMB
        }
        //création du tableau de retour
        $jeu['_evt_name_'] = 'update_art_attributes_set';
        $jeu['ref_article'] = $article_id;
        $jeu['variante'] = $variante;
        $jeu['lib_set'] = $set->getAttributeSetName();
        $jeu['attributes_set'] = $art->getAttributeSetId();

        return $jeu;
    }

    ///////////////////////////////////////////////////////////////////////////////
    ////////////// fin des evenements issus de l'import////////////////////////////
    ///////////////////////////////////////////////////////////////////////////////
    
    
    /**
     * créer des event recup_product ET recup_image
     * 
     * @return boolean
     */
    public function recup_element_create_event($element_type, $ids = null) {
        $elements_qui_peuvent_etre_crees = array('recup_product', 'recup_image');
        if (!in_array($element_type, $elements_qui_peuvent_etre_crees)) {
            throw new \Exception('Ne peut pas créer d\'événement "' . $element_type . '"');
        }

        $i = 0;
        LmbEdi\trace("event", "***************RECUPERATION $element_type****************");
        $l = LmbEdi\LmbEdi::instance();

        if (empty($ids)) {
            $ids = $this->getListeProduit();
        } elseif (is_numeric($ids)) {
            $ids = array($ids);
        } elseif (is_array($ids) && all($ids, 'is_numeric')) {
            ; //ids valides
        } else {
            throw new \Exception('id doit etre est un int ou un array of int ! `' . print_r($ids, 1) . '`');
        }

        foreach ($ids as $id) {
            $l->create_event($element_type, $id);
            if ($i++ > 10000000)
                throw new \Exception('Nombre of article limite est : 10000000'); //empécher boucle infinie
        }

        LmbEdi\trace("event", "***************FIN RECUPERATION $element_type****************");
        return true;
    }
    
    ///////////////////////////////////////////////////////////////////////////////
    ////////////// pre/post traite ////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * return null
     */
    public function getPreTraite() {
        if (!file_exists(dirname(__FILE__) . "/traitements/Emeteur_pretrait.php"))
            return null;
        require_once(dirname(__FILE__) . "/traitements/Emeteur_pretrait.php");
        if (!class_exists(self::$preTraiteClassName, false))
            return null;
        if (empty(self::$preTraite))
            self::$preTraite = new Emeteur_pretrait();
        return self::$preTraite;
    }

    /**
     * return null
     */
    public function getPostTraite() {
        if (!file_exists(dirname(__FILE__) . "/traitements/Emeteur_posttrait.php"))
            return null;
        require_once(dirname(__FILE__) . "/traitements/Emeteur_posttrait.php");
        if (!class_exists(self::$postTraiteClassName, false))
            return null;
        if (empty(self::$postTraite))
            self::$postTraite = new Emeteur_posttrait();
        return self::$postTraite;
    }

    /**
     * formatter les données de l'utilisateur
     * @param \Magento\Sales\Model\Order $order
     * @return array
     */
    protected function formatContact(\Magento\Sales\Model\Order $order) {
        $address = $order->getBillingAddress() ?? $order->getShippingAddress();
        if(!empty($address)) {
            return [
                'pro' => false,
                'email' => $order->getCustomerEmail(),
                'id_edi_canal' => \lmbedi_config::ID_CANAL(),
                'ref_contact' => $order->getCustomerId(),
                'id_civilite' => $this->convertGender($order->getCustomerGender()),
                'siret' => '',
                'nom' => $address->getLastname() . ' ' . $address->getFirstName(),
                'raison_sociale' => $address->getCustomerName(),
                'nom_famille' => $address->getLastname(),
                'prenom' => $address->getFirstName(),
                'tva_intra' => ''
            ];
        } else {
            return [];
        }
    }

    /**
     * formatter les données de l'utilisateur
     * 
     * /!\ la ref_coord n'est pas incluse dans le tableau retourné. Cette donnée doit être gérée/ajoutée en dehors de cette fonction !! /!\ 
     * 
     * @param \Magento\Sales\Model\Order $order
     * @return array
     */
    protected function formatCoordonnee(\Magento\Sales\Model\Order\Address $address, \Magento\Sales\Model\Order $order) {
        return [
            'id_edi_canal' => \lmbedi_config::ID_CANAL(),
            /* 'ref_coord' => $address->getId(), */
            'ref_contact' => $order->getCustomerId(),
            'lib_coord' => '',
            'tel1' => $address->getTelephone(),
            'tel2' => '',
            'fax' => $address->getFax(),
            'email' => $address->getEmail(),
        ];
        //getVatId
    }

    /**
     * Convertie le Sexe de Magento en LMB.
     * Ici, il y a la même correspondance.
     * 
     * @param type $gender
     * @return int|null
     */
    protected function convertGender($gender) {
        switch ($gender) {
            case 1: return 1;
            case 2: return 2;
            default:
                return null;
        }
    }

    protected function formatAddress($address, $userId) {
        if ('object' == gettype($address)) {
            $address->explodeStreetAddress(); //Create fields street1, street2, etc.
            $address = $address->getData();
        }

        $return = [
            'id_edi_canal' => \lmbedi_config::ID_CANAL(),
            'ref_contact' => $userId,
            'lib_adresse' => '',
            'nom_adresse' => $address['lastname'],
            'prenom_adresse' => $address['firstname'],
            'societe_adresse' => $address['company'],
            'text_adresse1' => isset($address['street1']) ? $address['street1'] : $address['street'],
            'text_adresse2' => @$address['street2'],
            'text_adresse3' => @$address['street3'],
            'code_postal' => $address['postcode'],
            'ville' => $address['city'],
            'id_pays' => $address['country_id'],
            'code_etat' => null, /* getRegion */
            'note' => ''
        ];


        return $return + ['ref_adresse' => md5(json_encode($return))];
        /* getPrefix
         * getSuffix */
    }

    /**
     * Convertit le type d'article Mg2 en entier pour LMB (It will return configurable or simple
     * simple va devenir 0; variation va devenir 1,...)
     * 
     * Obtenir la liste des product type de Mg2 :
     *   find . | grep "Model/Product/Type" | grep -v "/Test/" | grep -v '/tests/' | xargs grep "const.*TYPE" 2>/dev/null
     * 
     * http://docs.magento.com/m1/ce/user_guide/catalog/product-types.html
     * 
     * 
     * @param string $type - type d'article de Woo_coomerce : simple, variation, variable, external, grouped, 
     * @param int $productId
     * 
     * @return string|null
     */
    private function convertVariationTypeToLmb($type, $productId) {
        $variante = null;
        switch ($type) {
            case \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE :  //simple OU enfant - Magento ne fait pas la différence
                if ($this->getParentId($productId)) {
                    return self::LMB_PRODUCT_TYPE_VARIATION;
                }
                return self::LMB_PRODUCT_TYPE_SIMPLE;
            case \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE : return self::LMB_PRODUCT_TYPE_VARIABLE;
            default:
                return self::LMB_PRODUCT_TYPE_SIMPLE;
        }
    }

    /**
     * @param int $productId
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     * 
     * https://gielberkers.com/get-product-price-including-excluding-tax-magento-2/
     * 
     * @deprecated since version 1.2
     * @return array
     */
    protected function getPriceInclAndExclTax($product) {
        $objM = \Magento\Framework\App\ObjectManager::getInstance();
        $scopeConfig = $objM->create('\Magento\Framework\App\Config');
        $taxCalculation = $objM->create('\Magento\Tax\Model\TaxCalculation');


        if (!($taxAttribute = $product->getCustomAttribute('tax_class_id'))) {
            throw new LocalizedException(__('Tax Attribute not found'));
        }
        // First get base price (price excluding tax)
        $productRateId = $taxAttribute->getValue();
        $rate = $taxCalculation->getCalculatedRate($productRateId);

        if ((int) $scopeConfig->getValue('tax/calculation/price_includes_tax', \Magento\Store\Model\ScopeInterface::SCOPE_STORE) === 1) {
            // Product price in catalog is including tax.
            $priceExcludingTax = $product->getPrice() / (1 + ($rate / 100));
        } else {
            // Product price in catalog is excluding tax.
            $priceExcludingTax = $product->getPrice();
        }

        $priceIncludingTax = $priceExcludingTax + ($priceExcludingTax * ($rate / 100));

        return [
            'incl' => $priceIncludingTax,
            'excl' => $priceExcludingTax
        ];
    }

    /**
     * @param int $productId
     * @return int|null
     */
    protected function getParentId($productId) {
        $parentIds = \Magento\Framework\App\ObjectManager::getInstance()
                ->create('Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable')
                ->getParentIdsByChild($productId);
        if (isset($parentIds[0])) {
            return $parentIds[0];
        }
    }

    protected function getDiscountPercent($order_line, $parent_line) {
        $discount = $order_line->getDiscountPercent();
        if (!empty($parent_line)) {
            $discount = $parent_line->getDiscountPercent();
        }

        $force_discount_amount = \lmbedi_config::GET_PARAM('force_discount_amount');
        if (empty($discount) || (float) $discount == 0 || !empty($force_discount_amount)) {
            $discount_amount = $order_line->getDiscountAmount();
            if (!empty($parent_line)) {
                $discount_amount = $parent_line->getDiscountAmount();
            }

            if (!empty($discount_amount) && (float) $discount_amount !== 0) {
                $pu_ht = $order_line->getPrice();
                if (!empty($parent_line)) {
                    $pu_ht = $parent_line->getPrice();
                }

                $qte = $order_line->getQtyOrdered();
                if (!empty($parent_line)) {
                    $qte = $parent_line->getQtyOrdered();
                }

                $tva = $order_line->getTaxPercent();
                if (!empty($parent_line)) {
                    $qte = $parent_line->getTaxPercent();
                }

                $pu_total = $qte * $pu_ht;
                $remise = $discount_amount;
                $discount_amount_ht = \lmbedi_config::GET_PARAM('discount_amount_ht');
                if (empty($discount_amount_ht)) {
                    $remise /= (1+$tva/100);
                }
                $discount = 0;
                if ($pu_total > 0) {
                    $discount = round((100 * $remise) / $pu_total, 2);
                }
            }
        }
        
        return !empty($discount) ? $discount : 0;
    }

    protected function getTvaShipping($id_order) {
        $tva = 0;
        $tax_items = $this->getObjectManager()->create("\Magento\Sales\Model\ResourceModel\Order\Tax\Item")->getTaxItemsByOrderId($id_order);
        if (is_array($tax_items)) {
            foreach ($tax_items as $item) {
                if ($item['taxable_item_type'] === 'shipping') {
                    $tva = $item['tax_percent'];
                    break;
                }
            }
        }
        return $tva;
    }

}
