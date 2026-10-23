<?php

use Glpi\Application\Environment;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Inappnotification\Config\Settings;
use GlpiPlugin\Inappnotification\Dev\SampleNotifications;

if (!class_exists(SampleNotifications::class)) {
    require_once __DIR__ . '/../src/Dev/SampleNotifications.php';
}

Session::checkLoginUser();

// Developer-only: requires the GLPI config right AND either the `dev_test_panel` switch
// (Setup > Plugins > InAppNotification > Configuration) or a development GLPI environment.
$settings = Settings::get();
$switch_on = (int) ($settings['dev_test_panel'] ?? 0) === 1;
$dev_env = Environment::get()->shouldEnableExtraDevAndDebugTools();
if (!Session::haveRight('config', UPDATE) || (!$switch_on && !$dev_env)) {
    throw new AccessDeniedHttpException();
}

$users_id = (int) Session::getLoginUserID();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ((string) ($_POST['action'] ?? '')) {
        case 'samples':
            $n = SampleNotifications::triggerSamples($users_id);
            Session::addMessageAfterRedirect(
                sprintf(__('%d sample notification(s) generated for your account.', 'inappnotification'), $n),
                true,
                INFO
            );
            break;
        case 'states':
            SampleNotifications::triggerStates($users_id);
            Session::addMessageAfterRedirect(
                __('State examples generated: unread, read, snoozed, suppressed and an expired snooze.', 'inappnotification'),
                true,
                INFO
            );
            break;
        case 'upcoming':
            SampleNotifications::seedUpcoming($users_id);
            Session::addMessageAfterRedirect(
                __('Upcoming demo seeded. Compare the personal and all-users upcoming pages.', 'inappnotification'),
                true,
                INFO
            );
            break;
        case 'clear':
            SampleNotifications::clear($users_id);
            Session::addMessageAfterRedirect(__('Test notifications cleared.', 'inappnotification'), true, INFO);
            break;
    }
    // Not $_SERVER['PHP_SELF']: behind GLPI's front controller that is /index.php.
    Html::redirect((string) ($GLOBALS['CFG_GLPI']['root_doc'] ?? '') . '/plugins/inappnotification/front/dev.php');
}

$root = (string) ($GLOBALS['CFG_GLPI']['root_doc'] ?? '');
$base = $root . '/plugins/inappnotification/front';

$button = static function (string $action, string $label, string $help, string $btn_class): string {
    return "<form method='post' class='inappnotification-dev-action'>"
        . Html::hidden('action', ['value' => $action])
        . "<button type='submit' class='btn $btn_class'>" . htmlescape($label) . "</button>"
        . "<div class='text-muted small mt-1'>" . htmlescape($help) . "</div>"
        . "</form>";
};

Html::header(__('InAppNotification dev panel', 'inappnotification'), '', 'tools', 'inappnotification');

echo "<div class='alert alert-warning'><i class='ti ti-flask me-1'></i>"
    . htmlescape(__('Developer test panel. It generates sample notifications for your own account and seeds demo "upcoming" rows. For development use only.', 'inappnotification'))
    . "</div>";

echo "<div class='card'><div class='card-body d-flex flex-column gap-3'>";

echo "<div>";
echo "<h4 class='mb-1'>" . htmlescape(__('In-app notifications', 'inappnotification')) . "</h4>";
echo "<div class='d-flex flex-wrap gap-4'>";
echo $button('samples', __('Generate one per source type', 'inappnotification'), __('Ticket, Search, Project, Reminder, SLA, Scheduled task, Other — via the real capture path.', 'inappnotification'), 'btn-primary');
echo $button('states', __('Generate notification states', 'inappnotification'), __('Unread, read, snoozed, suppressed and an expired snooze (re-notifies on the next poll).', 'inappnotification'), 'btn-primary');
echo "</div></div>";

echo "<hr class='my-1'>";

echo "<div>";
echo "<h4 class='mb-1'>" . htmlescape(__('Upcoming notifications (recipient scope demo)', 'inappnotification')) . "</h4>";
echo "<div class='text-muted small mb-2'>"
    . htmlescape(__('Seeds three rows: one for you, one for another user, one with unresolved recipients. Your personal page shows only your row; the all-users page shows all three. The hourly cron replaces these on its next run.', 'inappnotification'))
    . "</div>";
echo $button('upcoming', __('Seed upcoming demo', 'inappnotification'), '', 'btn-primary');
echo "</div>";

echo "<hr class='my-1'>";

echo "<div>";
echo "<h4 class='mb-1'>" . htmlescape(__('Cleanup', 'inappnotification')) . "</h4>";
echo $button('clear', __('Clear all test notifications', 'inappnotification'), __('Removes every [TEST] notification and seeded upcoming row.', 'inappnotification'), 'btn-outline-danger');
echo "</div>";

echo "<hr class='my-1'>";

echo "<div><h4 class='mb-1'>" . htmlescape(__('Where to look', 'inappnotification')) . "</h4>";
echo "<div class='d-flex flex-wrap gap-2'>";
echo "<a class='btn btn-outline-secondary' href='" . htmlescape($root . '/front/central.php') . "'><i class='ti ti-bell me-1'></i>" . htmlescape(__('Central (bell)', 'inappnotification')) . "</a>";
echo "<a class='btn btn-outline-secondary' href='" . htmlescape($base . '/center.php') . "'>" . htmlescape(__('Notification list', 'inappnotification')) . "</a>";
echo "<a class='btn btn-outline-secondary' href='" . htmlescape($base . '/muted.php') . "'>" . htmlescape(__('Muted', 'inappnotification')) . "</a>";
echo "<a class='btn btn-outline-secondary' href='" . htmlescape($base . '/scheduled.php') . "'>" . htmlescape(__('Upcoming (yours)', 'inappnotification')) . "</a>";
echo "<a class='btn btn-outline-secondary' href='" . htmlescape($base . '/scheduled_all.php') . "'>" . htmlescape(__('Upcoming (all users)', 'inappnotification')) . "</a>";
echo "</div></div>";

echo "</div></div>";

// Send the actions in the background: a page reload would discard the user's click, and browsers
// that require a gesture per page (e.g. Firefox) would then block the bell's chime for the
// generated notifications until the next click.
echo Html::scriptBlock(<<<'JS'
document.querySelectorAll('form.inappnotification-dev-action').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('button');
        button.disabled = true;
        const headers = { 'X-Requested-With': 'XMLHttpRequest' };
        const token = typeof window.getAjaxCsrfToken === 'function' ? window.getAjaxCsrfToken() : '';
        if (token) {
            headers['X-Glpi-Csrf-Token'] = token;
        }
        try {
            await fetch(window.location.href, { method: 'POST', body: new FormData(form), headers, redirect: 'manual' });
        } finally {
            button.disabled = false;
            if (typeof window.displayAjaxMessageAfterRedirect === 'function') {
                window.displayAjaxMessageAfterRedirect();
            }
        }
    });
});
JS);

Html::footer();
