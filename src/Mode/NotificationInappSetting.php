<?php

use Glpi\Application\View\TemplateRenderer;

class PluginInappnotificationNotificationInappSetting extends NotificationSetting
{
    #[Override]
    public static function getTypeName($nb = 0)
    {
        return __('InAppNotification in-app notifications', 'inappnotification');
    }

    public function getEnableLabel()
    {
        return __('Enable InAppNotification in-app notifications', 'inappnotification');
    }

    public static function getMode()
    {
        return 'inapp';
    }

    public function showFormConfig()
    {
        global $CFG_GLPI;

        if ($CFG_GLPI['notifications_inapp']) {
            $twig_params = [
                'text' => __('InAppNotification is enabled. Use plugin settings to configure filters and sources.', 'inappnotification'),
                'url' => $CFG_GLPI['root_doc'] . '/plugins/inappnotification/front/config.form.php',
                'button' => __('Open InAppNotification settings', 'inappnotification'),
            ];

            // language=Twig
            echo TemplateRenderer::getInstance()->renderFromStringTemplate(<<<TWIG
                <div class="card">
                    <div class="card-body d-flex flex-column gap-3">
                        <p class="mb-0">{{ text }}</p>
                        <div>
                            <a class="btn btn-primary" href="{{ url }}">{{ button }}</a>
                        </div>
                    </div>
                </div>
TWIG, $twig_params);
            return;
        }

        $twig_params = ['message' => __('Notifications are disabled.')];
        // language=Twig
        echo TemplateRenderer::getInstance()->renderFromStringTemplate(<<<TWIG
            <div class="alert alert-warning">
                <a href="{{ path('front/setup.notification.php') }}">{{ message }}</a>
            </div>
TWIG, $twig_params);
    }

    #[Override]
    public static function getIcon()
    {
        return "ti ti-bell-ringing";
    }
}
