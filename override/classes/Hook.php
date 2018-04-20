<?php
//<version>1.7.0</version>
class Hook extends HookCore
{
    public static function getHookModuleExecList($hook_name = null)
    {
        $context = Context::getContext();
        $cache_id = self::MODULE_LIST_BY_HOOK_KEY.(isset($context->shop->id) ? '_'.$context->shop->id : '').((isset($context->customer)) ? '_'.$context->customer->id : '');
        if (!Cache::isStored($cache_id) || $hook_name == 'displayPayment' || $hook_name == 'displayPaymentEU' || $hook_name == 'paymentOptions' || $hook_name == 'displayBackOfficeHeader') {
            $frontend = true;
            $groups = array();
            $use_groups = Group::isFeatureActive();
            if (isset($context->employee)) {
                $frontend = false;
            } else {
                // Get groups list
                if ($use_groups) {
                    if (isset($context->customer) && $context->customer->isLogged()) {
                        $groups = $context->customer->getGroups();
                    } elseif (isset($context->customer) && $context->customer->isLogged(true)) {
                        $groups = array((int)Configuration::get('PS_GUEST_GROUP'));
                    } else {
                        $groups = array((int)Configuration::get('PS_UNIDENTIFIED_GROUP'));
                    }
                }
            }

            // SQL Request
            $sql = new DbQuery();
            $sql->select('h.`name` as hook, m.`id_module`, h.`id_hook`, m.`name` as module');
            $sql->from('module', 'm');
            if ($hook_name != 'displayBackOfficeHeader') {
                $sql->join(Shop::addSqlAssociation('module', 'm', true, 'module_shop.enable_device & '.(int)Context::getContext()->getDevice()));
                $sql->innerJoin('module_shop', 'ms', 'ms.`id_module` = m.`id_module`');
            }
            $sql->innerJoin('hook_module', 'hm', 'hm.`id_module` = m.`id_module`');
            $sql->innerJoin('hook', 'h', 'hm.`id_hook` = h.`id_hook`');
            if ($hook_name != 'paymentOptions') {
                $sql->where('h.`name` != "paymentOptions"');
            } elseif ($frontend) {
                // For payment modules, we check that they are available in the contextual country
                if (Validate::isLoadedObject($context->country)) {
                    $sql->where('((h.`name` = "displayPayment" OR h.`name` = "displayPaymentEU" OR h.`name` = "paymentOptions")AND (SELECT `id_country` FROM `'._DB_PREFIX_.'module_country` mc WHERE mc.`id_module` = m.`id_module` AND `id_country` = '.(int)$context->country->id.' AND `id_shop` = '.(int)$context->shop->id.' LIMIT 1) = '.(int)$context->country->id.')');
                }
                if (Validate::isLoadedObject($context->currency)) {
                    $sql->where('((h.`name` = "displayPayment" OR h.`name` = "displayPaymentEU" OR h.`name` = "paymentOptions") AND (SELECT `id_currency` FROM `'._DB_PREFIX_.'module_currency` mcr WHERE mcr.`id_module` = m.`id_module` AND `id_currency` IN ('.(int)$context->currency->id.', -1, -2) LIMIT 1) IN ('.(int)$context->currency->id.', -1, -2))');
                }
                if (Validate::isLoadedObject($context->cart)) {
                    $carrier = new Carrier($context->cart->id_carrier);
                    if (Validate::isLoadedObject($carrier)) {
                        $sql->where('((h.`name` = "displayPayment" OR h.`name` = "displayPaymentEU" OR h.`name` = "paymentOptions") AND (SELECT `id_reference` FROM `'._DB_PREFIX_.'module_carrier` mcar WHERE mcar.`id_module` = m.`id_module` AND `id_reference` = '.(int)$carrier->id_reference.' AND `id_shop` = '.(int)$context->shop->id.' LIMIT 1) = '.(int)$carrier->id_reference.')');
                    }
                }
            }
            if (Validate::isLoadedObject($context->shop)) {
                $sql->where('hm.`id_shop` = '.(int)$context->shop->id);
            }

            if ($frontend) {
                if ($use_groups) {
                    $sql->leftJoin('module_group', 'mg', 'mg.`id_module` = m.`id_module`');
                    if (Validate::isLoadedObject($context->shop)) {
                        $sql->where('mg.id_shop = '.((int)$context->shop->id).(count($groups) ? ' AND  mg.`id_group` IN ('.implode(', ', $groups).')' : ''));
                    } elseif (count($groups)) {
                        $sql->where('mg.`id_group` IN ('.implode(', ', $groups).')');
                    }
                }
            }

            $sql->groupBy('hm.id_hook, hm.id_module');
            $sql->orderBy('hm.`position`');

            $list = array();
            $disabledMethods = unserialize(Configuration::get("INTRUM_DISABLED_METHODS"));
            if ($hook_name == 'paymentOptions' ) {
                /* @var $context Context */

                /* Make intrum request */
                /* Intrum status */
                $status = 0;
                if (!defined('_PS_MODULE_INTRUMCOM_API')) {
                    require(_PS_MODULE_DIR_.'intrumcom/api/intrum.php');
                    require(_PS_MODULE_DIR_.'intrumcom/api/library_prestashop.php');
                }

                $request = CreatePrestaShopRequest($context->cart, $context->customer, $context->currency);
                $xml = $request->createRequest();
                $intrumCommunicator = new IntrumCommunicator();
                $intrumCommunicator->setServer(Configuration::get("INTRUM_MODE"));
                $response = $intrumCommunicator->sendRequest($xml);

                if ($response) {
                    $intrumResponse = new IntrumResponse();
                    $intrumResponse->setRawResponse($response);
                    $intrumResponse->processResponse();
                    $status = $intrumResponse->getCustomerRequestStatus();
                }
                $intrumLogger = IntrumLogger::getInstance();
                $intrumLogger->log(Array(
                    "firstname" => $request->getFirstName(),
                    "lastname" => $request->getLastName(),
                    "town" => $request->getTown(),
                    "postcode" => $request->getPostCode(),
                    "street" => trim($request->getFirstLine().' '.$request->getHouseNumber()),
                    "country" => $request->getCountryCode(),
                    "ip" => $_SERVER["REMOTE_ADDR"],
                    "status" => $status,
                    "request_id" => $request->getRequestId(),
                    "type" => "Request status",
                    "error" => ($status == 0) ? $response : "",
                    "response" => $response,
                    "request" => $xml
                ));
                $minAmount = Configuration::get("INTRUM_MIN_AMOUNT");
                $currentAmount = $context->cart->getOrderTotal(true, Cart::BOTH);
                $checkIntrum = true;
                if ($minAmount > $currentAmount) {
                    $checkIntrum = false;
                }

                $allowed = Array(0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,27,28,29,30,50,51,52,53,54,55,56,57);
                if (!in_array($status, $allowed)) {
                    $status = 0;
                }
                if ($result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql)) {
                    foreach ($result as $row)
                    {
                        if (!empty($disabledMethods[$status]) && is_array($disabledMethods[$status]) && in_array($row['id_module'], $disabledMethods[$status]) && $checkIntrum) {
                            continue;
                        }

                        $row['hook'] = strtolower($row['hook']);
                        if (!isset($list[$row['hook']])) {
                            $list[$row['hook']] = array();
                        }

                        $list[$row['hook']][] = array(
                            'id_hook' => $row['id_hook'],
                            'module' => $row['module'],
                            'id_module' => $row['id_module'],
                        );
                    }
                }
            } else {
                if ($result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql)) {
                    foreach ($result as $row) {
                        $row['hook'] = strtolower($row['hook']);
                        if (!isset($list[$row['hook']])) {
                            $list[$row['hook']] = array();
                        }

                        $list[$row['hook']][] = array(
                            'id_hook' => $row['id_hook'],
                            'module' => $row['module'],
                            'id_module' => $row['id_module'],
                        );
                    }
                }
            }

            if ($hook_name != 'displayPayment' && $hook_name != 'displayPaymentEU' && $hook_name != 'paymentOptions' && $hook_name != 'displayBackOfficeHeader') {
                Cache::store($cache_id, $list);
                // @todo remove this in 1.6, we keep it in 1.5 for backward compatibility
                self::$_hook_modules_cache_exec = $list;
            }
        } else {
            $list = Cache::retrieve($cache_id);
        }

        // If hook_name is given, just get list of modules for this hook
        if ($hook_name) {
            $retro_hook_name = strtolower(Hook::getRetroHookName($hook_name));
            $hook_name = strtolower($hook_name);

            $return = array();
            $inserted_modules = array();
            if (isset($list[$hook_name])) {
                $return = $list[$hook_name];
            }
            foreach ($return as $module) {
                $inserted_modules[] = $module['id_module'];
            }
            if (isset($list[$retro_hook_name])) {
                foreach ($list[$retro_hook_name] as $retro_module_call) {
                    if (!in_array($retro_module_call['id_module'], $inserted_modules)) {
                        $return[] = $retro_module_call;
                    }
                }
            }

            return (count($return) > 0 ? $return : false);
        } else {
            return $list;
        }
    }


    public static function oldGetHookModuleExecList($hook_name = null)
    {
        if (substr(_PS_VERSION_, 0, 3) == '1.5') {
            $context = Context::getContext();
            $cache_id = 'hook_module_exec_list'.((isset($context->customer)) ? '_'.$context->customer->id : '');
            if (!Cache::isStored($cache_id) || $hook_name == 'displayPayment')
            {
                $frontend = true;
                $groups = array();
                if (isset($context->employee))
                {
                    $shop_list = array((int)$context->shop->id);
                    $frontend = false;
                }
                else
                {
                    // Get shops and groups list
                    $shop_list = Shop::getContextListShopID();
                    if (isset($context->customer) && $context->customer->isLogged())
                        $groups = $context->customer->getGroups();
                    elseif (isset($context->customer) && $context->customer->isLogged(true))
                        $groups = array((int)Configuration::get('PS_GUEST_GROUP'));
                    else
                        $groups = array((int)Configuration::get('PS_UNIDENTIFIED_GROUP'));
                }

                // SQL Request
                $sql = new DbQuery();
                $sql->select('h.`name` as hook, m.`id_module`, h.`id_hook`, m.`name` as module, h.`live_edit`');
                $sql->from('module', 'm');
                $sql->innerJoin('hook_module', 'hm', 'hm.`id_module` = m.`id_module`');
                $sql->innerJoin('hook', 'h', 'hm.`id_hook` = h.`id_hook`');
                $sql->where('(SELECT COUNT(*) FROM '._DB_PREFIX_.'module_shop ms WHERE ms.id_module = m.id_module AND ms.id_shop IN ('.implode(', ', $shop_list).')) = '.count($shop_list));
                if ($hook_name != 'displayPayment')
                    $sql->where('h.name != "displayPayment"');
                // For payment modules, we check that they are available in the contextual country
                elseif ($frontend)
                {
                    $sql->where(Module::getPaypalIgnore());
                    if (Validate::isLoadedObject($context->country))
                        $sql->where('(h.name = "displayPayment" AND (SELECT id_country FROM '._DB_PREFIX_.'module_country mc WHERE mc.id_module = m.id_module AND id_country = '.(int)$context->country->id.' AND id_shop = '.(int)$context->shop->id.' LIMIT 1) = '.(int)$context->country->id.')');
                    if (Validate::isLoadedObject($context->currency))
                        $sql->where('(h.name = "displayPayment" AND (SELECT id_currency FROM '._DB_PREFIX_.'module_currency mcr WHERE mcr.id_module = m.id_module AND id_currency IN ('.(int)$context->currency->id.', -1, -2) LIMIT 1) IN ('.(int)$context->currency->id.', -1, -2))');
                }
                if (Validate::isLoadedObject($context->shop))
                    $sql->where('hm.id_shop = '.(int)$context->shop->id);

                if ($frontend)
                {
                    $sql->leftJoin('module_group', 'mg', 'mg.`id_module` = m.`id_module`');
                    if (Validate::isLoadedObject($context->shop))
                        $sql->where('mg.id_shop = '.((int)$context->shop->id).' AND  mg.`id_group` IN ('.implode(', ', $groups).')');
                    else
                        $sql->where('mg.`id_group` IN ('.implode(', ', $groups).')');
                    $sql->groupBy('hm.id_hook, hm.id_module');
                }

                $sql->orderBy('hm.`position`');

                $list = array();
                $disabledMethods = unserialize(Configuration::get("INTRUM_DISABLED_METHODS"));
                if ($hook_name == 'displayPayment') {
                    /* @var $context Context */

                    /* Make intrum request */
                    /* Intrum status */
                    $status = 0;
                    if (!defined('_PS_MODULE_INTRUMCOM_API')) {
                        require(_PS_MODULE_DIR_.'intrumcom/api/intrum.php');
                        require(_PS_MODULE_DIR_.'intrumcom/api/library_prestashop.php');
                    }

                    $request = CreatePrestaShopRequest($context->cart, $context->customer, $context->currency);
                    $xml = $request->createRequest();
                    $intrumCommunicator = new IntrumCommunicator();
                    $intrumCommunicator->setServer(Configuration::get("INTRUM_MODE"));
                    $response = $intrumCommunicator->sendRequest($xml);

                    if ($response) {
                        $intrumResponse = new IntrumResponse();
                        $intrumResponse->setRawResponse($response);
                        $intrumResponse->processResponse();
                        $status = $intrumResponse->getCustomerRequestStatus();
                    }
                    $intrumLogger = IntrumLogger::getInstance();
                    $intrumLogger->log(Array(
                        "firstname" => $request->getFirstName(),
                        "lastname" => $request->getLastName(),
                        "town" => $request->getTown(),
                        "postcode" => $request->getPostCode(),
                        "street" => trim($request->getFirstLine().' '.$request->getHouseNumber()),
                        "country" => $request->getCountryCode(),
                        "ip" => $_SERVER["REMOTE_ADDR"],
                        "status" => $status,
                        "request_id" => $request->getRequestId(),
                        "type" => "Request status",
                        "error" => ($status == 0) ? $response : "",
                        "response" => $response,
                        "request" => $xml
                    ));
                    $minAmount = Configuration::get("INTRUM_MIN_AMOUNT");
                    $currentAmount = $context->cart->getOrderTotal(true, Cart::BOTH);
                    $checkIntrum = true;
                    if ($minAmount > $currentAmount) {
                        $checkIntrum = false;
                    }

                    $allowed = Array(1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,27,28,29,30,50,51,52,53,54,55,56,57);
                    if (!in_array($status, $allowed)) {
                        $status = 0;
                    }
                    if ($result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql)) {
                        foreach ($result as $row)
                        {
                            if (!empty($disabledMethods[$status]) && is_array($disabledMethods[$status]) && in_array($row['id_module'], $disabledMethods[$status]) && $checkIntrum) {
                                continue;
                            }
                            $row['hook'] = strtolower($row['hook']);
                            if (!isset($list[$row['hook']]))
                                $list[$row['hook']] = array();

                            $list[$row['hook']][] = array(
                                'id_hook' => $row['id_hook'],
                                'module' => $row['module'],
                                'id_module' => $row['id_module'],
                                'live_edit' => $row['live_edit'],
                            );
                        }
                    }
                } else {
                    if ($result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql))
                        foreach ($result as $row)
                        {
                            $row['hook'] = strtolower($row['hook']);
                            if (!isset($list[$row['hook']]))
                                $list[$row['hook']] = array();

                            $list[$row['hook']][] = array(
                                'id_hook' => $row['id_hook'],
                                'module' => $row['module'],
                                'id_module' => $row['id_module'],
                                'live_edit' => $row['live_edit'],
                            );
                        }
                }
                if ($hook_name != 'displayPayment')
                {
                    Cache::store($cache_id, $list);
                    // @todo remove this in 1.6, we keep it in 1.5 for retrocompatibility
                    self::$_hook_modules_cache_exec = $list;
                }
            }
            else
                $list = Cache::retrieve($cache_id);

            // If hook_name is given, just get list of modules for this hook
            if ($hook_name)
            {
                $retro_hook_name = Hook::getRetroHookName($hook_name);
                $hook_name = strtolower($hook_name);

                $return = array();
                $inserted_modules = array();
                if (isset($list[$hook_name]))
                    $return = $list[$hook_name];
                foreach ($return as $module)
                    $inserted_modules[] = $module['id_module'];
                if (isset($list[$retro_hook_name]))
                    foreach ($list[$retro_hook_name] as $retro_module_call)
                        if (!in_array($retro_module_call['id_module'], $inserted_modules))
                            $return[] = $retro_module_call;

                return (count($return) > 0 ? $return : false);
            }
            else
                return $list;

        } else {
            $context = Context::getContext();
            $cache_id = 'hook_module_exec_list_'.(isset($context->shop->id) ? '_'.$context->shop->id : '' ).((isset($context->customer)) ? '_'.$context->customer->id : '');
            if (!Cache::isStored($cache_id) || $hook_name == 'displayPayment' || $hook_name == 'displayBackOfficeHeader')
            {
                $frontend = true;
                $groups = array();
                $use_groups = Group::isFeatureActive();
                if (isset($context->employee))
                    $frontend = false;
                else
                {
                    // Get groups list
                    if ($use_groups)
                    {
                        if (isset($context->customer) && $context->customer->isLogged())
                            $groups = $context->customer->getGroups();
                        elseif (isset($context->customer) && $context->customer->isLogged(true))
                            $groups = array((int)Configuration::get('PS_GUEST_GROUP'));
                        else
                            $groups = array((int)Configuration::get('PS_UNIDENTIFIED_GROUP'));
                    }
                }

                // SQL Request
                $sql = new DbQuery();
                $sql->select('h.`name` as hook, m.`id_module`, h.`id_hook`, m.`name` as module, h.`live_edit`');
                $sql->from('module', 'm');
                if ($hook_name != 'displayBackOfficeHeader')
                {
                    $sql->join(Shop::addSqlAssociation('module', 'm', true, 'module_shop.enable_device & '.(int)Context::getContext()->getDevice()));
                    $sql->innerJoin('module_shop', 'ms', 'ms.`id_module` = m.`id_module`');
                }
                $sql->innerJoin('hook_module', 'hm', 'hm.`id_module` = m.`id_module`');
                $sql->innerJoin('hook', 'h', 'hm.`id_hook` = h.`id_hook`');
                if ($hook_name != 'displayPayment')
                    $sql->where('h.name != "displayPayment"');
                // For payment modules, we check that they are available in the contextual country
                elseif ($frontend)
                {
                    if (Validate::isLoadedObject($context->country))
                        $sql->where('(h.name = "displayPayment" AND (SELECT id_country FROM '._DB_PREFIX_.'module_country mc WHERE mc.id_module = m.id_module AND id_country = '.(int)$context->country->id.' AND id_shop = '.(int)$context->shop->id.' LIMIT 1) = '.(int)$context->country->id.')');
                    if (Validate::isLoadedObject($context->currency))
                        $sql->where('(h.name = "displayPayment" AND (SELECT id_currency FROM '._DB_PREFIX_.'module_currency mcr WHERE mcr.id_module = m.id_module AND id_currency IN ('.(int)$context->currency->id.', -1, -2) LIMIT 1) IN ('.(int)$context->currency->id.', -1, -2))');
                }
                if (Validate::isLoadedObject($context->shop))
                    $sql->where('hm.id_shop = '.(int)$context->shop->id);

                if ($frontend)
                {
                    if ($use_groups)
                    {
                        $sql->leftJoin('module_group', 'mg', 'mg.`id_module` = m.`id_module`');
                        if (Validate::isLoadedObject($context->shop))
                            $sql->where('mg.id_shop = '.((int)$context->shop->id).' AND  mg.`id_group` IN ('.implode(', ', $groups).')');
                        else
                            $sql->where('mg.`id_group` IN ('.implode(', ', $groups).')');
                    }
                }

                $sql->groupBy('hm.id_hook, hm.id_module');
                $sql->orderBy('hm.`position`');

                $list = array();
                $disabledMethods = unserialize(Configuration::get("INTRUM_DISABLED_METHODS"));
                if ($hook_name == 'displayPayment') {
                    $status = 0;
                    if (!defined('_PS_MODULE_INTRUMCOM_API')) {
                        require(_PS_MODULE_DIR_.'intrumcom/api/intrum.php');
                        require(_PS_MODULE_DIR_.'intrumcom/api/library_prestashop.php');
                    }

                    $request = CreatePrestaShopRequest($context->cart, $context->customer, $context->currency);
                    $xml = $request->createRequest();
                    $intrumCommunicator = new IntrumCommunicator();
                    $intrumCommunicator->setServer(Configuration::get("INTRUM_MODE"));
                    $response = $intrumCommunicator->sendRequest($xml);

                    if ($response) {
                        $intrumResponse = new IntrumResponse();
                        $intrumResponse->setRawResponse($response);
                        $intrumResponse->processResponse();
                        $status = $intrumResponse->getCustomerRequestStatus();
                    }
                    $intrumLogger = IntrumLogger::getInstance();
                    $intrumLogger->log(Array(
                        "firstname" => $request->getFirstName(),
                        "lastname" => $request->getLastName(),
                        "town" => $request->getTown(),
                        "postcode" => $request->getPostCode(),
                        "street" => trim($request->getFirstLine().' '.$request->getHouseNumber()),
                        "country" => $request->getCountryCode(),
                        "ip" => $_SERVER["REMOTE_ADDR"],
                        "status" => $status,
                        "request_id" => $request->getRequestId(),
                        "type" => "Request status",
                        "error" => ($status == 0) ? $response : "",
                        "response" => $response,
                        "request" => $xml
                    ));
                    $minAmount = Configuration::get("INTRUM_MIN_AMOUNT");
                    $currentAmount = $context->cart->getOrderTotal(true, Cart::BOTH);
                    $checkIntrum = true;
                    if ($minAmount > $currentAmount) {
                        $checkIntrum = false;
                    }

                    $allowed = Array(1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,27,28,29,30,50,51,52,53,54,55,56,57);
                    if (!in_array($status, $allowed)) {
                        $status = 0;
                    }
                    if ($result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql)) {
                        foreach ($result as $row)
                        {
                            if (!empty($disabledMethods[$status]) && is_array($disabledMethods[$status]) && in_array($row['id_module'], $disabledMethods[$status]) && $checkIntrum) {
                                continue;
                            }
                            $row['hook'] = strtolower($row['hook']);
                            if (!isset($list[$row['hook']]))
                                $list[$row['hook']] = array();

                            $list[$row['hook']][] = array(
                                'id_hook' => $row['id_hook'],
                                'module' => $row['module'],
                                'id_module' => $row['id_module'],
                                'live_edit' => $row['live_edit'],
                            );
                        }
                    }
                } else {
                    if ($result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql))
                        foreach ($result as $row)
                        {
                            $row['hook'] = strtolower($row['hook']);
                            if (!isset($list[$row['hook']]))
                                $list[$row['hook']] = array();

                            $list[$row['hook']][] = array(
                                'id_hook' => $row['id_hook'],
                                'module' => $row['module'],
                                'id_module' => $row['id_module'],
                                'live_edit' => $row['live_edit'],
                            );
                        }
                }
                if ($hook_name != 'displayPayment' && $hook_name != 'displayBackOfficeHeader')
                {
                    Cache::store($cache_id, $list);
                    // @todo remove this in 1.6, we keep it in 1.5 for retrocompatibility
                    self::$_hook_modules_cache_exec = $list;
                }
            }
            else
                $list = Cache::retrieve($cache_id);

            // If hook_name is given, just get list of modules for this hook
            if ($hook_name)
            {
                $retro_hook_name = strtolower(Hook::getRetroHookName($hook_name));
                $hook_name = strtolower($hook_name);

                $return = array();
                $inserted_modules = array();
                if (isset($list[$hook_name]))
                    $return = $list[$hook_name];
                foreach ($return as $module)
                    $inserted_modules[] = $module['id_module'];
                if (isset($list[$retro_hook_name]))
                    foreach ($list[$retro_hook_name] as $retro_module_call)
                        if (!in_array($retro_module_call['id_module'], $inserted_modules))
                            $return[] = $retro_module_call;

                return (count($return) > 0 ? $return : false);
            }
            else
                return $list;
        }
    }
}