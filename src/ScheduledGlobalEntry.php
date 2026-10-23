<?php

namespace GlpiPlugin\Inappnotification;

use CommonDBTM;
use ExtraVisibilityCriteria;
use Glpi\Search\DefaultSearchRequestInterface;
use User;

class ScheduledGlobalEntry extends CommonDBTM implements ExtraVisibilityCriteria, DefaultSearchRequestInterface
{
    public static string $rightname = Access::RIGHT_UPCOMING_ALL;

    public static function getTypeName($nb = 0): string
    {
        return _n('Upcoming notification (all users)', 'Upcoming notifications (all users)', $nb, 'inappnotification');
    }

    public static function getIcon(): string
    {
        return 'ti ti-users';
    }

    public static function canView(): bool
    {
        return Access::canViewAllUpcoming();
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_inappnotification_scheduled_global';
    }

    public static function getVisibilityCriteria(bool $forceall = false): array
    {
        if (!Access::canViewAllUpcoming()) {
            return ['WHERE' => [self::getTable() . '.id' => 0]];
        }

        return [];
    }

    public function rawSearchOptions(): array
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => __('Characteristics'),
        ];

        $tab[] = [
            'id'            => 1,
            'table'         => self::getTable(),
            'field'         => 'scheduled_at',
            'name'          => __('When'),
            'itemtype'      => self::class,
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 2,
            'table'         => self::getTable(),
            'field'         => 'kind_label',
            'name'          => __('Notification type', 'inappnotification'),
            'itemtype'      => self::class,
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 7,
            'table'         => self::getTable(),
            'field'         => 'itemtype',
            'name'          => __('Type'),
            'itemtype'      => self::class,
            'datatype'      => 'itemtypename',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 3,
            'table'         => self::getTable(),
            'field'         => 'recipient_label',
            'name'          => __('Recipient'),
            'itemtype'      => self::class,
            'datatype'      => 'specific',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 4,
            'table'         => self::getTable(),
            'field'         => 'item_label',
            'name'          => __('Item'),
            'itemtype'      => self::class,
            'datatype'      => 'specific',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 5,
            'table'         => self::getTable(),
            'field'         => 'detail',
            'name'          => __('Details'),
            'itemtype'      => self::class,
            'datatype'      => 'text',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 6,
            'table'         => self::getTable(),
            'field'         => 'entity_label',
            'name'          => __('Entity'),
            'itemtype'      => self::class,
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        return $tab;
    }

    #[\Override]
    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        // The plugin's list page (with its action buttons), not GLPI's generic list route, so the
        // search form, sorting and paging stay there.
        return ($full ? (string) ($CFG_GLPI['root_doc'] ?? '') : '') . '/plugins/inappnotification/front/scheduled_all.php';
    }

    #[\Override]
    public static function getDefaultSearchRequest(): array
    {
        return [
            'forcetoview'  => [1, 2, 7, 3, 4, 5, 6],
            'sort'         => 1,
            'order'        => 'ASC',
        ];
    }

    #[\Override]
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            return parent::getSpecificValueToDisplay($field, $values, $options);
        }

        $row = self::loadRowFromValues($values, $options);
        if (!is_array($row)) {
            return parent::getSpecificValueToDisplay($field, $values, $options);
        }

        if ($field === 'recipient_label') {
            return self::renderRecipientCell($row);
        }

        if ($field === 'item_label') {
            return self::renderItemCell($row);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    #[\Override]
    public function getName($options = [])
    {
        return (string) ($this->fields['item_label'] ?? '');
    }

    #[\Override]
    public function getLinkURL()
    {
        $url = trim((string) ($this->fields['item_url'] ?? ''));
        return $url !== '' ? $url : parent::getLinkURL();
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private static function loadRowFromValues(array $values, array $options = []): ?array
    {
        static $cache = null;

        $id = (int) ($values['id'] ?? 0);
        if ($id <= 0 && isset($options['raw_data']) && is_array($options['raw_data'])) {
            $id = (int) ($options['raw_data']['id'] ?? 0);
        }
        if ($id <= 0) {
            return null;
        }

        if (!is_array($cache)) {
            $cache = getAllDataFromTable(self::getTable());
        }

        return $cache[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function renderRecipientCell(array $row): string
    {
        $label = trim((string) ($row['recipient_label'] ?? ''));
        if ($label === '') {
            return '';
        }

        $recipient_users_id = (int) ($row['recipient_users_id'] ?? 0);
        if ($recipient_users_id <= 0) {
            return htmlescape($label);
        }

        $url = User::getFormURLWithID($recipient_users_id);
        return sprintf(
            "<a href='%s'>%s</a>",
            htmlescape($url),
            htmlescape($label)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function renderItemCell(array $row): string
    {
        $label = trim((string) ($row['item_label'] ?? ''));
        if ($label === '') {
            $label = __('Item');
        }

        $url = trim((string) ($row['item_url'] ?? ''));
        if ($url === '') {
            return htmlescape($label);
        }

        return sprintf(
            "<a href='%s'>%s</a>",
            htmlescape($url),
            htmlescape($label)
        );
    }
}
