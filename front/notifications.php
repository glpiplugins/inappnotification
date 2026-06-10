<?php

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\SessionExpiredException;
use GlpiPlugin\Inappnotification\Service\AlertStore;

include_once __DIR__ . '/../../../inc/includes.php';

if (!class_exists(AlertStore::class)) {
    require_once __DIR__ . '/../src/Service/AlertStore.php';
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$checkAjaxCsrf = static function (): void {
    $token = (string) ($_POST['_glpi_csrf_token'] ?? $_SERVER['HTTP_X_GLPI_CSRF_TOKEN'] ?? '');

    // Same behavior as GLPI AJAX controllers: accept header token and preserve token for concurrent requests.
    Session::checkCSRF([
        '_glpi_csrf_token' => $token,
    ], true);
};

$resolveIds = static function (): array {
    $raw = $_POST['ids'] ?? [];
    if (!is_array($raw)) {
        $raw = explode(',', (string) $raw);
    }

    $ids = [];
    foreach ($raw as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
};

try {
    Session::checkLoginUser();

    $users_id = (int) Session::getLoginUserID();
    $action = (string) ($_REQUEST['action'] ?? 'list');

    if ($action === 'list') {
        $limit = (int) ($_GET['limit'] ?? 15);
        if ($limit <= 0 || $limit > 100) {
            $limit = 15;
        }

        $respond([
            'success' => 1,
            'data' => AlertStore::getForUser($users_id, $limit),
        ]);
    }

    if ($action === 'muted_list') {
        $limit = (int) ($_GET['limit'] ?? 200);
        if ($limit <= 0 || $limit > 500) {
            $limit = 200;
        }

        $respond([
            'success' => 1,
            'data' => [
                'items' => AlertStore::getMutedForUser($users_id, $limit),
                'csrf_token' => Session::getNewCSRFToken(),
            ],
        ]);
    }

    if ($action === 'mark_read') {
        $checkAjaxCsrf();
        $ids = $resolveIds();
        if ($ids !== []) {
            foreach ($ids as $id) {
                AlertStore::markAsRead($users_id, $id);
            }
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            AlertStore::markAsRead($users_id, $id);
        }

        $respond([
            'success' => 1,
            'data' => AlertStore::getForUser($users_id, 15),
        ]);
    }

    if ($action === 'mark_all_read') {
        $checkAjaxCsrf();
        AlertStore::markAllAsRead($users_id);

        $respond([
            'success' => 1,
            'data' => AlertStore::getForUser($users_id, 15),
        ]);
    }

    if ($action === 'mark_unread') {
        $checkAjaxCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        AlertStore::markAsUnread($users_id, $id);

        $respond([
            'success' => 1,
            'data' => AlertStore::getForUser($users_id, 15),
        ]);
    }

    if ($action === 'snooze') {
        $checkAjaxCsrf();
        $ids = $resolveIds();
        $minutes = (int) ($_POST['minutes'] ?? 1440);
        if ($ids !== []) {
            foreach ($ids as $id) {
                AlertStore::snooze($users_id, $id, $minutes);
            }
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            AlertStore::snooze($users_id, $id, $minutes);
        }

        $respond([
            'success' => 1,
            'data' => AlertStore::getForUser($users_id, 15),
        ]);
    }

    if ($action === 'suppress') {
        $checkAjaxCsrf();
        $ids = $resolveIds();
        if ($ids !== []) {
            foreach ($ids as $id) {
                AlertStore::suppress($users_id, $id);
            }
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            AlertStore::suppress($users_id, $id);
        }

        $respond([
            'success' => 1,
            'data' => AlertStore::getForUser($users_id, 15),
        ]);
    }

    if ($action === 'reactivate') {
        $checkAjaxCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        AlertStore::reactivate($users_id, $id);

        $respond([
            'success' => 1,
            'data' => AlertStore::getForUser($users_id, 15),
        ]);
    }

    $respond([
        'success' => 0,
        'error' => __('Unsupported action', 'inappnotification'),
    ], 400);
} catch (SessionExpiredException $e) {
    $respond([
        'success' => 0,
        'error' => __('Session expired'),
    ], 401);
} catch (AccessDeniedHttpException $e) {
    $message = method_exists($e, 'getMessageToDisplay')
        ? (string) $e->getMessageToDisplay()
        : '';

    $respond([
        'success' => 0,
        'error' => $message !== '' ? $message : __('The action you have requested is not allowed.'),
    ], 403);
} catch (Throwable $e) {
    $respond([
        'success' => 0,
        'error' => __('Unexpected error', 'inappnotification'),
    ], 500);
}
