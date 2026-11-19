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

declare(strict_types=1);

namespace GlpiPlugin\Inappnotification\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Inappnotification\Service\AlertStore;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON endpoint of the notification bell (mounted under /plugins/inappnotification/).
 *
 * Every action only touches the logged-in user's own entries (AlertStore scopes by users_id).
 * Any logged-in user may call it, also from the simplified interface (plugin controllers default
 * to "central access").
 */
final class NotificationController extends AbstractController
{
    private const int LIST_LIMIT = 15;

    #[Route('/Notifications', name: 'notifications_list', methods: ['GET'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function list(Request $request): JsonResponse
    {
        $limit = $request->query->getInt('limit', self::LIST_LIMIT);
        if ($limit <= 0 || $limit > 100) {
            $limit = self::LIST_LIMIT;
        }

        return $this->respond(AlertStore::getForUser($this->getUserId(), $limit));
    }

    // GET is routed too, only to answer it with 405: a GET on a POST-only route would end as an
    // unmapped MethodNotAllowedException (HTTP 500, logged as critical).
    #[Route(
        '/Notifications/{action}',
        name: 'notifications_action',
        requirements: ['action' => 'mark_read|mark_all_read|mark_unread|snooze|suppress'],
        methods: ['GET', 'POST'],
    )]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function action(Request $request, string $action): JsonResponse
    {
        if (!$request->isMethod('POST')) {
            // State changes only via POST: the CSRF check does not apply to GET requests.
            return $this->error(__('Unsupported action', 'inappnotification'), Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $users_id = $this->getUserId();
        $ids = $this->getIds($request);

        switch ($action) {
            case 'mark_read':
                foreach ($ids as $id) {
                    AlertStore::markAsRead($users_id, $id);
                }

                break;
            case 'mark_all_read':
                AlertStore::markAllAsRead($users_id);
                break;
            case 'mark_unread':
                foreach ($ids as $id) {
                    AlertStore::markAsUnread($users_id, $id);
                }

                break;
            case 'snooze':
                $minutes = $request->request->getInt('minutes', 1440);
                foreach ($ids as $id) {
                    AlertStore::snooze($users_id, $id, $minutes);
                }

                break;
            case 'suppress':
                foreach ($ids as $id) {
                    AlertStore::suppress($users_id, $id);
                }

                break;
        }

        return $this->respond(AlertStore::getForUser($users_id, self::LIST_LIMIT));
    }

    private function getUserId(): int
    {
        return (int) Session::getLoginUserID();
    }

    /**
     * Entry ids from `ids[]` (or a comma-separated `ids`), falling back to a single `id`.
     *
     * @return list<int>
     */
    private function getIds(Request $request): array
    {
        $input = $request->request->all();
        $raw = $input['ids'] ?? [];
        if (!is_array($raw)) {
            $raw = explode(',', (string) $raw);
        }

        if ($raw === []) {
            $raw = [$input['id'] ?? 0];
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function respond(array $data): JsonResponse
    {
        return $this->json(['success' => 1, 'data' => $data]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['success' => 0, 'error' => $message], $status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

        return $response;
    }
}
