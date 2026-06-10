<?php

namespace GlpiPlugin\Inappnotification\Dashboard;

class CardRegistry
{
    public const CARD_UNREAD_TOTAL = 'inappnotification_unread_total';
    public const CARD_UNREAD_SAVEDSEARCH = 'inappnotification_unread_savedsearch';
    public const CARD_UNREAD_TICKET = 'inappnotification_unread_ticket';
    public const CARD_UNREAD_PROJECT = 'inappnotification_unread_project';
    public const CARD_UNREAD_REMINDER = 'inappnotification_unread_reminder';
    public const CARD_UNREAD_SLA = 'inappnotification_unread_sla';
    public const CARD_UNREAD_CRONTASK = 'inappnotification_unread_crontask';

    public static function getCards(): array
    {
        $group = __('InAppNotification', 'inappnotification');
        $cards = [
            self::CARD_UNREAD_TOTAL => self::buildCard(
                __('Unread notifications', 'inappnotification'),
                'unreadTotal',
                []
            ),
            self::CARD_UNREAD_SAVEDSEARCH => self::buildCard(
                __('Unread saved search notifications', 'inappnotification'),
                'unreadByGroup',
                ['group' => 'savedsearch']
            ),
            self::CARD_UNREAD_TICKET => self::buildCard(
                __('Unread ticket notifications', 'inappnotification'),
                'unreadByGroup',
                ['group' => 'ticket']
            ),
            self::CARD_UNREAD_PROJECT => self::buildCard(
                __('Unread project notifications', 'inappnotification'),
                'unreadByGroup',
                ['group' => 'project']
            ),
            self::CARD_UNREAD_REMINDER => self::buildCard(
                __('Unread reminder notifications', 'inappnotification'),
                'unreadByGroup',
                ['group' => 'reminder']
            ),
            self::CARD_UNREAD_SLA => self::buildCard(
                __('Unread SLA notifications', 'inappnotification'),
                'unreadByGroup',
                ['group' => 'sla']
            ),
            self::CARD_UNREAD_CRONTASK => self::buildCard(
                __('Unread scheduled task notifications', 'inappnotification'),
                'unreadByGroup',
                ['group' => 'crontask']
            ),
        ];

        foreach ($cards as &$card) {
            $card['group'] = $group;
        }

        return $cards;
    }

    private static function buildCard(string $label, string $provider_method, array $args): array
    {
        return [
            'widgettype' => ['bigNumber'],
            'itemtype'   => '\\GlpiPlugin\\Inappnotification\\AlertEntry',
            'label'      => $label,
            'provider'   => CardProvider::class . '::' . $provider_method,
            'args'       => $args,
            'filters'    => [],
        ];
    }
}
