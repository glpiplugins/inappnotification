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
use GlpiPlugin\Inappnotification\Service\ScheduledNotificationStore;
use Override;
use Session;
use User;

/**
 * The logged-in user's upcoming notifications as a search itemtype (projected from the all-users
 * table by ScheduledNotificationStore::refreshForUser()).
 */
class ScheduledEntry extends CommonDBTM implements ExtraVisibilityCriteria, DefaultSearchRequestInterface
{
    /** Column holding the recipient's users_id. */
    protected const RECIPIENT_FIELD = 'users_id';

    public static function getTypeName($nb = 0): string
    {
        return _n('Upcoming notification', 'Upcoming notifications', $nb, 'inappnotification');
    }

    public static function getIcon(): string
    {
        return 'ti ti-calendar-time';
    }

    public static function canView(): bool
    {
        return (int) Session::getLoginUserID() > 0;
    }

    public static function getTable($classname = null): string
    {
        return ScheduledNotificationStore::TABLE;
    }

    public static function getVisibilityCriteria(bool $forceall = false): array
    {
        $users_id = (int) Session::getLoginUserID();
        if ($users_id <= 0) {
            return ['WHERE' => [static::getTable() . '.id' => 0]];
        }

        return ['WHERE' => [static::getTable() . '.users_id' => $users_id]];
    }

    public function rawSearchOptions(): array
    {
        $table = static::getTable();

        return [
            [
                'id'   => 'common',
                'name' => __('Characteristics'),
            ],
            [
                'id'            => 1,
                'table'         => $table,
                'itemtype'      => static::class,
                'field'         => 'scheduled_at',
                'name'          => __('Date'),
                'datatype'      => 'datetime',
                'massiveaction' => false,
            ],
            [
                'id'            => 2,
                'table'         => $table,
                'itemtype'      => static::class,
                'field'         => 'kind',
                'name'          => __('Notification type', 'inappnotification'),
                'datatype'      => 'specific',
                'searchtype'    => ['equals', 'notequals'],
                'massiveaction' => false,
            ],
            [
                'id'            => 7,
                'table'         => $table,
                'itemtype'      => static::class,
                'field'         => 'itemtype',
                'name'          => __('Type'),
                'datatype'      => 'itemtypename',
                'massiveaction' => false,
            ],
            [
                'id'               => 3,
                'table'            => $table,
                'itemtype'         => static::class,
                'field'            => 'recipient_label',
                'name'             => __('Recipient'),
                'datatype'         => 'specific',
                'additionalfields' => [static::RECIPIENT_FIELD],
                'massiveaction'    => false,
            ],
            [
                'id'               => 4,
                'table'            => $table,
                'itemtype'         => static::class,
                'field'            => 'item_label',
                'name'             => __('Item'),
                'datatype'         => 'specific',
                'additionalfields' => ['item_url'],
                'massiveaction'    => false,
            ],
            [
                'id'               => 5,
                'table'            => $table,
                'itemtype'         => static::class,
                'field'            => 'detail',
                'name'             => __('Details'),
                'datatype'         => 'specific',
                'additionalfields' => ['kind'],
                'massiveaction'    => false,
            ],
            [
                'id'            => 6,
                'table'         => $table,
                'itemtype'      => static::class,
                'field'         => 'entity_label',
                'name'          => __('Entity'),
                'datatype'      => 'string',
                'massiveaction' => false,
            ],
        ];
    }

    /**
     * The user's own entries are visible whatever entity is active: per-user scoping is done by the
     * search restriction in hook.php, and `entities_id` only records the source item's entity.
     * Otherwise GLPI search hides them in a sub-entity.
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

        // The plugin's list page, not GLPI's generic list route, so the search form, sorting and
        // paging stay there.
        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/inappnotification/front/scheduled.php';
    }

    #[Override]
    public static function getDefaultSearchRequest(): array
    {
        return [
            'forcetoview'  => [1, 2, 7, 3, 4, 5, 6],
            'sort'         => 1,
            'order'        => 'ASC',
        ];
    }

    #[Override]
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        return match ($field) {
            'kind' => htmlescape(ScheduledNotificationStore::getKindLabels()[$values['kind']] ?? $values['kind']),
            'detail' => htmlescape(ScheduledNotificationStore::formatDetail((string) ($values['kind'] ?? ''), (string) $values['detail'])),
            'recipient_label' => self::renderRecipient((string) $values['recipient_label'], (int) ($values[static::RECIPIENT_FIELD] ?? 0)),
            'item_label' => self::renderLink((string) $values['item_label'], (string) ($values['item_url'] ?? '')),
            default => parent::getSpecificValueToDisplay($field, $values, $options),
        };
    }

    #[Override]
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'kind') {
            $options['display'] = false;
            $options['value'] = is_array($values) ? ($values[$field] ?? '') : $values;

            return Dropdown::showFromArray($name, ScheduledNotificationStore::getKindLabels(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    #[Override]
    public function getName($options = [])
    {
        return (string) $this->fields['item_label'];
    }

    #[Override]
    public function getLinkURL()
    {
        return $this->fields['item_url'] !== '' ? $this->fields['item_url'] : parent::getLinkURL();
    }

    /**
     * Recipient cell: the user (linked), or the recipients of the notification targets when they
     * could not be resolved to users.
     */
    private static function renderRecipient(string $label, int $users_id): string
    {
        if ($users_id <= 0) {
            return htmlescape(__('Recipients from notification targets', 'inappnotification'));
        }

        return self::renderLink($label, User::getFormURLWithID($users_id));
    }

    private static function renderLink(string $label, string $url): string
    {
        if ($url === '') {
            return htmlescape($label);
        }

        return sprintf('<a href="%s">%s</a>', htmlescape($url), htmlescape($label));
    }
}
