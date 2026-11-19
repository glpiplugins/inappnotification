<?php

/**
 * -------------------------------------------------------------------------
 * InAppNotification plugin for GLPI
 *
 * https://github.com/glpiplugins/inappnotification
 *
 * @copyright 2026 Roy Brannath
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of InAppNotification.
 *
 * InAppNotification is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * InAppNotification is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with InAppNotification. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Inappnotification;

use CommonDBTM;
use Dropdown;
use ExtraVisibilityCriteria;
use Glpi\Search\DefaultSearchRequestInterface;
use GlpiPlugin\Inappnotification\Service\AlertStore;
use Html;
use MassiveAction;
use Override;
use Session;

/**
 * The logged-in user's in-app notifications as a search itemtype (list page, menu, dashboard).
 *
 * No GLPI right applies: every user may view and update only their own entries (search
 * restriction in hook.php); creating, deleting and purging through the UI is not offered.
 */
class AlertEntry extends CommonDBTM implements ExtraVisibilityCriteria, DefaultSearchRequestInterface
{
    public static function getTypeName($nb = 0): string
    {
        return _n('In-app notification', 'In-app notifications', $nb, 'inappnotification');
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
        return __('In-app notifications', 'inappnotification');
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
        return AlertStore::TABLE;
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
        return [[
            'id' => 'common',
            'name' => __('Characteristics'),
        ], [
            'id' => 1,
            'table' => self::getTable(),
            'field' => 'id',
            'name' => __('ID'),
            'itemtype' => self::class,
            'datatype' => 'number',
            'massiveaction' => false,
        ], [
            'id' => 2,
            'table' => self::getTable(),
            'field' => 'alert_name',
            'name' => __('Name'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
            'massiveaction' => false,
        ], [
            'id' => 3,
            'table' => self::getTable(),
            'field' => 'alert_message',
            'name' => __('Message'),
            'itemtype' => self::class,
            'datatype' => 'text',
            'massiveaction' => false,
        ], [
            'id' => 4,
            'table' => self::getTable(),
            'field' => 'occurrence_count',
            'name' => __('Occurrences', 'inappnotification'),
            'itemtype' => self::class,
            'datatype' => 'number',
            'massiveaction' => false,
        ], [
            'id' => 5,
            'table' => self::getTable(),
            'field' => 'unread_count',
            'name' => __('Unread', 'inappnotification'),
            'itemtype' => self::class,
            'datatype' => 'number',
            'massiveaction' => false,
        ], [
            'id' => 6,
            'table' => self::getTable(),
            'field' => 'last_triggered_at',
            'name' => __('Last update'),
            'itemtype' => self::class,
            'datatype' => 'datetime',
            'massiveaction' => false,
        ], [
            'id' => 7,
            'table' => self::getTable(),
            'field' => 'source_group',
            'name' => __('Source', 'inappnotification'),
            'itemtype' => self::class,
            'datatype' => 'specific',
            'searchtype' => ['equals', 'notequals'],
            'massiveaction' => false,
        ], [
            'id' => 8,
            'table' => self::getTable(),
            'field' => 'source_itemtype',
            'name' => __('Item type'),
            'itemtype' => self::class,
            'datatype' => 'itemtypename',
            'massiveaction' => false,
        ], [
            'id' => 9,
            'table' => self::getTable(),
            'field' => 'is_suppressed',
            'name' => __('Suppressed', 'inappnotification'),
            'itemtype' => self::class,
            'datatype' => 'bool',
            'massiveaction' => false,
        ], [
            'id' => 10,
            'table' => self::getTable(),
            'field' => 'snoozed_until',
            'name' => __('Snoozed until', 'inappnotification'),
            'itemtype' => self::class,
            'datatype' => 'datetime',
            'massiveaction' => false,
        ]];
    }

    /**
     * The user's own entries are visible whatever entity is active: per-user scoping is done by the
     * search restriction in hook.php, and `entities_id` only records the source item's entity.
     * Otherwise GLPI search hides them in a sub-entity while the bell still counts them.
     */
    #[Override]
    public function isEntityAssign()
    {
        return false;
    }

    #[Override]
    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        // The plugin's list page (with its action buttons), not GLPI's generic list route, so the
        // search form, sorting and paging stay there.
        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/inappnotification/front/center.php';
    }

    #[Override]
    public static function getDefaultSearchRequest(): array
    {
        return [
            'forcetoview'  => [2, 3, 7, 4, 5, 6],
            'sort'         => 6, // last_triggered_at
            'order'        => 'DESC',
        ];
    }

    #[Override]
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'source_group') {
            $group = is_array($values) ? (string) $values[$field] : (string) $values;

            return htmlescape(AlertStore::getSourceGroupLabels()[$group] ?? $group);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    #[Override]
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'source_group') {
            $options['display'] = false;
            $options['value'] = is_array($values) ? ($values[$field] ?? '') : $values;

            return Dropdown::showFromArray($name, AlertStore::getSourceGroupLabels(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    #[Override]
    public function getName($options = [])
    {
        return (string) ($this->fields['alert_name'] ?? '');
    }

    #[Override]
    public function canUpdateItem(): bool
    {
        return (int) Session::getLoginUserID() > 0
            && (int) ($this->fields['users_id'] ?? 0) === (int) Session::getLoginUserID();
    }

    #[Override]
    public function getSpecificMassiveActions($checkitem = null): array
    {
        $actions = parent::getSpecificMassiveActions($checkitem);

        if (self::canUpdate()) {
            $actions[self::class . MassiveAction::CLASS_ACTION_SEPARATOR . 'reactivate']
                = __('Reactivate', 'inappnotification');
        }

        return $actions;
    }

    #[Override]
    public static function showMassiveActionsSubForm(MassiveAction $ma)
    {
        if ($ma->getAction() === 'reactivate') {
            echo Html::submit(__('Reactivate', 'inappnotification'), ['name' => 'massiveaction']);
            return true;
        }

        return parent::showMassiveActionsSubForm($ma);
    }

    #[Override]
    public static function processMassiveActionsForOneItemtype(
        MassiveAction $ma,
        CommonDBTM $item,
        array $ids,
    ) {
        if ($ma->getAction() === 'reactivate') {
            $users_id = (int) Session::getLoginUserID();

            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id <= 0) {
                    $ma->itemDone($item::class, $id, MassiveAction::ACTION_KO);
                    continue;
                }

                $entry = new self();
                if (!$entry->getFromDB($id)) {
                    $ma->itemDone($item::class, $id, MassiveAction::ACTION_KO);
                    continue;
                }

                if ((int) ($entry->fields['users_id'] ?? 0) !== $users_id) {
                    $ma->itemDone($item::class, $id, MassiveAction::ACTION_NORIGHT);
                    continue;
                }

                AlertStore::reactivate($users_id, $id);
                $ma->itemDone($item::class, $id, MassiveAction::ACTION_OK);
            }

            return;
        }

        parent::processMassiveActionsForOneItemtype($ma, $item, $ids);
    }

    #[Override]
    public function getLinkURL()
    {
        $url = (string) ($this->fields['target_url'] ?? '');
        return $url !== '' ? $url : parent::getLinkURL();
    }
}
