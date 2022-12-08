<?php
/**
* 2007-2022 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2022 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class AtkzOrdersFication extends Module
{
    public function __construct()
    {
        $this->name = 'atkzordersfication';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'ATKOZ';
        $this->need_instance = 1;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('ATKOZ - Orders Notification');
        $this->description = $this->l('Sending an email to customers when purchasing a product');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall my module?');
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        Configuration::updateValue('ATKZORDERSFICATION_ORDER_STATE', false);
        Configuration::updateValue('ATKZORDERSFICATION_PRODUCTS', false);
        Configuration::updateValue('ATKZORDERSFICATION_EMAILS', false);

        if (!parent::install()
            || !$this->registerHook('displayBackOfficeHeader')
            || !$this->registerHook('actionOrderStatusUpdate')
        ) {
            return false;
        }
        return true;
    }

    public function uninstall()
    {
        if (!parent::uninstall()
            || !$this->unregisterHook('displayBackOfficeHeader')
            || !$this->unregisterHook('actionOrderStatusUpdate')
        ) {
            return false;
        }
        return true;
    }

    public function getContent()
    {
        $this->_html = '';
        if (((bool)Tools::isSubmit('submitAtkzordersficationModule')) == true) {
            $this->_html .= $this->postProcess();
        }

        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitAtkzordersficationModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    protected function getConfigForm()
    {
        $statusOrders = OrderState::getOrderStates($this->context->language->id, true);

        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Settings'),
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'desc' => $this->l('Enter a valid email address for test notification'),
                        'name' => 'ATKZORDERSFICATION_EMAILS',
                        'label' => $this->l('Email'),
                    ),
                    array(
                        'col' => 6,
                        'type' => 'select',
                        'desc' => $this->l('Select order state to send notification'),
                        'name' => 'ATKZORDERSFICATION_ORDER_STATE',
                        'label' => $this->l('Order state'),
                        'options' => array(
                            'default' => array('value' => 0, 'label' => $this->l('None')),
                            'query' => $statusOrders,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'desc' => $this->l('Enter a Product ID for send notification'),
                        'name' => 'ATKZORDERSFICATION_PRODUCTS',
                        'label' => $this->l('Product ID'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function getConfigFieldsValues()
    {
        $id_shop = Shop::getContextShopID();
        $id_shop_group = Shop::getContextShopGroupID();

        return array(
            'ATKZORDERSFICATION_ORDER_STATE' => Tools::getValue('ATKZORDERSFICATION_ORDER_STATE', Configuration::get('ATKZORDERSFICATION_ORDER_STATE', null, $id_shop_group, $id_shop)),
            'ATKZORDERSFICATION_PRODUCTS' => Tools::getValue('ATKZORDERSFICATION_PRODUCTS', Configuration::get('ATKZORDERSFICATION_PRODUCTS', null, $id_shop_group, $id_shop)),
            'ATKZORDERSFICATION_EMAILS' => Tools::getValue('ATKZORDERSFICATION_EMAILS', Configuration::get('ATKZORDERSFICATION_EMAILS', null, $id_shop_group, $id_shop)),
        );
    }

    protected function postProcess()
    {
        $form_values = $this->getConfigFieldsValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    public function hookDisplayBackOfficeHeader()
    {
    }

    public function hookActionOrderStatusUpdate(array $params)
    {

        $order = new Order((int) $params['id_order']);
        $current_state = $order->current_state;

        /*  Voir si test de comparaison avec le status current (precommande) */
        $new_state = $params['newOrderStatus']->id;
        $notif_state = (int) Configuration::get('ATKZORDERSFICATION_ORDER_STATE');

        if ($new_state === $notif_state){

             /*  Voir si test multi produits */
            $notif_product = (int) Configuration::get('ATKZORDERSFICATION_PRODUCTS');
            $checkProduct = $order->orderContainProduct($notif_product);

            if ($checkProduct){
                
                /*  Voir si test multi emails */
                $notif_email = Configuration::get('ATKZORDERSFICATION_EMAILS');

                if ($notif_email){
                    $mail_customer = $notif_email;
                }else{
                    $customer = new Customer((int) $order->id_customer);
                    $mail_customer = $customer->email;
                }

                if (!empty($mail_customer) && Validate::isEmail($mail_customer)){

                    $id_shop = (isset($order->id_shop)) ? $order->id_shop : (int) Context::getContext()->shop->id;
                    $id_lang = (isset($order->id_lang)) ? $order->id_lang : (int) Context::getContext()->language->id;
                    $iso = Language::getIsoById($id_lang);

                    $product = new Product((int) $notif_product, false, $id_lang, $id_shop);
                    //$id_product_attribute = $params['product']['id_product_attribute'];
                    $product_name = Product::getProductName($product->id, null, $id_lang);
                    $product_link = Context::getContext()->link->getProductLink($product, $product->link_rewrite, null, null, $id_lang, $id_shop, null);
                    $template_vars = [
                        '{product}' => $product_name,
                        '{product_link}' => $product_link,
                    ];
    
                    if (file_exists(dirname(__FILE__) . '/mails/' . $iso . '/ordersfication.txt') &&
                        file_exists(dirname(__FILE__) . '/mails/' . $iso . '/ordersfication.html')) {
                        try {
                            Mail::Send(
                                $id_lang,
                                'ordersfication',
                                Mail::l('Notificaion Product', $id_lang),
                                $template_vars,
                                $mail_customer,
                                null,
                                Configuration::get('PS_SHOP_EMAIL', null, null, $id_shop),
                                Configuration::get('PS_SHOP_NAME', null, null, $id_shop),
                                null,
                                null,
                                dirname(__FILE__) . '/mails/',
                                false,
                                $id_shop
                            );
                        } catch (Exception $e) {
                            PrestaShopLogger::addLog(
                                sprintf(
                                    'Module atkzordersfication error: Could not send email to address [%s] because %s',
                                    $mail_customer,
                                    $e->getMessage()
                                ),
                                3
                            );
                        }
                    }
                }
            }

            //exit;
        }
    }
}
