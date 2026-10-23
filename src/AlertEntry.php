<?php

namespace GlpiPlugin\Inappnotification;

use CommonDBTM;
use ExtraVisibilityCriteria;
use GlpiPlugin\Inappnotification\Service\AlertStore;
use Glpi\Search\DefaultSearchRequestInterface;
use Html;
use MassiveAction;
use Session;

class AlertEntry extends CommonDBTM implements ExtraVisibilityCriteria, DefaultSearchRequestInterface
{
    public static $rightname = 'bookmark_public';

    public static function getTypeName($nb = 0): string
    {
        return _n('Search notification', 'Search notifications', $nb, 'inappnotification');
    }

    public static function getIcon(): string
    {
        return 'ti ti-bell';
    }

    public static function canView(): bool
    {
        return (int) Session::getLoginUserID() > 0;
    }

    public static function canUpdate(): bool
    {
        return (int) Session::getLoginUserID() > 0;
    }

    public static function getMenuName(): string
    {
        return __('Search notifications', 'inappnotification');
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getMenuName(),
            'page'  => '/plugins/inappnotification/front/center.php',
            'icon'  => self::getIcon(),
        ];
    }

    public static function getSectorizedDetails(): array
    {
        return ['tools', self::class];
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_inappnotification_alerts';
    }

    public static function getVisibilityCriteria(bool $forceall = false): array
    {
        $users_id = (int) Session::getLoginUserID();
        if ($users_id <= 0) {
            return ['WHERE' => [self::getTable() . '.id' => 0]];
        }

        return ['WHERE' => [self::getTable() . '.users_id' => $users_id]];
    }

    public function rawSearchOptions(): array
    {
        $tab = [];

        $tab[] = [
            'id' => 'common',
            'name' => __('Characteristics'),
        ];

        $tab[] = [
            'id' => 1,
            'table' => self::getTable(),
            'field' => 'id',
            'name' => __('ID'),
            'itemtype' => self::class,
            'datatype' => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id' => 2,
            'table' => self::getTable(),
            'field' => 'alert_name',
            'name' => __('Name'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
            'massiveaction' => false,
        ];

        $tab[] = [
            'id' => 3,
            'table' => self::getTable(),
            'field' => 'alert_message',
            'name' => __('Message'),
            'itemtype' => self::class,
            'datatype' => 'text',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id' => 4,
            'table' => self::getTable(),
            'field' => 'occurrence_count',
            'name' => __('Occurrences'),
            'itemtype' => self::class,
            'datatype' => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id' => 5,
            'table' => self::getTable(),
            'field' => 'unread_count',
            'name' => __('Unread'),
            'itemtype' => self::class,
            'datatype' => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id' => 6,
            'table' => self::getTable(),
            'field' => 'last_triggered_at',
            'name' => __('Last update'),
            'itemtype' => self::class,
            'datatype' => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id' => 7,
            'table' => self::getTable(),
            'field' => 'source_group',
            'name' => __('Source', 'inappnotification'),
            'itemtype' => self::class,
            'datatype' => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id' => 8,
            'table' => self::getTable(),
            'field' => 'source_itemtype',
            'name' => __('Itemtype'),
            'itemtype' => self::class,
            'datatype' => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id' => 9,
            'table' => self::getTable(),
            'field' => 'is_suppressed',
            'name' => __('Suppressed', 'inappnotification'),
            'itemtype' => self::class,
            'datatype' => 'bool',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id' => 10,
            'table' => self::getTable(),
            'field' => 'snoozed_until',
            'name' => __('Snoozed until', 'inappnotification'),
            'itemtype' => self::class,
            'datatype' => 'datetime',
            'massiveaction' => false,
        ];

        return $tab;
    }

    /**
     * The user's own entries are visible whatever entity is active: per-user scoping is done by the
     * search restriction in hook.php, and `entities_id` only records the source item's entity.
     * Otherwise GLPI search hides them in a sub-entity while the bell still counts them.
     */
    #[\Override]
    public function isEntityAssign()
    {
        return false;
    }

    #[\Override]
    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        // The plugin's list page (with its action buttons), not GLPI's generic list route, so the
        // search form, sorting and paging stay there.
        return ($full ? (string) ($CFG_GLPI['root_doc'] ?? '') : '') . '/plugins/inappnotification/front/center.php';
    }

    #[\Override]
    public static function getDefaultSearchRequest(): array
    {
        return [
            'forcetoview'  => [2, 3, 7, 4, 5, 6],
            'sort'         => 6, // last_triggered_at
            'order'        => 'DESC',
        ];
    }

    #[\Override]
    public function getName($options = [])
    {
        return (string) ($this->fields['alert_name'] ?? '');
    }

    #[\Override]
    public function canUpdateItem(): bool
    {
        return (int) Session::getLoginUserID() > 0
            && (int) ($this->fields['users_id'] ?? 0) === (int) Session::getLoginUserID();
    }

    #[\Override]
    public function getSpecificMassiveActions($checkitem = null): array
    {
        $actions = parent::getSpecificMassiveActions($checkitem);

        if (self::canUpdate()) {
            $actions[self::class . MassiveAction::CLASS_ACTION_SEPARATOR . 'reactivate']
                = __('Reactivate', 'inappnotification');
        }

        return $actions;
    }

    #[\Override]
    public static function showMassiveActionsSubForm(MassiveAction $ma)
    {
        if ($ma->getAction() === 'reactivate') {
            echo Html::submit(__('Reactivate', 'inappnotification'), ['name' => 'massiveaction']);
            return true;
        }

        return parent::showMassiveActionsSubForm($ma);
    }

    #[\Override]
    public static function processMassiveActionsForOneItemtype(
        MassiveAction $ma,
        CommonDBTM $item,
        array $ids
    ) {
        if ($ma->getAction() === 'reactivate') {
            $users_id = (int) Session::getLoginUserID();

            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id <= 0) {
                    $ma->itemDone($item::getType(), $id, MassiveAction::ACTION_KO);
                    continue;
                }

                $entry = new self();
                if (!$entry->getFromDB($id)) {
                    $ma->itemDone($item::getType(), $id, MassiveAction::ACTION_KO);
                    continue;
                }

                if ((int) ($entry->fields['users_id'] ?? 0) !== $users_id) {
                    $ma->itemDone($item::getType(), $id, MassiveAction::ACTION_NORIGHT);
                    continue;
                }

                AlertStore::reactivate($users_id, $id);
                $ma->itemDone($item::getType(), $id, MassiveAction::ACTION_OK);
            }
            return;
        }

        parent::processMassiveActionsForOneItemtype($ma, $item, $ids);
    }

    #[\Override]
    public function getLinkURL()
    {
        $url = (string) ($this->fields['target_url'] ?? '');
        return $url !== '' ? $url : parent::getLinkURL();
    }
}
