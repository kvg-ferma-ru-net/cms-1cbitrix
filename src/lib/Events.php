<?php

namespace Innokassa\Fiscal;

use Bitrix\Main\EventResult;
use Bitrix\Sale\ResultError;
use Innokassa\Fiscal\Impl\ClientFactory;
use Innokassa\MDK\Settings\SettingsAbstract;
use Innokassa\MDK\Entities\Atoms\ReceiptSubType;
use Innokassa\MDK\Exceptions\Services\AutomaticException;

/**
 * Статический класс обработчиков событий
 */
class Events
{
    /**
     * Обработчик события 'перед сохранением заказа'
     *
     * @link https://dev.1c-bitrix.ru/api_d7/bitrix/sale/events/order_saved.php
     *
     * @param \Bitrix\Main\Event $event
     * @return void
     */
    public static function onSaleOrderBeforeSaved(\Bitrix\Main\Event $event)
    {
        $mdk = ClientFactory::build();
        $settings = $mdk->componentSettings();
        $automatic = $mdk->serviceAutomatic();

        /** @var \Bitrix\Sale\Order */
        $order = $event->getParameter("ENTITY");

        // если заказ только создается (не имеет id) - пропускаем
        if (!$order->getId()) {
            return;
        }

        $orderFields = $order->getFieldValues();
        $siteId = $order->getSiteId();

        // если заказ внешний, а нужна обработка только внутренних заказов - завершаем
        if (
            !(
                !$order->isExternal()
                || $settings->get("only_internal", $siteId) != "Y"
            )
        ) {
            return;
        }

        // если заказ не оплачен - завершаем
        if (!$order->isPaid()) {
            return;
        }

        // проверяем все ли системы оплаты соответсвуют тому что выбрано в модуле
        foreach ($order->getPaymentCollection() as $payment) {
            $paymentFields = $payment->getFieldValues();

            $isCorrectPaySystem = (
                $settings->get("paysystem", $siteId) == -1
                || $settings->get("paysystem", $siteId) == $paymentFields["PAY_SYSTEM_ID"]
            );

            // если была оплата по неподходящему способу - пропускаем
            if ($paymentFields["PAID"] == "Y" && !$isCorrectPaySystem) {
                return;
            }
        }

        try {
            if (
                $settings->getScheme($siteId) == SettingsAbstract::SCHEME_PRE_FULL
                && $settings->getOrderStatusReceiptPre($siteId) == $orderFields["STATUS_ID"]
            ) {
                $automatic->fiscalize($order->getId(), $siteId, ReceiptSubType::PRE);
            } elseif ($settings->getOrderStatusReceiptFull($siteId) == $orderFields["STATUS_ID"]) {
                $automatic->fiscalize($order->getId(), $siteId, ReceiptSubType::FULL);
            }
        } catch (AutomaticException $e) {
        } catch (\Exception $e) {
            $event->addResult(
                new EventResult(
                    EventResult::ERROR,
                    new ResultError(sprintf('Не удалось фискализировать заказ - %s', $e->getMessage())),
                    'innokassa.fiscal'
                )
            );
        }
    }

    //########################################################################

    /**
     * Обработчик события рендера админ меню
     *
     * @link https://dev.1c-bitrix.ru/api_help/main/events/onadmincontextmenushow.php
     *
     * @param array &$aItems
     * @return void
     */
    public static function onAdminContextMenuShow(array &$aItems)
    {
        if (
            $GLOBALS['APPLICATION']->GetCurPage() == '/bitrix/admin/sale_order_view.php'
            && isset($_GET["ID"])
        ) {
            $aItems[] = [
                "TEXT" => 'Управление чеками',
                "LINK" => 'https://crm.innokassa.ru/',
                'LINK_PARAM' => 'target=_blank',
                "ICON" => "btn_green"
            ];
        }
    }
}
