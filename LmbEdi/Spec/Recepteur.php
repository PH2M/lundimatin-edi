<?php

namespace LundiMatin\EDI\LmbEdi\Spec;

use \LundiMatin\EDI\LmbEdi;
use LundiMatin\EDI\Model\LmbAction;
use LundiMatin\EDI\Model\LmbManager;
use \Magento\Catalog\Model\Product\Link;
use \Magento\Catalog\Model\Category;
use \Magento\Catalog\Model\Product;
use \Magento\Catalog\Model\Product\Type;
use \Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use \Magento\Sales\Model\Order;

/**
 * permet les traitements des messages venant de LMB
 */
class Recepteur extends Connecteur {

    const TRACKING_TITLE = "Tracking LMB";
    const INFOS_MULTI_STORE = [
        "desc_courte",
        "lib_article",
        "desc_longue"
    ];

    const PREFIX_BOUTIQUE = "_boutique_";

    protected $bdd;
    protected static $preTraite;
    protected static $postTraite;
    public static $preTraiteClassName = "LundiMatin\\EDI\\LmbEdi\\Spec\\Recepteur_pretrait";
    public static $postTraiteClassName = "LundiMatin\\EDI\\LmbEdi\\Spec\\Recepteur_posttrait";
    //Permet d'indiquer que l'EDI est en cours de réception
    //Du coup les edi_event venant de Hooks ne devront pas être pris en compte
    //afin d'éviter un Ping-Pong avec LMB
    public static $receive = false;

    const IMAGE_PATH = 'lmb_create_art_img';

    public function __construct() {
        self::$receive = true;
        $this->bdd = LmbEdi\LmbEdi::getBDD();
    }

    protected function getStoreId($data = null) {
        $store_id = 0;
        $store_param = \lmbedi_config::GET_PARAM('store');

        if (!empty($data["store_id"])) {
            $store_id = $data["store_id"];
        } else if (!empty($store_param)) {
            $store_id = $store_param;
        }

        return $store_id;
    }

    public function isAutorisedStore($id_store) {
        $stores = $this->getStores();
        return array_key_exists($id_store, $stores);
    }

    protected function isSingleStoreMode() {
        $store_manager = $this->getObjectManager()->get('\Magento\Store\Model\StoreManagerInterface');
        return $store_manager->isSingleStoreMode();
    }

    protected function getStores($withDefault = false, $codeKey = false) {
        $storeManager = $this->getObjectManager()->get('\Magento\Store\Model\StoreManagerInterface');
        return $storeManager->getStores($withDefault, $codeKey);
    }

    protected function getIdsGroupeStore($datas) {
        $ids_groupe = [];
        if (!empty($datas['ref_art_categs'])) {
            $categ = $datas['ref_art_categs'];
        } else if (!empty($datas['ref_art_categ'])) {
            $categ = array($datas['ref_art_categ']);
        }
        $ids_website_article = $this->getIds_website($categ);

        foreach ($this->getStores() as $store) {
            if (!empty($store->getGroupId()) && $store->getWebsiteId() && in_array($store->getWebsiteId(), $ids_website_article)) {
                $ids_groupe[$store->getGroupId()][] = $store->getId();
            }
        }

        return $ids_groupe;
    }

    protected function setStoreId($store_id) {
        $store_manager = $this->getObjectManager()->create('\Magento\Store\Model\StoreManagerInterface');
        $store = $store_manager->getStore($store_id);
        LmbEdi\trace("tests_store", "Chargement du store $store_id / " . $store->getCode());
        $store_manager->setCurrentStore($store->getCode());
    }

    protected function handleInfosMultiStore($art, $datas) {
        if (empty($datas) || $this->isSingleStoreMode()) {
            return false;
        }

        try {
            $ids_groupe_store = $this->getIdsGroupeStore($datas);
            $datas_save = [];
            foreach ($datas as $key => $value) {
                foreach (self::INFOS_MULTI_STORE as $field) {
                    $field_check = $field . self::PREFIX_BOUTIQUE;
                    if (strpos($key, $field_check) !== false) {
                        $id_website = substr($key, strlen($field_check));
                        if (!empty($id_website) && !empty($ids_store = $ids_groupe_store[$id_website])) {
                            foreach ($ids_store as $id_store) {
                                $datas_save[$id_store][$field] = $value;
                            }
                        }
                    }
                }
            }

            foreach ($datas_save as $id_store => $data) {
                $product = $this->getObjectManager()->create('\Magento\Catalog\Model\Product')->setStoreId($id_store)->load($art->getId());
                foreach ($data as $key => $val) {
                    switch ($key) {
                        case 'lib_article':
                            $product->setName($val);
                            break;
                        case 'desc_courte':
                            $product->setShortDescription($val);
                            break;
                        case 'desc_longue':
                            $product->setDescription($val);
                            break;
                    }
                }
                $product->save();
            }

        } catch (\Exception $exception) {
            LmbEdi\trace_error("multi_store", "Erreur : " . json_encode(["message" => $exception->getMessage(), "line" => $exception->getLine(), "file" => $exception->getFile()]));
        }

        return true;
    }

    /**
     * Permet d'enregistrer les articles en tenant compte du cas des Parents sans Enfants initialement bloquant pour Magento
     * @param Product $art
     */
    protected function saveArticle($art, $store_id = null, $skip_pretrait = false) {
        
        if (!$skip_pretrait) {
            if (empty($store_id)) {
                $store_id = $this->getStoreId();
            }

            if (empty($art->getId())) {
                $art->save();
            }

            if ($art->getTypeId() == Configurable::TYPE_CODE) {
                $childs = $art->getTypeInstance()->getChildrenIds($art->getId());
                if (empty($childs)) {
                    $art->setTypeId(Type::TYPE_SIMPLE)
                        ->setStoreId($store_id)
                        ->save();

                    $art = $this->getObjectManager()->create('\Magento\Catalog\Model\Product')
                        ->load($art->getId());
                    $art->setTypeId(Configurable::TYPE_CODE)
                        ->setStoreId($store_id);
                }
            }
        }

        try {
            $art->save();
        }
        catch (\Magento\UrlRewrite\Model\Exception\UrlAlreadyExistsException $e) {
            $message = "Mise à jour de l'article ".$data['ref_article']." ignoré";
            $message .= ", un autre article utilise la même url";
            
            LmbEdi\trace_error("reception_exception", $message);
            LmbEdi\trace_error("reception_exception", $e->getMessage());
            return false;
        }
    }

    /**
     * Gestion du catalogue, création de catégorie
     */
    public function create_categorie($data) {
        $ignore_categ = \lmbedi_config::GET_PARAM('ignore_categ');
        if (!empty($ignore_categ)) {
            return true;
        }

        LmbEdi\trace("reception", "Debut de la création d'une catégorie");

        $id_lmb = $data['ref_art_categ'];
        unset($data['ref_art_categ']);
        $cat = $this->setInfosCategory($data);

        if (!$cat || !$cat->getId()) {
            LmbEdi\trace_error('reception', 'creation categorie nok (pas de résultat) - ' . print_r($data, true));
            return false;
        }

        $this->createCorrespondanceLmb($id_lmb, $cat->getId(), 2);

        return true;
    }

    /**
     * @param type $data
     * @return boolean
     */
    public function delete_categorie($data) {
        $ignore_categ = \lmbedi_config::GET_PARAM('ignore_categ');
        if (!empty($ignore_categ)) {
            return true;
        }

        LmbEdi\trace('reception', "DEBUT de la suppression d'une catégorie " . $data['ref_art_categ'] . "*******************");

        if (Category::TREE_ROOT_ID == $data['ref_art_categ']) {
            LmbEdi\trace_error('reception', 'Ne pas supprimer la catégorie ROOT `' . $data['ref_art_categ'] . '`');
            return true;
        }

        $root_category = \lmbedi_config::GET_PARAM('root_category');
        if (!empty($root_category) && $root_category == $data['ref_art_categ']) {
            LmbEdi\trace_error('reception', 'Ne pas supprimer la catégorie racine `' . $data['ref_art_categ'] . '`');
            return true;
        }

        $store_id = $this->getStoreId($data);
        $cat = LmbManager::load(LmbManager::CATEGORY_MANAGER, $data['ref_art_categ'], $store_id);
        $id_parent = $cat->getParentId();

        if (!$cat || !$cat->getId()) {
            LmbEdi\trace_error('reception', 'pas de categorie à supprimer, pas de catégorie trouvée ' . $data['ref_art_categ'] . '`');
            return true;
        }

        try {
            $desactive_categ = \lmbedi_config::GET_PARAM('desactive_categ_on_delete');
            if (!empty($desactive_categ)) {
                $cat->setIsActive(false)
                    ->save();
            } else {
                $this->getObjectManager()->get('Magento\Framework\Registry')
                    ->register('isSecureArea', true);
                $cat->delete();
            }
        } catch (\Exception $e) {
            if ($e->getMessage() != "Opération de suppression interdite") {
                LmbEdi\trace_error('reception', "Opération de suppression interdite pour la catégorie #" . $data['ref_art_categ']);
                throw $e;
            }
        }

        $this->changeCategoryChildrenCount($id_parent, $store_id);
        LmbEdi\trace("reception", "FIN de la suppression d'une catégorie " . $data['ref_art_categ'] . "*******************");

        return true;
    }

    /**
     * @param type $category
     * @return boolean
     * @throws Exception
     */
    public function update_categorie($data) {
        $ignore_categ = \lmbedi_config::GET_PARAM('ignore_categ');
        if (!empty($ignore_categ)) {
            return true;
        }

        LmbEdi\trace("reception", "DEBUT de la MAJ d'une catégorie *******************");
        $cat = $this->setInfosCategory($data);
        LmbEdi\trace("reception", "FIN de la MAJ d'une catégorie *******************");
        return (bool)$cat;
    }

    /**
     * @param array $data
     * @return Category|null
     */
    protected function setInfosCategory(array $data) {
        $store_id = $this->getStoreId($data);

        //get by ID
        if (!empty($data['ref_art_categ'])) {
            LmbEdi\trace("reception", " => Mise à jour de catégorie");
            $cat = LmbManager::load(LmbManager::CATEGORY_MANAGER, $data['ref_art_categ'], $store_id);

            if (empty($cat->getId())) {
                LmbEdi\trace("reception", "Catégorie " . $data['ref_art_categ'] . " non trouvée, mise à jour impossible");
                return true;
            }
        } else {
            LmbEdi\trace("reception", " => Création de catégorie");
            $cat = LmbManager::create(LmbManager::CATEGORY_MANAGER);
        }

        // Catégorie parent
        $root_category = \lmbedi_config::GET_PARAM('root_category');
        if (empty($root_category)) {
            $root_category = Category::TREE_ROOT_ID;
        }
        $parentId = !empty($data['ref_art_categ_parent']) ? $data['ref_art_categ_parent'] : $root_category;
        $oldParentId = $cat->getParentId();

        $parentCateg = LmbManager::load(LmbManager::CATEGORY_MANAGER, $parentId, $store_id);
        if (!$parentCateg || !$parentCateg->getId()) {
            LmbEdi\trace_error('reception', 'Catégorie Parente inexistante .`' . $data['ref_art_categ_parent'] . '`');
            return false;
        }

        $cleanurl = trim(preg_replace('/ +/', '', preg_replace('/[^A-Za-z0-9 ]/', '', urldecode(html_entity_decode(strip_tags(strtolower($data['lib_art_categ'])))))));

        $active = isset($data['visible']) ? !empty($data['visible']) : !empty($data['active']);
        // Add a new sub category
        $cat->setName(ucfirst($data['lib_art_categ']))
            ->setIsActive(true)
            ->setData('description', $data['description'])
            ->setParentId($parentCateg->getId())
            ->setStoreId($store_id)
            ->setLevel($parentCateg->getLevel() + 1)
            ->setPosition(1 + $this->getMaxPositionOfChildren($parentCateg, $store_id))
            ->setIsActive($active);

        if (empty($data['ref_art_categ'])) {
            $cat->setUrlKey($cleanurl)
                ->save();
        }

        $cat->setPath($parentCateg->getPath() . '/' . $cat->getId());

        // Mise à jour avec changement de parent
        if (!empty($data['ref_art_categ']) && $oldParentId != $parentId) {
            $this->changeCategoryChildrenCount($oldParentId, $store_id);
            $this->changeCategoryChildrenCount($parentId, $store_id);
        }

        $cat->save();
        return $cat;
    }

    protected function getMaxPositionOfChildren($parentCateg, $store_id = null) {
        if (empty($store_id)) {
            $store_id = $this->getStoreId();
        }

        $maxPosition = 0;
        foreach (explode(',', $parentCateg->getChildren()) as $childId) {
            $childCateg = LmbManager::load(LmbManager::CATEGORY_MANAGER, $childId, $store_id);
            $maxPosition = max($maxPosition, $childCateg->getPosition());
        }
        return $maxPosition;
    }

    /**
     * changer la valeur de compte d'enfant de la catégorie
     * @param int $categoryId
     */
    protected function changeCategoryChildrenCount($categoryId, $store_id = null) {
        if (empty($store_id)) {
            $store_id = $this->getStoreId();
        }

        $cat = LmbManager::load(LmbManager::CATEGORY_MANAGER, $categoryId, $store_id);
        if ($cat && $cat->getId()) {
            $collection = $this->getObjectManager()->get('\Magento\Catalog\Model\CategoryFactory')
                ->create()
                ->getCollection()
                ->addAttributeToFilter('parent_id', array('eq' => $categoryId));

            $cat->setChildrenCount(count($collection));
            $cat->save();
        }
    }

    /**
     * création d'article ou de variante d'article
     *
     * @param array $data
     * @return boolean
     */
    public function create_article($data) {

        LmbEdi\trace("reception", "Debut de la création d'un article");

        $ref_lmb = $data['ref_article'];
        unset($data['ref_article']);
        $data["ref_lmb"] = $ref_lmb;
        $art = $this->setInfosArticle($data);

        if (!$art || !$art->getId()) {
            LmbEdi\trace_error('reception', 'creation article nok (pas de résultat) - ' . print_r($data, true));
            return false;
        }
        // type de liaison
        $type_liaison = self::LMB_CORRESPONDANCE_CODE_ARTICLE_SIMPLE;
        if (!empty($data["variante"])) {
            if ($data["variante"] == self::LMB_PRODUCT_TYPE_VARIABLE) {
                $type_liaison = self::LMB_CORRESPONDANCE_CODE_ARTICLE_VARIABLE;
            } else if ($data["variante"] == self::LMB_PRODUCT_TYPE_VARIATION) {
                $type_liaison = self::LMB_CORRESPONDANCE_CODE_ARTICLE_VARIATION;
            }
        }

        $this->createCorrespondanceLmb($ref_lmb, $art->getId(), $type_liaison);
        LmbEdi\trace("reception", "Fin de la création d'un article");
        return true;
    }

    public function update_article($data) {
        LmbEdi\trace("reception", "Debut de la modification d'un article");
        try {
            $art = $this->setInfosArticle($data);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            LmbEdi\trace_error('reception', 'update article nok (pas de résultat) - ' . print_r($data, true));
            return true;
        }
        if (!$art || !$art->getId()) {
            LmbEdi\trace_error('reception', 'update article nok (pas de résultat) - ' . print_r($data, true));
            return true;
        }
        LmbEdi\trace("reception", "Fin de la modification d'un article");
        return true;
    }

    public function update_stock_art($data) {
        $store_id = $this->getStoreId($data);
        
        if (!empty($data['ref_article'])) { //dans le cas d'une maj
            LmbEdi\trace("reception", " => Mise à jour d'article");
            $art = LmbManager::load(LmbManager::PRODUCT_MANAGER, $data['ref_article'], $store_id, LmbManager::OBJECT_FORMAT);

            if (empty($art->getId())) {
                LmbEdi\trace_error("reception", "Article " . $data['ref_article'] . " non trouvée, mise à jour impossible");
                return false;
            }
            $this->set_stock($art, $data);
            LmbEdi\trace("reception", "Stock de l'article " . $data['ref_article'] . " mis à jour");
            return true;
        }
        
        LmbEdi\trace_error("reception", "Infos incomplète, mise à jour du stock impossible");
        LmbEdi\trace_error("reception", print_r($data, true));
        return false;
    }

    public function update_art_liaisons($liaisons) {
        if (!array_key_exists('ref_article', $liaisons) || empty($liaisons['ref_article'])) {
            return true;
        }

        $product = $this->getObjectManager()->create('\Magento\Catalog\Model\Product')->load($liaisons['ref_article']);
        if (!$product->getId()) {
            return true;
        }

        $product->setProductLinks(null);
        $product->save();

        if (array_key_exists("liaisons", $liaisons)) {
            if (array_key_exists(\Magento\GroupedProduct\Model\ResourceModel\Product\Link::LINK_TYPE_GROUPED, $liaisons['liaisons']) && !empty($liaisons['liaisons'][\Magento\GroupedProduct\Model\ResourceModel\Product\Link::LINK_TYPE_GROUPED])) {
                if ($product->getTypeId() == Type::TYPE_SIMPLE) {
                    LmbEdi\trace("gestion_grouped", $liaisons['ref_article'] . " => Passage de SIMPLE à GROUPED");
                    $product->setTypeId(\Magento\GroupedProduct\Model\Product\Type\Grouped::TYPE_CODE);
                    $product->save();
                }
            } else {
                if ($product->getTypeId() == \Magento\GroupedProduct\Model\Product\Type\Grouped::TYPE_CODE) {
                    LmbEdi\trace("gestion_grouped", $liaisons['ref_article'] . " => Passage de GROUPED à SIMPLE");
                    $product->setTypeId(Type::TYPE_SIMPLE);
                    $product->save();
                }
            }

            $this->setArticleLiaisons($product, $liaisons['liaisons']);
        }
        return true;
    }

    public function update_art_tarif($datas) {
        if (empty($datas['ref_article'])) {
            LmbEdi\trace_error("reception", "param ref_article manquant");
            return true;
        }

        $this->updateTierPrices($datas);

        if (!empty($datas['tarifs_shops'])) {
            foreach ($datas['tarifs_shops'] as $id_website => $tarif_shop) {
                $tarif_shop = reset($tarif_shop);
                $this->updateTarifShop($id_website, $datas['ref_article'], $tarif_shop);
            }
        }

        return true;
    }

    protected function updateTierPrices($datas) {
        $ref_article = $datas['ref_article'];
        $art = $this->getObjectManager()->create('\Magento\Catalog\Model\Product')->load($ref_article);
        if (empty($art->getId())) {
            LmbEdi\trace_error("reception", "article non trouvé, impossible de mettre à jour");
            return true;
        }
        $art->setTierPrices([]);
        $art->save();
        if (empty($datas['tarifs'])) {
            LmbEdi\trace_error("reception", "tarifs non précisé");
            return true;
        }

        foreach ($datas['tarifs'] as $tarif) {
            $save_tierPrice = false;
            if (!empty($tarif['client_categ'])) {
                $customer_group = $tarif['client_categ'];
                $id_website = 0;
                $save_tierPrice = true;
            } else if (!empty($tarif['id_shop']) && $tarif['qte'] > 1) {
                $id_website = $tarif['id_shop'];
                $customer_group = \Magento\Customer\Api\Data\GroupInterface::CUST_GROUP_ALL;
                $save_tierPrice = true;
            }

            $price = $art->getTaxClassId() == 0 ? $tarif['pu_ht'] : $tarif['pu_ttc'];
            if (!empty($price) && $save_tierPrice) {
                $tierPriceFactory = $this->getObjectManager()->get("Magento\Catalog\Api\Data\ProductTierPriceInterfaceFactory");
                $tierPrice = $tierPriceFactory->create();
                $tierPrice->setCustomerGroupId($customer_group)
                    ->setQty((float)$tarif['qte'])
                    ->setValue((float)$price);

                $tierPrice->getExtensionAttributes()->setWebsiteId($id_website);
                $tierPriceManagement = $this->getObjectManager()->create("Magento\Catalog\Api\ScopedProductTierPriceManagementInterface");
                try {
                    $tierPriceManagement->add($art->getSku(), $tierPrice);
                } catch (\Throwable $e) {
                    LmbEdi\trace("save_tierPrice", "Erreur : " . json_encode(["message" => $e->getMessage(), "line" => $e->getLine(), "file" => $e->getFile()]));
                }
            }
        }
        return true;
    }

    protected function updateTarifShop($id_website, $ref_article, $tarif) {
        $ids_store = $this->getObjectManager()->create("\Magento\Store\Model\StoreManagerInterface")->getStoreByWebsiteId($id_website);
        if (!empty($ids_store)) {
            foreach ($ids_store as $id_store) {
                $product = $this->getObjectManager()->create('\Magento\Catalog\Model\Product')->setStoreId($id_store)->load($ref_article);
                $product->setStoreId($id_store);
                $special_price = null;
                $ht = $product->getTaxClassId() == 0;
                if ($ht) {
                    $price = $tarif['pu_ht'];
                    if (isset($tarif['pu_base_ht'])) {
                        $price = $tarif['pu_base_ht'];
                    }
                    if (isset($tarif['promo_ht'])) {
                        $special_price = $tarif['promo_ht'];
                    }
                } else {
                    $price = $tarif['pu_ttc'];
                    if (isset($tarif['pu_base_ttc'])) {
                        $price = $tarif['pu_base_ttc'];
                    }
                    if (isset($tarif['promo_ttc'])) {
                        $special_price = $tarif['promo_ttc'];
                    }
                }

                $debut_promo = $tarif['promo_debut'] ?? null;
                $fin_promo = $tarif['promo_fin'] ?? null;

                try {
                    $productFactory = $this->getObjectManager()->get('\Magento\Catalog\Model\ProductFactory')->create();
                    $productResourceModel = $this->getObjectManager()->get('\Magento\Catalog\Model\ResourceModel\Product')->load($productFactory, $product->getId());
                    $productFactory->setStoreId($id_store);
                    $productFactory->setPrice($price);
                    $productFactory->setSpecialPrice($special_price);
                    $productFactory->setSpecialFromDate($debut_promo);
                    $productFactory->setSpecialToDate($fin_promo);
                    $productResourceModel->saveAttribute($productFactory, "price");
                    $productResourceModel->saveAttribute($productFactory, "special_price");
                    $productResourceModel->saveAttribute($productFactory, "special_from_date");
                    $productResourceModel->saveAttribute($productFactory, "special_to_date");
                } catch (\Exception $e) {
                    LmbEdi\trace_error("update_tarif", "Erreur : " . json_encode(["message" => $e->getMessage(), "line" => $e->getLine(), "file" => $e->getFile()]));
                }
            }
        }

        return true;
    }

    protected function setArticleLiaisons($product, $liaisons) {
        try {
            $art = $this->getObjectManager()->create('Magento\Catalog\Model\Product')->load($product->getId());
            $links = [];
            $compteur = 1;
            foreach ($liaisons as $type => $liaison) {
                $linkType = false;
                switch ($type) {
                    case Link::LINK_TYPE_RELATED :
                        $linkType = "related";
                        break;
                    case \Magento\GroupedProduct\Model\ResourceModel\Product\Link::LINK_TYPE_GROUPED :
                        $linkType = "associated";
                        break;
                    case Link::LINK_TYPE_UPSELL :
                        $linkType = "upsell";
                        break;
                    case Link::LINK_TYPE_CROSSSELL :
                        $linkType = "crosssell";
                        break;
                }
                if (empty($liaison) || empty($linkType)) {
                    continue;
                }
                foreach ($liaison as $id_art) {
                    $linkProduct = $this->getObjectManager()->create('Magento\Catalog\Model\Product')->load($id_art);
                    if (!$linkProduct->getId() || !in_array($type, [Link::LINK_TYPE_RELATED, \Magento\GroupedProduct\Model\ResourceModel\Product\Link::LINK_TYPE_GROUPED, Link::LINK_TYPE_UPSELL, Link::LINK_TYPE_CROSSSELL])) {
                        continue;
                    }
                    $productLinks = $this->getObjectManager()->create("\Magento\Catalog\Api\Data\ProductLinkInterface");
                    $link = $productLinks;
                    $links[] = $link->setSku($art->getSku())
                        ->setLinkedProductSku($linkProduct->getSku())
                        ->setLinkType($linkType)
                        ->setPosition($compteur);
                    $compteur++;
                }
            }
            if (!empty($links)) {
                $art->setProductLinks($links);
                $art->save();
            }

        } catch (\Exception $e) {
            LmbEdi\trace_error("save_liaison", "Erreur : " . json_encode(["message" => $e->getMessage(), "line" => $e->getLine(), "file" => $e->getFile()]));
        }

        return true;
    }

    public function delete_article($data) {
        $artId = $data['ref_article'];
        $store_id = $this->getStoreId($data);
        LmbEdi\trace('reception', "DEBUT de la suppression d'un article " . $artId . "*******************");

        try {
            $art = LmbManager::load(LmbManager::PRODUCT_MANAGER, $artId, $store_id);
        } catch (\Exception $e) {
            $art = null;
        }

        if (!$art || !$art->getId()) {
            LmbEdi\trace('reception', 'pas d\article à supprimer ' . $artId . '`');
            LmbEdi\trace_error('reception', 'pas d\article à supprimer ' . $artId . '`');
            return true;
        }
        LmbEdi\trace("reception", "DELETE");

        //Visibilité
        // 1 => Non
        // 2 => Catalogue
        // 3 => Recherche
        // 4 => Les deux
        $art->setVisibility(1);
        // Dispo
        // 1 => enable
        // 2 => disable
        $art->setStatus(2);
        $this->saveArticle($art);

        LmbEdi\trace("reception", "FIN de la suppression d'un article " . $artId . "*******************");
        return true;
    }

    public function update_client($client) {
        if (empty($client) || empty($client['ref_contact']) || empty($client['id_client_categorie'])) {
            return true;
        }
        LmbEdi\trace('reception', "DEBUT UPDATE CLIENT " . $client['ref_contact'] . "*******************");

        try {
            $customer = $this->getObjectManager()->get("\Magento\Customer\Model\Customer")->load($client['ref_contact']);
            if (!$customer->getId()) {
                LmbEdi\trace('reception', "client inconnu");
                return true;
            }

            $customerGroup = $this->getObjectManager()->create("\Magento\Customer\Model\Group")->load($client['id_client_categorie']);
            if (!$customerGroup->getId()) {
                LmbEdi\trace('reception', "groupe de client " . $client['id_client_categorie'] . " inconnu");
                return true;
            }
            $customer->setData('ignore_validation_flag', true);
            $customer->setGroupId($client['id_client_categorie']);
            $customer->save();
        } catch (\Exception $exception) {
            LmbEdi\trace("debug_client", "Erreur : " . json_encode(["message" => $exception->getMessage(), "line" => $exception->getLine(), "file" => $exception->getFile()]));
            return true;
        }
        LmbEdi\trace('reception', "***************FIN UPDATE CLIENT " . $client['ref_contact'] . "****************");
        return true;
    }


    /**
     *
     *
     * @param \Magento\Catalog\Model\Product $art
     * @param array $data
     * @return \Magento\Catalog\Model\Product
     */
    protected function setInfosArticle(array $data) {
        $store_id = $this->getStoreId($data);
        LmbEdi\trace("reception", '$DATA ' . print_r($data, 1));
        //creation de l'objet $art
        if (!empty($data['ref_article'])) { //dans le cas d'une maj
            LmbEdi\trace("reception", " => Mise à jour d'article");
            $art = LmbManager::load(LmbManager::PRODUCT_MANAGER, $data['ref_article'], $store_id, LmbManager::OBJECT_FORMAT);

            if (empty($art->getId())) {
                LmbEdi\trace("reception", "Article " . $data['ref_article'] . " non trouvée, mise à jour impossible");
                return false;
            }
            $sku = $this->checkSku($art, $data);
            if (empty($sku)) {
                LmbEdi\trace_error("reception", "SKU invalide, impossible de mettre à jour l'article");
                LmbEdi\trace_error("reception", print_r($data, true));
                return false;
            }
            if ($sku != $art->getSku()) {
                $art->setSku($sku);
                $art->forceManagerFormat(LmbManager::OBJECT_FORMAT);
                $art->save();
                $art = LmbManager::load(LmbManager::PRODUCT_MANAGER, $data['ref_article'], $store_id, LmbManager::OBJECT_FORMAT);
            }
        } else { //dans le cas d'une création
            $art = LmbManager::create(LmbManager::PRODUCT_MANAGER);
            LmbEdi\trace("reception", " => Création d'article");

            $sku = $this->checkSku($art, $data);
            if (empty($sku)) {
                LmbEdi\trace_error("reception", "SKU vide, impossible de créer l'article");
                LmbEdi\trace_error("reception", print_r($data, true));
                return false;
            }

            $art->setSku($sku);
        }

        $this->set_identite($art, $data);
        $art->setStoreId($store_id);
        $this->set_etat($art, $data);
        $this->set_prix($art, $data);
        // Les catégories a enlever doivent l'être après le save, mais la liste doit être récupérée avant les ajouts
        $current_categs = $art->getCategoryIds();
        $this->set_categ($art, $data, $current_categs);
        $this->set_detail($art, $data);

        $this->set_caracs($art, $data);
        $this->setWebsitesIds($art, $data);
        $this->saveArticle($art, $store_id);

        $this->set_stock($art, $data);
        try {
            $this->set_groupe($art, $data);
        }
        catch (\Magento\Framework\Exception\InputException $e) {
            $message = "Mise à jour de l'article ".$data['ref_article']." ignoré";
            if (!empty($data['ref_article_parent'])) {
                $message .= ", le groupe de l'article ".$data['ref_article_parent']." a des doublons";
            }
            LmbEdi\trace_error("reception_exception", $message);
            LmbEdi\trace_error("reception_exception", $e->getMessage());
            return false;
        }
        $this->handleInfosMultiStore($art, $data);
        $this->clean_categ($art, $data, $current_categs);

        return $art;
    }
   
    protected function checkSku($art, $data, $use_ref_lmb = false) {
        //SKU
        if ($use_ref_lmb) {
            $sku = !empty($data["ref_lmb"]) ? $data["ref_lmb"] : $data['reference'];
        } else {
            $sku = $data['reference'];
        }
        $sku = trim($sku);

        if (!empty($sku)) {
            $art_sku = $this->getObjectManager()->create('Magento\Catalog\Model\Product')
                ->loadByAttribute('sku', $sku);

            if (!empty($art_sku) && $art_sku->getId() && $art_sku->getId() != $art->getId()) {
                $sku = "";
            }
        }

        if (empty($sku) && !$use_ref_lmb) {
            $current_sku = $art->getSku();
            $sku = !empty($current_sku) ? $current_sku : $this->checkSku($art, $data, true);
        }

        return $sku;
    }

    protected function addAttributeToAttributeSet($attribute_set_id, $attribute_id) {
        $eavSetupFactory = $this->getObjectManager()->create('Magento\Eav\Setup\EavSetupFactory');
        $setup = $this->getObjectManager()->create('Magento\Setup\Module\DataSetup');
        $eavSetup = $eavSetupFactory->create(['setup' => $setup]);

        $eavSetup->addAttributeToSet(Product::ENTITY, $attribute_set_id, "General", $attribute_id);
    }

    protected function set_groupe($art, $data) {
        if (!empty($data['variante']) && $data['variante'] == self::LMB_PRODUCT_TYPE_VARIATION) {
            $configurableProduct = LmbManager::load(LmbManager::PRODUCT_MANAGER, $data['ref_article_parent'], $art->getStoreId());
            $currents_products = $configurableProduct->getTypeInstance()->getChildrenIds($configurableProduct->getId());
            if (!empty($currents_products[0]) && is_array($currents_products[0])) {
                $currents_products = $currents_products[0];
            }

            if (!empty($data['caracs_variantes'])) {
                foreach ($data['caracs_variantes'] as $id_lmb => $infos_attr) {
                    $attr_code = !empty($infos_attr["ref_carac"]) ? $infos_attr["ref_carac"] : null;
                    $infos_attr["id_lmb"] = $id_lmb;
                    if (empty($infos_attr['val_carac'])) {
                        $infos_attr['val_carac'] = "-";
                    }
                    $attribute = $this->getAttribute($attr_code, $infos_attr);

                    $this->updateProductAttribute($art, $attribute->getAttributeCode(), $infos_attr['val_carac'], $this->getStoreId($data));

                    $this->addAttributeToAttributeSet($art->getAttributeSetId(), $attribute->getAttributeId());
                }
            }

            $art->save();

            if ($configurableProduct->getTypeId() != Configurable::TYPE_CODE) {
                $configurableProduct->setTypeId(Configurable::TYPE_CODE)
                    ->setStoreId($this->getStoreId($data))
                    ->save();
            }

            $products_list = !empty($currents_products) ? array_filter($currents_products) : array();
            $products_list[] = $art->getId();
            $products_list = array_unique($products_list);
            $configurableProduct->setAssociatedProductIds($products_list);

            $this->saveArticle($configurableProduct);
        }
    }

    protected function checkAttributeForProduct($attribute, $art) {
        if ($art->getTypeId() != Configurable::TYPE_CODE) {
            // Pas de check nécessaire si l'article n'est pas un article parent
            return true;
        }
        
        if (empty($attribute) || empty($attribute->getAttributeCode())) {
            return false;
        }
        
        $configurable = $art->getTypeInstance();
        $attr_checker = $this->getObjectManager()
            ->get(\Magento\Catalog\Api\ProductAttributeRepositoryInterface::class)
            ->get($attribute->getAttributeCode());

        if ($configurable->canUseAttribute($attr_checker)) {
            return true;
        }

        return false;
    }

    protected function set_caracs($art, $data) {
        // autres caractéristiques non déclinables
        foreach ($data['caracs_non_variantes'] as $id_lmb => $infos_attr) {
            $attr_code = !empty($infos_attr["ref_carac"]) ? $infos_attr["ref_carac"] : null;
            $infos_attr["id_lmb"] = $id_lmb;
            $attribute = $this->getAttribute($attr_code, $infos_attr, false);

            if ($this->checkAttributeForProduct($attribute, $art)) {
                $this->addAttributeToAttributeSet($art->getAttributeSetId(), $attribute->getAttributeId());
                $this->updateProductAttribute($art, $attribute->getAttributeCode(), $infos_attr['val_carac'], $this->getStoreId($data));
            }
        }

        if (!empty($data['caracs_variantes']) && !empty($data["variante"]) && $data['variante'] == self::LMB_PRODUCT_TYPE_VARIABLE) {

            $attributes_list = array();
            foreach ($data['caracs_variantes'] as $id_lmb => $infos_attr) {
                $attr_code = !empty($infos_attr["ref_carac"]) ? $infos_attr["ref_carac"] : null;
                $infos_attr["id_lmb"] = $id_lmb;
                $attribute = $this->getAttribute($attr_code, $infos_attr);
                if (empty($attribute) || empty($attribute->getAttributeId())) {
                    continue;
                }

                if ($this->checkAttributeForProduct($attribute, $art)) {
                    $attributes_list[$attribute->getAttributeId()] = $attribute;
                }
            }

            $art->setCanSaveConfigurableAttributes(true);
            if ($art->getTypeId() != Configurable::TYPE_CODE) {
                $art->setTypeId(Configurable::TYPE_CODE)
                    ->setStoreId($this->getStoreId($data))
                    ->save();
            }

            $configurable = $art->getTypeInstance();
            $this->getObjectManager()->create('Magento\ConfigurableProduct\Model\Product\Type\Configurable')
                ->setUsedProductAttributeIds(array_keys($attributes_list), $art);
            $configurableAttributesData = $configurable->getConfigurableAttributesAsArray($art);
            $art->setNewVariationsAttributeSetId($art->getAttributeSetId());
            $art->setConfigurableAttributesData($configurableAttributesData);
            $configurable->save($art->getManagerElement(LmbManager::OBJECT_FORMAT));
            $art->save();
        }

        if(!empty($data['personnalisations'])) {
            $art->setTypeId(Type::TYPE_SIMPLE);
            $art->setOptions([])->save();
            $product = $this->getObjectManager()->create('Magento\Catalog\Model\Product')->load($art->getId());
            $product->setHasOptions(1);
            $product->setCanSaveCustomOptions(true);
            $custom_options = $product->getOptions();

            // type_lmb => type_mg2 OU type_lmb => [affichage_lmb => type_mg2]
            $correspondances_types = [
                "input_simple" => "field",
                "textarea" => "area",
                "html" => "area",
                "number" => "field",
                "selection_simple" => [
                    "radio" => "radio",
                    "select" => "drop_down"
                ],
                "selection_multiple" => [
                    "select" => "multiple",
                    "radio" => "checkbox",
                    "checkbox" => "checkbox"
                ],
                "date" => "date"
            ];

//            $this->delete_old_options($custom_options, $data['personnalisations']);

            foreach($data['personnalisations'] as $id_personnalisation => $personnalisation) {
                $updated_option = false;
                $option_sku = $personnalisation['ref_carac'] ?? 'carac_perso_' . $id_personnalisation;
                $type = $correspondances_types[$personnalisation['type']];

                // Types particuliers
                if(is_array($type)) {
                    $type = $correspondances_types[$personnalisation['type']][$personnalisation['affichage']];
                }

                $valeurs = [];
                $i = 1;
                foreach($personnalisation['val_carac'] as $valeur) {
                    $valeurs[] = [
                        'title' => $valeur,
                        'value' => $valeur,
                        'price' => 0.00,
                        'price_type' => 'fixed',
                        'sku' => $option_sku . "_" . $i,
                        "is_delete" => 0,
                        "sort_order" => $i
                    ];

                    $i++;
                }

                // On vérifie que l'option n'existe pas déjà pour l'update
                foreach($custom_options as $custom_option) {
                    if($custom_option->getSku() == $option_sku) {
                        $custom_option->setTitle($personnalisation['lib_carac'])
                            ->setType($type)
                            ->setIs_require($personnalisation['required'])
                            ->setValues($valeurs);
                        $custom_option->save();

                        $updated_option = true;
                    }
                }

                if($updated_option) {
                    continue;
                }

                // Si elle n'existe pas on la créé
                $option = [
                    "title" => $personnalisation['lib_carac'],
                    "type" => $type,
                    "is_require" => $personnalisation['required'],
                    "price" => "0.00",
                    "price_type" => "fixed",
                    "max_characters" => $personnalisation['max_characters'] ?? null,
                    "sku" => $option_sku
                ];

                if(!empty($valeurs)) {
                    $option['values'] = $valeurs;
                }

                try {
                    /** @var \Magento\Catalog\Api\Data\ProductCustomOptionInterface $customOption */
                    $customOption = $this->getObjectManager()->create("\Magento\Catalog\Model\Product\Option")
                        ->setProductId($product->getId())
                        ->setStoreId($product->getStoreId())
                        ->addData($option);
                    $customOption->save();
                    $art->addOption($customOption);
                } catch(\Throwable $e) {
                    LmbEdi\trace("save_personnalisations", "Erreur : " . json_encode(["message" => $e->getMessage(), "line" => $e->getLine(), "file" => $e->getFile()]));
                }
            }
        }
        else {
            $product = $this->getObjectManager()->create('Magento\Catalog\Model\Product')
                    ->load($art->getId());
            $custom_options = $product->getOptions();
            // Si il n'y a pas de customisation dans le message mais qu'il y en a sur l'article, on les supprime
            if (!empty($custom_options)) {
                $art->setOptions([])->save();
                $product->setHasOptions(0);
                $product->setCanSaveCustomOptions(false);
            }
        }

        return $art;
    }

    protected function delete_old_options($custom_options, $personnalisations) {
        return true;
        if (empty($custom_options)) {
            return true;
        }

        $options_add = [];
        foreach ($personnalisations as $id_carac => $personnalisation) {
            $option_sku = $personnalisation['ref_carac'] ?? 'carac_perso_' . $id_carac;
            $options_add[] = $option_sku;
        }
        LmbEdi\trace("debug_option", 'options_add > ' . json_encode($options_add) );
        foreach ($custom_options as $option) {
            LmbEdi\trace("debug_option", 'getSku > ' . $option->getSku());
            if (!in_array($option->getSku(), $options_add)) {
                LmbEdi\trace("debug_option", 'getId > ' . $option->getId());
//                $option->delete();
//                $option->getValueInstance()->deleteValue($option->getId());
            }
        }
        return true;
    }

    protected function set_categ($art, $data, $current_categs) { //$art = l'objet article    $data = {ref_art_categs,ref_art_categ}
        //Catégorie
        if (!empty($data['ref_art_categs'])) {
            $categ = $data['ref_art_categs'];
        } else if (!empty($data['ref_art_categ'])) {
            $categ = array($data['ref_art_categ']);
        }
        
        $categs_add = array();
        foreach ($categ as $c) {
            if (!in_array($c, $current_categs)) {
               $categs_add[] = $c;
            }
        }
        
        if (!empty($categs_add)) {
            $art->setCategoryIds($categs_add);
        }
        
        return $art;
    }

    protected function setWebsitesIds($art, $data) {
        if (!empty($data['ref_art_categs'])) {
            $categ = $data['ref_art_categs'];
        } else if (!empty($data['ref_art_categ'])) {
            $categ = array($data['ref_art_categ']);
        }

        $ids_website = $this->getIds_website($categ);
        LmbEdi\trace("debug_update_ws", 'id_websites > ' . print_r($ids_website, true));
        $art->setWebsiteIds($ids_website);
        //$this->saveArticle($art, null, true);
        return $art;
    }

    protected function getIds_website($ids_categ) {
        $ids_website = [];
        $ids_root_categ = $this->getIdsRootCateg();
        foreach ($ids_categ as $id_categ) {
            $id_categ_parent = $this->getId_categ_parent($id_categ);
            if (!empty($id_categ_parent) && array_key_exists($id_categ_parent, $ids_root_categ)) {
                $ids_website[] = $ids_root_categ[$id_categ_parent];
            }
        }

        $ids_website = array_unique($ids_website);
        if (empty($ids_website)) {
            return [1];
        }

        return $ids_website;
    }

    protected function getIdsRootCateg() {
        $ids_root_categ = [];
        $stores = $this->getStores();
        foreach ($stores as $store) {
            $id_categ = $store->getGroup()->getRootCategoryId();
            if (!array_keys($id_categ, $ids_root_categ)) {
                $ids_root_categ[$id_categ] = $store->getGroup()->getWebsiteId();
            }
        }
        return $ids_root_categ;
    }

    protected function getId_categ_parent($id_categ) {
        $cat = LmbManager::load(LmbManager::CATEGORY_MANAGER, $id_categ);
        if (!$cat->getId()) {
            return false;
        }
        $id_categ_parent = $cat->getParentId();
        if (empty($id_categ_parent) || $id_categ_parent == Category::TREE_ROOT_ID) {
            return $id_categ;
        }

        return $this->getId_categ_parent($id_categ_parent);
    }

    protected function clean_categ($art, $data, $current_categs) { //$art = l'objet article    $data = {ref_art_categs,ref_art_categ}
        //Catégorie
        if (!empty($data['ref_art_categs'])) {
            $categ = $data['ref_art_categs'];
        } else if (!empty($data['ref_art_categ'])) {
            $categ = array($data['ref_art_categ']);
        }

        $store_id = $this->getStoreId($data);
        $emul = $this->getObjectManager()->get('\Magento\Store\Model\App\Emulation');
        $emul->startEnvironmentEmulation($store_id, \Magento\Framework\App\Area::AREA_ADMINHTML);
        $categ_repo = $this->getObjectManager()->get('\Magento\Catalog\Model\CategoryLinkRepository');
        foreach ($current_categs as $current_categ) {
            if (!in_array($current_categ, $categ)) {
                try {
                    $categ_repo->deleteByIds($current_categ, $art->getSku());
                } catch (\Exception $exception) {
                    LmbEdi\trace_error('delete_categ', "impossible  deleteByIds id : " . $current_categ . $exception->getMessage());
                }
            }
        }
        $emul->stopEnvironmentEmulation();

        return $art;
    }

    protected function set_detail($art, $data) { //$art = l'objet article    $data = {poids,desc_courte,desc_longue}
        if (isset($data['desc_courte'])) {
            $art->setShortDescription($data['desc_courte']);
        }
        if (isset($data['desc_longue'])) {
            $art->setDescription($data['desc_longue']);
        }
        if (!empty($data['poids'])) {
            $art->setWeight($data['poids']);
        }

        if (!empty(\lmbedi_config::GET_PARAM('marque_code'))) {
            if (!empty($data['id_lmb'])) {
                $opt = $this->getAttributeOption(\lmbedi_config::GET_PARAM('marque_code'), $data['marque']);
                if (!empty($opt) && !empty($opt->getValue())) {
                    $data['id_marque'] = $opt->getValue();
                } else {
                    $data['id_marque'] = $this->addAttributeOption(\lmbedi_config::GET_PARAM('marque_code'), $data['marque']);
                }
                $this->createCorrespondanceLmb($data['id_lmb'], $data['id_marque'], 13);
            }
            $art->setAttributeText(\lmbedi_config::GET_PARAM('marque_code'), $data['marque']);
            $art->setData(\lmbedi_config::GET_PARAM('marque_code'), $data['id_marque']);
        }


        return $art;
    }

    /* https://inchoo.net/magento/programming-magento/programatically-manually-creating-simple-magento-product/ */

    protected function set_prix($art, $data) { //$art = l'objet article    $data = {pa_ht,pu_ht,tarifs,tva}
        $tva_class = $this->get_tax_class_id($data['tva']);
        LmbEdi\trace("reception", "TVAid = " . print_r($tva_class, 1));
        $art->setTaxClassId($data['tva'] ? $tva_class : 0);
        $pa_code = \lmbedi_config::GET_PARAM('prix_achat_code');
        if (!empty($pa_code)) {
            $function_prix_achat = "set" . ucfirst($pa_code);
            $art->$function_prix_achat($data['pa_ht']);
        }
        $force_ttc = true;
        if ($force_ttc && $data['tva']) {
            $price = $data['pu_ht'] * (1 + $data['tva'] / 100);
            $art->setPrice($price);
        } else {
            $art->setPrice($data['pu_ht']);
        }
        if (isset($data["promos"])) {
            if (!empty($data["promos"])) {
                $promo = $data["promos"][0];
                if (isset($promo['pu_ht'])) {
                    if ($force_ttc) {
                        $art->setSpecialPrice($promo['pu_ht'] * (1 + $data['tva'] / 100));
                    } else {
                        $art->setSpecialPrice($promo['pu_ht']);
                    }

                    if (isset($promo['debut_promo'])) {
                        $art->setSpecialFromDate($promo['debut_promo']);
                    } else {
                        $art->setSpecialFromDate(null);
                    }
                    if (isset($promo['fin_promo'])) {
                        $art->setSpecialToDate($promo['fin_promo']);
                    } else {
                        $art->setSpecialToDate(null);
                    }
                }
            } else {
                $art->setSpecialPrice(null);
                $art->setSpecialFromDate(null);
                $art->setSpecialToDate(null);
            }
        }

        return $art;
    }

    protected function get_tax_class_id($tax_rate) { // Methode a améliorer avec le gestionnaire de collections.
        $ressource = $this->getObjectManager()->create('Magento\Framework\App\ResourceConnection');
        $connexion = $ressource->getConnection();
        $table_tax_class = $ressource->getTableName('tax_class');
        $table_tax_calculation = $ressource->getTableName('tax_calculation');
        $table_tax_calculation_rate = $ressource->getTableName('tax_calculation_rate');
        $sql = "SELECT class_id FROM " . $table_tax_class . " tcl "
            . "LEFT JOIN " . $table_tax_calculation . " tc ON tc.product_tax_class_id = tcl.class_id "
            . "LEFT JOIN " . $table_tax_calculation_rate . " tcr ON tcr.tax_calculation_rate_id = tc.tax_calculation_rate_id "
            . "WHERE tcr.tax_country_id = 'FR' AND tcr.rate='" . $tax_rate . "'"
            . " GROUP BY class_id";
        $data = $connexion->fetchAll($sql);
        return (!empty($data[0]["class_id"]) ? $data[0]["class_id"] : NULL);
    }

    protected function set_identite($art, $data) { //$art = l'objet article    $data = {code_barre,reference,lib_article}
        //SKU
        $sku = trim($data['reference']);

        if (!empty($sku)) {
            $art_sku = $this->getObjectManager()->create('Magento\Catalog\Model\Product')
                ->loadByAttribute('sku', $sku);

            if (!empty($art_sku) && $art_sku->getId() && $art_sku->getId() != $art->getId()) {
                $sku = "";
            }
        }

        if (empty($sku)) {
            $sku = !empty($data["ref_lmb"]) ? $data["ref_lmb"] : $art->getSku();
        }

        $code_barre_code = \lmbedi_config::GET_PARAM('code_barre_code');
        if (!empty($code_barre_code)) {
            $function_code_barre = "set" . ucfirst($code_barre_code);
            $art->$function_code_barre($data['code_barre']);
        }

        if (!empty($data['code_barre']) && empty($art->getBarcode())) {
            $art->setBarcode($data['code_barre']);
        }

        $art->setName($data['lib_article']);
        return $art;
    }

    protected function set_etat($art, $data) { //$art = l'objet article    $data = {variante,dispo,active,visible,attributes_set}
        //type
        $type = Type::TYPE_SIMPLE; // type simple ou enfant
        if (!empty($data['variante']) && $data['variante'] == self::LMB_PRODUCT_TYPE_VARIABLE) {
            $type = Configurable::TYPE_CODE;
        }
        //Visibilité
        // 1 => Non
        // 2 => Catalogue
        // 3 => Recherche
        // 4 => Les deux
        $visibility = 4;
        if ((isset($data['visible']) && empty($data['visible'])) || (!empty($data['variante']) && $data['variante'] == self::LMB_PRODUCT_TYPE_VARIATION)) {
            $visibility = 1;
        }
        //status
        $status_lmb = 0;
        if (isset($data['active'])) {
            $status_lmb = $data['active'];
        } else if (isset($data['dispo'])) {
            $status_lmb = $data['dispo'];
        }
        $status = 2 - $status_lmb; // pareil ici enable/disable = 1/2 chez Magento
        //jeu d'attribut
        $id_attributes_set = $this->getAttributesSetFromInfos($data);
        if (empty($id_attributes_set)) {
            $id_attributes_set = '';
        }

        $art->setVisibility($visibility);
        $art->setTypeId($type);
        $art->setStatus($status);
        $art->setVariante($data['variante']);
        $art->setAttributeSetId($id_attributes_set);

        return $art;
    }

    protected function set_stock($art, $data) { //$art = l'objet article    $data = {qte}
        if ($art->getTypeId() == Configurable::TYPE_CODE) {
            return;
        }

        $vente_hors_stock = \lmbedi_config::GET_PARAM('vente_hors_stock');
        if (empty($vente_hors_stock)) {
            $vente_hors_stock = 0;
        }

        if (empty($data['qte'])) {
            $data['qte'] = 0;
        }

        if ($data['qte'] > 0 || $vente_hors_stock) {
            $en_stock = 1;
        } else {
            $en_stock = 0;
        }

        $version_magento = $this->getObjectManager()
            ->get('\Magento\Framework\App\ProductMetadataInterface')
            ->getVersion();

        // Version 2.4+
        if (version_compare($version_magento, "2.4", ">=")) {
            $ItemsSave = $this->getObjectManager()->create('\Magento\InventoryApi\Api\SourceItemsSaveInterface');
            $ItemFactory = $this->getObjectManager()->create('Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory');

            $sourceItem = $ItemFactory->create();
            $sourceItem->setSourceCode('default');
            $sourceItem->setSku($art->getSku());
            $sourceItem->setQuantity($data['qte']);
            $sourceItem->setStatus($en_stock);
            $ItemsSave->execute([$sourceItem]);
        } else {
            //Version antérieure
            //Recommendé d'utiliser le StockRegistery --> Car anciennes méthodes dépréciées
            $stockRegistery = $this->getObjectManager()->create('\Magento\CatalogInventory\Api\StockRegistryInterface');
            $art_sku = $art->getSku();
            $stockItem = $stockRegistery->getStockItemBySku($art_sku);
            $stockItem->setQty((int)$data['qte']);
            $stockItem->setIsInStock($en_stock);
            $stockItem->setUseConfigManageStock(1);
            $stockItem->setManageStock(1);
            $stockItem->save();
            $stockRegistery->updateStockItemBySku($art_sku, $stockItem);
        }
    }

    ///////////////
    //           //
    // Attribute //
    //           //
    ///////////////
    //non variant
    protected function productAddOrUpdateAttribute($art, $ref, $lib, $ref_carac, $value, $store_id = null) {
        //l'attribut existe-t-il ?
        $exist = $this->getObjectManager()->get('\Magento\Eav\Model\Entity\Attribute')->loadByCode('catalog_product', $ref);

        $attributeInfo = $this->getObjectManager()->get('\Magento\Eav\Model\Entity\Attribute')
            ->loadByCode('catalog_product', $ref_carac);
        $attributeId = $attributeInfo->getAttributeId();
        if (empty($exist->getData())) {
            LmbEdi\trace("event", " création... Attribut non existant : " . $ref_carac);
            $newAttributeId = $this->createAttribute($lib, $ref_carac, $art);
            $this->createCorrespondanceLmb($ref, $ref_carac, 10);
            LmbEdi\trace("event", "Attribut crée : " . print_r($exist->getData(), 1));
        } else {
            LmbEdi\trace("event", "déja créé Lib : " . $ref_carac);
        }
        $this->updateProductAttribute($art, $ref_carac, $value, $store_id);
        return $art;
    }

    protected function updateProductAttribute($art, $attributeCode, $value, $store_id = null) {
        $attribute = $this->getAttribute($attributeCode);
        $type = $attribute->getFrontendInput();

        switch ($type) {
            case "select" :
                $opt = $this->getAttributeOption($attributeCode, $value);
                $value = $opt->getValue();
                break;
            case "multiselect" :
                $values = explode(";", $value);
                $value = array();
                foreach ($values as $val) {
                    $opt = $this->getAttributeOption($attributeCode, $val);
                    $value[] = $opt->getValue();
                }
                break;
        }

        $art->setData($attributeCode, $value);
        //$this->saveArticle($art, $store_id);
    }

    protected function doesProductAttributeHaveOption($productId, $attrCode, $optionValue) {
        $att = array();
        $att = $this->getProductAttribute($productId, $attrCode);
        return $att[1] == $optionValue;
    }

    protected function doesAttributeHaveOption($attrCode, $optionValue) {
        $options = $this->getObjectManager()->get('\Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\Collection')
            ->setPositionOrder('asc')
            ->setAttributeFilter($attrCode)
            ->setStoreFilter()
            ->load();
        if (count($options) && isset($options[$optionValue])) {
            return true;
        }
        return false;
    }

    protected function getAttribute($attr_code, $infos_creation = null, $carac_variante = true) {
        $attributeId = 0;
        $attribute = null;

        if (!empty($attr_code) && is_numeric($attr_code)) {
            $attributeModel = $this->getObjectManager()->create('Magento\Catalog\Model\ResourceModel\Eav\Attribute');
            $attributeById = $attributeModel->load($attr_code);
            if (!empty($attributeById) && !empty($attributeById->getAttributeCode())) {
                $attr_code = $attributeById->getAttributeCode();
            }
        }
        
        if (!empty($attr_code)) {
            $attribute = $this->getObjectManager()->get('\Magento\Eav\Model\Entity\Attribute')
                ->loadByCode('catalog_product', $attr_code);
            if (!empty($attribute) && $attribute->getAttributeCode() == $attr_code) {
                $attributeId = $attribute->getAttributeId();
            }
        }

        if (empty($attributeId) && !empty($infos_creation["id_lmb"])) {
            $attribute = $this->getObjectManager()->get('\Magento\Eav\Model\Entity\Attribute')
                ->loadByCode('catalog_product', "attribut_".$infos_creation["id_lmb"]);
            if (!empty($attribute) && $attribute->getAttributeCode() == "attribut_".$infos_creation["id_lmb"]) {
                $attributeId = $attribute->getAttributeId();
            }
        }

        if (empty($attributeId) && !empty($infos_creation["id_lmb"])) {
            $attribute = $this->newAttribute($infos_creation["lib_carac"], $infos_creation["id_lmb"], $carac_variante);
        }

        return $attribute;
    }

    /**
     * @basé sur le createAttribute
     * @param type $lib
     * @return type
     */
    protected function newAttribute($lib, $id_lmb, $carac_variante) {
        $code = "attribut_$id_lmb";
        $eavSetupFactory = $this->getObjectManager()->create('Magento\Eav\Setup\EavSetupFactory');
        $setup = $this->getObjectManager()->create('Magento\Setup\Module\DataSetup');
        $eavSetup = $eavSetupFactory->create(['setup' => $setup]);
        $att = [
            'type' => 'text',
            'backend' => '',
            'frontend' => '',
            'label' => $lib,
            'input' => $carac_variante ? "select" : 'text',
            'class' => '',
            'source' => '',
            'global' => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
            'visible' => true,
            'required' => false,
            'user_defined' => $carac_variante ? true : false,
            'default' => '',
            'searchable' => false,
            'filterable' => (boolean)$carac_variante,
            'comparable' => false,
            'visible_on_front' => true,
            'used_in_product_listing' => true,
            'unique' => false,
            'apply_to' => ''
        ];

        $eavSetup->addAttribute(Product::ENTITY, $code, $att);
        $attribute = $this->getObjectManager()->get('\Magento\Eav\Model\Entity\Attribute')
            ->loadByCode('catalog_product', $code);

        $this->createCorrespondanceLmb($id_lmb, $attribute->getAttributeCode(), $carac_variante ? 9 : 10);

        return $attribute;
    }

    protected function getAttributeOption($attributeCode, $attributeValue) {
        $eavConfig = $this->getObjectManager()->create('\Magento\Eav\Model\Config');
        $attributeOptionManagement = $this->getObjectManager()->create('\Magento\Eav\Api\AttributeOptionManagementInterface');

        $attr = $this->getObjectManager()->get(\Magento\Catalog\Api\ProductAttributeRepositoryInterface::class)->get($attributeCode);
        $id_option = 0;
        $find = false;
        foreach ($attr->getOptions() as $option) {
            $option_id = $option->getValue();
            $option_label = $option->getLabel();
            // is_string permet de gérer le cas "0" que PHP considère empty
            if ((!empty($option_label) || is_string($option_label)) && trim(strtolower($attributeValue)) == trim(strtolower($option_label))) {
                $id_option = $option->getValue();
                $find = true;
                break;
            }
        }

        if (empty($find) && empty($id_option)) {
            $option = $this->getObjectManager()->create('\Magento\Eav\Api\Data\AttributeOptionInterface');
            $option->setLabel($attributeValue);
            $add = $attributeOptionManagement->add(
                \Magento\Catalog\Model\Product::ENTITY, $this->getAttribute($attributeCode)->getAttributeId(), $option
            );

            $id_option = $option->getValue();
        }

        return $option;
    }

    protected function addAttributeOption($attributeCode, $attributeValue, $store_id = null) {
        $eavConfig = $this->getObjectManager()->create('\Magento\Eav\Model\Config');
        $attributeOptionManagement = $this->getObjectManager()->create('\Magento\Eav\Api\AttributeOptionManagementInterface');
        $optionLabel = $this->getObjectManager()->create('\Magento\Eav\Api\Data\AttributeOptionLabelInterface');
        $option = $this->getObjectManager()->create('\Magento\Eav\Api\Data\AttributeOptionInterface');

        $optionLabel->setStoreId($this->getStoreId($store_id));
        $optionLabel->setLabel($attributeValue);

        $option->setLabel($attributeValue);

        $attributeOptionManagement->add(
            \Magento\Catalog\Model\Product::ENTITY, $this->getAttribute($attributeCode)->getAttributeId(), $option
        );

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $mrq = $objectManager->get(\Magento\Catalog\Api\ProductAttributeRepositoryInterface::class)->get($attributeCode);
        $value = 0;
        foreach ($mrq->getOptions() as $option) {
            if (!empty($option->getLabel()) && !empty($option->getValue())) {
                if ($option->getValue() > $value) {
                    $value = $option->getValue();
                }
            }
        }

        LmbEdi\trace("set_marque", "Marque Id crée : " . $value);
        return $value;
    }

    /**
     * utiliser getAttributeData à la place, la plupart du temps
     * @param type $attrName
     */
    protected function getAttributeInfo($attrCode) {
        return $this->getObjectManager()->get('\Magento\Eav\Model\Entity\Attribute')->loadByCode('catalog_product', $attributeCode);
    }

    protected function getProductAttribute($productId, $attrCode) {
        $store_id = $this->getStoreId();
        $product = LmbManager::load(LmbManager::PRODUCT_MANAGER, $productId, $store_id);
        //custom attribute
        $getMyAttr = $product->getResource()->getAttribute($attrCode);
        if (!$getMyAttr) {
            return;
        }
        $attrTestValue = $getMyAttr->getFrontend()->getValue($product);
        $attrTestLabel = $getMyAttr->getStoreLabel();
        return [$attrTestLabel, $attrTestValue];
    }

    protected function createAttribute($lib, $ref_carac, $art = null, $required = false, $visible_on_front = true, $unique = true) {

        $eavSetupFactory = $this->getObjectManager()->create('Magento\Eav\Setup\EavSetupFactory');
        LmbEdi\trace("A_reception", "Create attribute  = " . print_r($lib, 1));
        $context = LmbAction::getContext();
        $setup = $this->getObjectManager()->create('Magento\Setup\Module\DataSetup');
        $eavSetup = $eavSetupFactory->create(['setup' => $setup]);
        $att = [
            'type' => 'text',
            'backend' => '',
            'frontend' => '',
            'label' => $ref_carac,
            'input' => 'text',
            'class' => '',
            'source' => '',
            'global' => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
            'visible' => true,
            'required' => $required,
            'user_defined' => false,
            'default' => '',
            'searchable' => false,
            'filterable' => false,
            'comparable' => false,
            'visible_on_front' => $visible_on_front,
            'used_in_product_listing' => true,
            'unique' => $unique,
            'apply_to' => ''
        ];
        $eavSetup->addAttribute(Product::ENTITY, $lib, $att);
        $attributeInfo = $this->getObjectManager()->get('\Magento\Eav\Model\Entity\Attribute')
            ->loadByCode('catalog_product', $ref_carac);
        $attributeId = $attributeInfo->getAttributeId();
        return $attributeId;
    }

    /**
     * //https://blog.mdnsolutions.com/magento-2-create-product-attributes-and-options-programmatically/
     * @param type $attr
     * @param type $values
     * @return type
     */
    protected function addOptionsToAttribute($attr, $values) {

        $i = 0;
        foreach ($values as $value) {
            $order["option_{$i}"] = $i;
            $optionsStore["option_{$i}"] = array(
                0 => $value, // admin
                1 => $value, // default store view
            );
            $textSwatch["option_{$i}"] = array(
                1 => $value,
            );
            $visualSwatch["option_{$i}"] = '';
            $delete["option_{$i}"] = '';
            $this->log(" - Option {$value} added for the attribute.");
            $i++;
        }

        switch ($swatchType) {
            case 'text':
                return [
                    'optiontext' => [
                        'order' => $order,
                        'value' => $optionsStore,
                        'delete' => $delete,
                    ],
                    'swatchtext' => [
                        'value' => $textSwatch,
                    ],
                ];
                break;
            case 'visual':
                return [
                    'optionvisual' => [
                        'order' => $order,
                        'value' => $optionsStore,
                        'delete' => $delete,
                    ],
                    'swatchvisual' => [
                        'value' => $visualSwatch,
                    ],
                ];
                break;
            default:
                return [
                    'option' => [
                        'order' => $order,
                        'value' => $optionsStore,
                        'delete' => $delete,
                    ],
                ];
        }
        return $attr->addData($data)->save();
    }

    protected function productAddAttribute($prodId, $attrRef) {
        $store_id = $this->getStoreId();
        $art = LmbManager::load(LmbManager::PRODUCT_MANAGER, $prodId, $store_id);
    }

    ///////////////////
    //               //
    // END Attribute //
    //               //
    ///////////////////

    protected function getImagesAttributes() {
        $img_attrs = array('image', 'small_image', 'thumbnail');

        $param = \lmbedi_config::GET_PARAM('image_attributes');
        if (!empty($param)) {
            $temp = explode(";", $param);

            if (!empty($temp) && is_array($temp)) {
                $img_attrs = $temp;
            }
        }

        return $img_attrs;
    }

    public function update_art_images_positions($infos) {
        if (empty($infos['ref_article']) || empty($infos['ordre'])) {
            return true;
        }

        // Pas de gestion du store pour les images (incompatible)
        $store_id = 0;
        $article = LmbManager::load(LmbManager::PRODUCT_MANAGER, $infos['ref_article'], $store_id, LmbManager::OBJECT_FORMAT);
        if (empty($article->getId())) {
            return true;
        }

        $article->setStoreId($store_id);
        $mediaGalleryEntries = $article->getMediaGalleryEntries();
        $ordre_length = count($infos['ordre']);
        $compteur = 1;
        $image_defaut = null;
        foreach ($mediaGalleryEntries as $key => $entry) {
            if (in_array($entry->getId(), $infos['ordre'])) {
                $positions = array_keys($infos['ordre'], $entry->getId());
                $position = $positions[0] + 1;
            } else {
                $position = $compteur + $ordre_length;
                $compteur++;
            }

            if ($position == 1) {
                $image_defaut = $entry;
            }

            LmbEdi\trace('image_path', $entry->getFile() . " at position $position");
            $entry->setPosition($position)
                ->setStoreId($store_id);
        }

        if (!empty($image_defaut)) {
            $image_attrs = $this->getImagesAttributes();

            // La première fois pour supprimer l'image par défaut actuelle
            foreach ($image_attrs as $image_attr) {
                $attr = str_replace('_', '', 'set' . ucwords($image_attr, "_"));
                $article->$attr();
            }
        }

        $article->setMediaGalleryEntries($mediaGalleryEntries);
        $this->saveArticle($article);

        if (!empty($image_defaut)) {
            $image_attrs = $this->getImagesAttributes();

            // La première fois pour supprimer l'image par défaut actuelle
            foreach ($image_attrs as $image_attr) {
                $attr = str_replace('_', '', 'set' . ucwords($image_attr, "_"));
                $article->$attr($image_defaut->getFile());
            }
        }

        $article->setMediaGalleryEntries($mediaGalleryEntries);
        $this->saveArticle($article);

        return true;
    }

    public function create_art_img($data) {
        LmbEdi\trace('reception', "DEBUT de la create_art_img " . $data['ref_article'] . "*******************");

        // Pas de gestion du store pour les images (incompatible)
        $store_id = 0;
        $prod = LmbManager::load(LmbManager::PRODUCT_MANAGER, $data['ref_article'], $store_id, LmbManager::OBJECT_FORMAT);

        if (!$prod || !$prod->getId()) {
            LmbEdi\trace('reception', 'pas d\article à supprimer ' . $data['ref_article'] . '`');
            return true;
        }

        $prod->setStoreId($store_id);
        $lmbCreateArtImgDirName = self::IMAGE_PATH;
        $dir = $this->getObjectManager()->get('Magento\Framework\App\Filesystem\DirectoryList')->getPath('media') . DIRECTORY_SEPARATOR . $lmbCreateArtImgDirName;

        if (!file_exists($dir))
            mkdir($dir, 0777, true);

        $fileName = $data['ref_article'] . '_' . uniqid() . '_' . basename($data['url']);
        $absoluteFileNamefile = $dir . DIRECTORY_SEPARATOR . $fileName;
        $relativeFileName = $lmbCreateArtImgDirName . DIRECTORY_SEPARATOR . $fileName;

        if (!$this->downloadFile($data['url'], $absoluteFileNamefile)) {
            LmbEdi\trace('reception', "Échec du téléchargement de l'image " . $data['url']);
            return true;
        }

        $content = $this->getObjectManager()
            ->create('\Magento\Framework\Api\Data\ImageContentInterface');

        $content->setType(mime_content_type($absoluteFileNamefile))
            ->setName($fileName)
            ->setBase64EncodedData(base64_encode(file_get_contents($absoluteFileNamefile)));


        $img = $this->getObjectManager()
            ->create('\Magento\Catalog\Model\Product\Gallery\Entry');

        $mediaGalleryEntries = $prod->getMediaGalleryEntries();
        $images_type = (count($mediaGalleryEntries) == 0) ? $this->getImagesAttributes() : array();
        $img->setFile($absoluteFileNamefile)
            ->setMediaType('image')
            ->setDisabled(false)
            ->setTypes($images_type)
            ->setStoreId($store_id)
            ->setContent($content);

        $id_image = $this->getObjectManager()->get('\Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface')
            ->create($prod->getSku(), $img);

        $this->createCorrespondanceLmb($data['id_image'], $id_image, 12);

        LmbEdi\trace('reception', "FIN de la create_art_img " . $data['ref_article'] . "*******************");
        return true;
    }

    protected function downloadFile($url, $newfname) {
        $file = fopen($url, 'rb');
        if (!$file) {
            LmbEdi\trace_error('reception', 'download file : aucun fichier à télécharger `' . $url . '`');
            return false;
        }
        $newf = fopen($newfname, 'wb');
        if (!$newf) {
            LmbEdi\trace_error('reception', 'download file : impossible d ecrire dans le repertoire `' . $newfname . '`');
            return false;
        }
        while (!feof($file)) {
            fwrite($newf, fread($file, 1024 * 8), 1024 * 8);
        }
        fclose($file);
        fclose($newf);
        return true;
    }

    public function delete_art_img($data) {
        // Pas de gestion du store pour les images (incompatible)
        $store_id = 0;

        if (!empty($data['ref_article'])) { //dans le cas d'une maj
            LmbEdi\trace("reception", " => Mise à jour d'article");
            $art = LmbManager::load(LmbManager::PRODUCT_MANAGER, $data['ref_article'], $store_id, LmbManager::OBJECT_FORMAT);

            if (!$art || !$art->getId()) {
                LmbEdi\trace_error('reception', 'delete_art_img : pas de produit avec id `' . $data['ref_article'] . '`');
                return true;
            }

            $art->setStoreId($store_id);
            
            if (!is_numeric($data['id_image'])) {
                $img_id_by_url = $this->getObjectManager()
                    ->create('\Magento\Catalog\Model\Product\Gallery\EntryResolver')
                    ->getEntryIdByFilePath($art->getManagerElement(LmbManager::OBJECT_FORMAT), $data['id_image']);
                if(!empty($img_id_by_url)) {
                    LmbEdi\trace_error('reception', "delete_art_img : convertion `" . $data['id_image'] . '` par `'.$img_id_by_url.'`');
                    $data['id_image'] = $img_id_by_url;
                }
            }
            
            $img = $this->getObjectManager()
                ->create('\Magento\Catalog\Model\Product\Gallery\EntryResolver')
                ->getEntryFilePathById($art->getManagerElement(LmbManager::OBJECT_FORMAT), $data['id_image']);

            if (empty($img)) {
                LmbEdi\trace_error('reception', "delete_art_img : pas d'image avec l'id `" . $data['id_image'] . '`');
                return true;
            }
           
            $emul = $this->getObjectManager()->get('\Magento\Store\Model\App\Emulation');
            $emul->startEnvironmentEmulation($store_id, \Magento\Framework\App\Area::AREA_ADMINHTML);
            $this->getObjectManager()->get('\Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface')
                ->remove($art->getSku(), $data['id_image']);
            $emul->stopEnvironmentEmulation();
        }

        return true;
    }

    protected function getAttributesSetFromInfos($infos) {
        $id_attributes_set = null;
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        if (!empty($infos['attributes_set'])) {
            $setRepository = $objectManager->create('Magento\Eav\Api\AttributeSetRepositoryInterface');
            $set = $setRepository->get($infos['attributes_set']);
            $attributeSetId = $set->getAttributeSetId();
            if (empty($attributeSetId) || $infos['attributes_set'] === 0) {
                $art = LmbManager::create(LmbManager::PRODUCT_MANAGER);
//                LmbEdi\trace_error('reception', "le jeu d'attribut est introuvable ou égale à 0. La valeur " . $art->getDefaultAttributeSetId() . " est mise par défaut");
                return $art->getDefaultAttributeSetId();
            } else {
                return $infos['attributes_set'];
            }
        } else {
            $art = LmbManager::create(LmbManager::PRODUCT_MANAGER);
//            LmbEdi\trace_error('reception', "le jeu d'attribut est introuvable ou égale à 0. La valeur " . $art->getDefaultAttributeSetId() . " est mise par défaut");
            return $art->getDefaultAttributeSetId();
        }
    }

    /**
     * Gestion des commandes
     *
     * @param array $request
     * @return boolean
     */
    public function modif_etat_cmd(array $request) {
        $order = $this->getObjectManager()->create('\Magento\Sales\Model\Order')->loadByIncrementId($request['id_order']);

        if (!$order || !$order->getId()) {
            LmbEdi\trace_error('modif_etat_cmd', "Order avec l'id increment `" . $request['id_order'] . "` inexistant");
            return true;
        }

        try {
            $order->setState($request['etat'])
                ->setStatus($request['etat'])
                ->save();
        } catch (\Exception $e) {
            LmbEdi\trace_error('modif_etat_cmd', 'Etat de modification de commande non géré : `' . $request['etat'] . '`');
            return true;
        }

        return true;
    }

    public function update_tracking($infos) {
        $order = $this->getObjectManager()
            ->create('\Magento\Sales\Model\Order')
            ->loadByIncrementId($infos['id_order']);

        if (!$order || !$order->getId()) {
            LmbEdi\trace_error('update_tracking', "Order avec l'id increment `" . (int)$infos['id_order'] . "` inexistant");
            return true;
        }

        if (!$order->canShip()) {
            LmbEdi\trace_error('update_tracking', "Order " . (int)$infos['id_order'] . " enregistrée comme non livrable");
            return true;
        }

        $tracking_title = \lmbedi_config::GET_PARAM('tracking_title');
        if (empty($tracking_title)) {
            $tracking_title = self::TRACKING_TITLE;
        }

        try {
            $shipments = $order->getShipmentsCollection();
            $shipment = null;
            $track = null;

            foreach ($shipments as $ship) {
                $tracks = $ship->getAllTracks();
                foreach ($tracks as $t) {
                    if ($t->getTitle() == $tracking_title) {
                        $shipment = $ship;
                        $track = $t;
                        break 2;
                    }
                }
            }

            $data = array(
                'carrier_code' => $infos["transporteur"],
                'title' => $tracking_title,
                'number' => $infos["num_tracking"]
            );
            if (empty($data["carrier_code"])) {
                $data["carrier_code"] = $order->getShippingMethod();
            }

            if (empty($track)) {
                $track = $this->getObjectManager()
                    ->get('Magento\Sales\Model\Order\Shipment\TrackFactory')
                    ->create();
            }
            $track->addData($data);

            if (empty($shipment)) {
                $convertOrder = $this->getObjectManager()
                    ->create('Magento\Sales\Model\Convert\Order');
                $shipment = $convertOrder->toShipment($order);

                foreach ($order->getAllItems() as $orderItem) {
                    if (!$orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                        continue;
                    }

                    $qtyShipped = $orderItem->getQtyToShip();
                    $shipmentItem = $convertOrder->itemToShipmentItem($orderItem)
                        ->setQty($qtyShipped);

                    $shipment->addItem($shipmentItem);
                }
                $shipment->register();
            }

            $shipment->addTrack($track)
                ->save();

            $shipment->save();
        } catch (\Throwable $e) {
            LmbEdi\trace_error('update_tracking', "Échec de la création de l'expédition pour " . $infos['id_order']);
            return false;
        }

        try {
            $this->getObjectManager()->create('Magento\Shipping\Model\ShipmentNotifier')
                ->notify($shipment);
        } catch (\Throwable $e) {
            LmbEdi\trace_error('update_tracking', "Échec de l'envoi du mail au changement de tracking de la commande " . $infos['id_order']);
        }

        return true;
    }

    /**
     * Création de correspondance entre état d'une commande LMB vers Magento 2
     *
     * à n'utiliser que sur LMB
     *
     * @return array
     */
    protected function getDefautCorrespEtatsCmd() {
        // ETATS magento 2
        //-----------------
        //  const STATE_NEW = 'new';
        //  const STATE_PENDING_PAYMENT = 'pending_payment';
        //  const STATE_PROCESSING = 'processing';
        //  const STATE_COMPLETE = 'complete';
        //  const STATE_CLOSED = 'closed';
        //  const STATE_CANCELED = 'canceled';
        //  const STATE_HOLDED = 'holded';
        //  const STATE_PAYMENT_REVIEW = 'payment_review';
        return array(
            1 => 'new', //(état ajouté par rapport à WC)
            8 => 'holded',
            3 => 'holded', //(état ajouté par rapport à WC)
            22 => 'holded', //(état ajouté par rapport à WC)
            18 => 'pending_payment', //(état ajouté par rapport à WC)
            9 => 'processing',
            73 => 'processing',
            91 => 'processing', //(état ajouté par rapport à WC)
            15 => 'complete',
            40 => 'complete', //(état ajouté par rapport à WC)
            31 => 'complete', //(état ajouté par rapport à WC)
            2 => 'cancelled', //(état ajouté par rapport à WC)
            17 => 'cancelled', //(état ajouté par rapport à WC)
            7 => 'cancelled',
        );
    }

    public function multiplexe($messages) {
        foreach ($messages as $message) {
            $mess = \lmbedi_message_recu::create();
            $mess->set_fonction($message['nom_fonction'], $message['params']);
        }
        return true;
    }
    
    public function getPreTraite() {
        if (!file_exists(dirname(__FILE__) . "/traitements/Recepteur_pretrait.php"))
            return null;
        require_once(dirname(__FILE__) . "/traitements/Recepteur_pretrait.php");
        if (!class_exists(self::$preTraiteClassName, false))
            return null;
        if (empty(self::$preTraite))
            self::$preTraite = new Recepteur_pretrait();
        return self::$preTraite;
    }

    /**
     * return null
     */
    public function getPostTraite() {
        if (!file_exists(dirname(__FILE__) . "/traitements/Recepteur_postrait.php"))
            return null;
        require_once(dirname(__FILE__) . "/traitements/Recepteur_posttrait.php");
        if (!class_exists(self::$postTraiteClassName, false))
            return null;
        if (empty(self::$postTraite))
            self::$postTraite = new Recepteur_posttrait();
        return self::$postTraite;
    }

    /**
     * encode slashes to their HTML counterpart
     * @param type $data
     * @return type
     */
    private function slashslash($data) {
        $typeDeValARemplacer = array(
            //produit
            'lib_article', 'desc_courte', 'desc_longue',
            //categorie
            'lib_art_categ', 'description');
        foreach ($typeDeValARemplacer as $typeValR) {
            if (isset($data[$typeValR]) && is_string($data[$typeValR])) {
                $v = trim($data[$typeValR]);
                $data[$typeValR] = str_replace(["\r\n", "\n", "\r"], '<br>', $v);
                $data[$typeValR] = str_replace('\\', '&#92;', $v);
            }
        }
        return $data;
    }

    protected function createCorrespondanceLmb($lmb, $magento, $type) {
        $corres = array(
            "ref_lmb" => $lmb,
            "ref_externe" => $magento,
            "id_ref_type" => $type
        );
        Emeteur::envoi_LMB("create_correspondance", $corres);
    }
}
