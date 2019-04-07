<?php
/**
 * 2007-2019 PrestaShop SA and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2019 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Module\WidgetInterface;
use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Core\Product\ProductListingPresenter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;

class Ps_Crossselling extends Module implements WidgetInterface
{
    /**
     * @var string
     */
    protected $templateFile = 'module:ps_crossselling/views/templates/hook/ps_crossselling.tpl';

    /**
     * @var array
     */
    protected $hooks = [
        'displayFooterProduct',
        'actionOrderStatusPostUpdate',
    ];

    public function __construct()
    {
        $this->name = 'ps_crossselling';
        $this->author = 'PrestaShop';
        $this->version = '2.1.0';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => '1.7.2.0',
            'max' => _PS_VERSION_,
        ];
        parent::__construct();

        $this->displayName = $this->trans('Cross-selling', [], 'Modules.Crossselling.Admin');
        $this->description = $this->trans('Adds a "Customers who bought this product also bought..." section to every product page.', [], 'Modules.Crossselling.Admin');
    }

    /**
     * @return bool
     */
    public function install()
    {
        $this->_clearCache($this->templateFile);
        
        return parent::install()
            && Configuration::updateValue('CROSSSELLING_DISPLAY_PRICE', 1)
            && Configuration::updateValue('CROSSSELLING_NBR', 8)
            && $this->registerHook($this->hooks)
        ;
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        $this->_clearCache($this->templateFile);
        
        return parent::uninstall()
            && Configuration::deleteByName('CROSSSELLING_DISPLAY_PRICE')
            && Configuration::deleteByName('CROSSSELLING_NBR')
        ;
    }

    /**
     * @return string
     */
    public function getContent()
    {
        $html = '';

        if (Tools::isSubmit('submitCross')) {
            $isSuccess = Configuration::updateValue('CROSSSELLING_DISPLAY_PRICE', (bool) Tools::getValue('CROSSSELLING_DISPLAY_PRICE')) 
                && Configuration::updateValue('CROSSSELLING_NBR', (int) Tools::getValue('CROSSSELLING_NBR'))
            ;
            
            if ($isSuccess) {
                $this->_clearCache($this->templateFile);
                $html .= $this->displayConfirmation($this->trans('The settings have been updated.', [], 'Admin.Notifications.Success'));
            }
        }

        return $html . $this->renderForm();
    }

    /**
     * @param array $params
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        $this->_clearCache($this->templateFile);
    }

    /**
     * @param string $hookName
     * @param array $configuration
     *
     * @return array
     */
    public function getWidgetVariables($hookName, array $configuration)
    {
        $widgetVariables = [];
        $productIds = $this->getProductIds($hookName, $configuration);

        if (!empty($productIds)) {
            $products = $this->getOrderProducts($productIds);

            if (!empty($products)) {
                $widgetVariables['products'] = $products;
            }
        }

        return $widgetVariables;
    }

    /**
     * @param string $hookName
     * @param array $configuration
     *
     * @return string|null
     */
    public function renderWidget($hookName, array $configuration)
    {
        $productIds = $this->getProductIds($hookName, $configuration);

        if (empty($productIds)) {
            return null;
        }

        $cacheKey = $this->getCacheId() . '|' . implode('|', $productIds);

        if (!$this->isCached($this->templateFile, $cacheKey)) {
            $variables = $this->getWidgetVariables($hookName, $configuration);

            if (empty($variables)) {
                return null;
            }

            $this->smarty->assign($variables);
        }

        return $this->fetch($this->templateFile, $cacheKey);
    }

    protected function renderForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Settings', [], 'Admin.Global'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Display price on products', [], 'Modules.Crossselling.Admin'),
                        'name' => 'CROSSSELLING_DISPLAY_PRICE',
                        'desc' => $this->trans('Show the price on the products in the block.', [], 'Modules.Crossselling.Admin'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Enabled', [], 'Admin.Global'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('Disabled', [], 'Admin.Global'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Number of displayed products', [], 'Modules.Crossselling.Admin'),
                        'name' => 'CROSSSELLING_NBR',
                        'class' => 'fixed-width-xs',
                        'desc' => $this->trans('Set the number of products displayed in this block.', [], 'Modules.Crossselling.Admin'),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (bool) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitCross';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) .
            '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => [
                'CROSSSELLING_NBR' => Tools::getValue('CROSSSELLING_NBR', (int) Configuration::get('CROSSSELLING_NBR')),
                'CROSSSELLING_DISPLAY_PRICE' => Tools::getValue('CROSSSELLING_DISPLAY_PRICE', (bool) Configuration::get('CROSSSELLING_DISPLAY_PRICE')),
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => (int) $this->context->language->id
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * @param string $hookName
     * @param array $configuration
     *
     * @return array
     */
    protected function getProductIds($hookName, array $configuration)
    {
        $productIds = [];

        if (isset($configuration['cart']) && Validate::isLoadedObject($configuration['cart'])) {
            $products = $configuration['cart']->getProducts();
            if (!empty($products)) {
                foreach ($products as $product) {
                    $productIds[] = $product['id_product'];
                }
            }
        } else if (!empty($configuration['product']['id_product'])) {
            $productIds[] = $configuration['product']['id_product'];
        }

        return $productIds;
    }

    /**
     * @param array $productIds
     *
     * @return array
     */
    protected function getOrderProducts(array $productIds)
    {
        $orderedProductsForTemplate = [];
        $queryOrderIds = new DbQuery();
        $queryOrderIds->select('o.id_order');
        $queryOrderIds->from('orders', 'o');
        $queryOrderIds->innerJoin('order_detail', 'od', 'od.id_order = o.id_order');
        $queryOrderIds->where('o.valid = 1 AND od.product_id IN (' . implode(',', $productIds) . ')');
        $orders = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($queryOrderIds);

        if (!empty($orders)) {
            $queryOrderedProductIds = new DbQuery();
            $queryOrderedProductIds->select('DISTINCT od.product_id');
            $queryOrderedProductIds->from('order_detail', 'od');
            $queryOrderedProductIds->leftJoin('product', 'p', 'p.id_product = od.product_id');
            $queryOrderedProductIds->join(Shop::addSqlAssociation('product', 'p'));
            $queryOrderedProductIds->leftJoin('product_lang', 'pl', 'pl.id_product = od.product_id AND pl.id_lang = ' . (int) $this->context->language->id . Shop::addSqlRestrictionOnLang('pl'));
            $queryOrderedProductIds->leftJoin('category_lang', 'cl', 'cl.id_category = product_shop.id_category_default AND cl.id_lang = ' . (int) $this->context->language->id . Shop::addSqlRestrictionOnLang('cl'));
            $queryOrderedProductIds->leftJoin('image', 'i', 'i.id_product = od.product_id AND i.cover = 1');
            $queryOrderedProductIds->orderBy('RAND()');
            $queryOrderedProductIds->limit((int) Configuration::get('CROSSSELLING_NBR'));
            $queryOrderedProductIds->where('product_shop.active = 1 AND od.id_order IN (' . implode(',', $orders) . ') AND od.product_id NOT IN (' . implode(',', $productIds) . ')');

            if (Combination::isFeatureActive()) {
                $queryOrderedProductIds->leftJoin('product_attribute', 'pa', 'p.id_product = pa.id_product');
                $queryOrderedProductIds->join(Shop::addSqlAssociation(
                    'product_attribute',
                    'pa',
                    false,
                    'product_attribute_shop.default_on = 1'
                ));
                $queryOrderedProductIds->join(Product::sqlStock(
                    'p',
                    'product_attribute_shop',
                    false,
                    $this->context->shop
                ));
            } else {
                $queryOrderedProductIds->join(Product::sqlStock(
                    'p',
                    'product',
                    false,
                    $this->context->shop
                ));
            }

            if (Group::isFeatureActive()) {
                $queryOrderedProductIds->leftJoin('category_product', 'cp', 'cp.id_category = product_shop.id_category_default AND cp.id_product = product_shop.id_product');
                $queryOrderedProductIds->leftJoin('category_group', 'cg', 'cp.id_category = cg.id_category');
                $groups = FrontController::getCurrentCustomerGroups();
                if (!empty($groups)) {
                    $queryOrderedProductIds->where('AND cg.id_group IN ('.implode(',', $groups) . ')');
                } else {
                    $queryOrderedProductIds->where('AND cg.id_group = ' . (int) Configuration::get('PS_UNIDENTIFIED_GROUP'));
                }
            }

            $orderedProducts = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($queryOrderedProductIds);
        }

        if (!empty($orderedProducts)) {
            $assembler = new ProductAssembler($this->context);
            $presenterFactory = new ProductPresenterFactory($this->context);
            $presentationSettings = $presenterFactory->getPresentationSettings();
            $presenter = new ProductListingPresenter(
                new ImageRetriever($this->context->link),
                $this->context->link,
                new PriceFormatter(),
                new ProductColorsRetriever(),
                $this->context->getTranslator()
            );
            $presentationSettings->showPrices = (bool) Configuration::get('CROSSSELLING_DISPLAY_PRICE');

            foreach ($orderedProducts as $productId) {
                $orderedProductsForTemplate[] = $presenter->present(
                    $presentationSettings,
                    $assembler->assembleProduct(['id_product' => $productId]),
                    $this->context->language
                );
            }
        }

        return $orderedProductsForTemplate;
    }
}
