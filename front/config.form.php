<?php

use GlpiPlugin\Glpimsteams\Api\CurlHttpClient;
use GlpiPlugin\Glpimsteams\Api\TeamsAccessTokenProvider;
use GlpiPlugin\Glpimsteams\Api\TeamsBotClient;
use GlpiPlugin\Glpimsteams\Service\ConfigurationService;
use GlpiPlugin\Glpimsteams\Service\HealthService;
use GlpiPlugin\Glpimsteams\Service\LoggingService;
use GlpiPlugin\Glpimsteams\Service\TeamsRouteService;

include '../../../inc/includes.php';

// The configuration page uses GLPI's standard configuration right. CSRF is
// validated by GLPI 11's central CheckCsrfListener for POST requests.
Session::checkRight(Config::$rightname, UPDATE);

$service = new ConfigurationService();
$httpClient = new CurlHttpClient();
$tokenProvider = new TeamsAccessTokenProvider($service, $httpClient);
$health = new HealthService(
    $service,
    $tokenProvider,
    new TeamsBotClient($tokenProvider, $httpClient),
    new TeamsRouteService($service),
    new LoggingService()
);

$redirect = static function (): never {
    Html::back();
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update'])) {
        $service->save($_POST);
        Session::addMessageAfterRedirect(__('Configuration updated.'));
        $redirect();
    }

    if (isset($_POST['test_connection'])) {
        try {
            $health->testConnection();
            Session::addMessageAfterRedirect(__('Teams connection succeeded.'), false, INFO);
        } catch (Throwable $exception) {
            Session::addMessageAfterRedirect($health->userMessage($exception), false, ERROR);
        }
        $redirect();
    }

    if (isset($_POST['test_message'])) {
        try {
            $health->sendTestMessage();
            Session::addMessageAfterRedirect(__('Test message sent to Teams.'), false, INFO);
        } catch (Throwable $exception) {
            Session::addMessageAfterRedirect($health->userMessage($exception), false, ERROR);
        }
        $redirect();
    }
}

$values = $service->getMasked();
$configured = $values['tenant_id'] !== ''
    && $values['bot_app_id'] !== ''
    && $values['bot_app_secret'] === '********';

try {
    $snapshot = $health->snapshot();
} catch (Throwable $exception) {
    (new LoggingService())->error('Unable to load Teams administration snapshot', [
        'error_type' => get_class($exception),
    ]);
    $snapshot = [
        'configured' => false,
        'outbox' => [
            'pending' => 0,
            'processing' => 0,
            'sent' => 0,
            'failed' => 0,
        ],
        'last_log_level' => 'ERROR',
        'last_log_message' => 'Snapshot unavailable',
        'last_log_date' => date('Y-m-d H:i:s'),
    ];
}

$esc = static fn (mixed $value): string => htmlescape((string) $value);
$value = static function (string $field) use ($values, $esc): string {
    return $esc($values[$field] ?? '');
};
$secretPlaceholder = static function (string $field) use ($values, $esc): string {
    if (($values[$field] ?? '') !== '********') {
        return '';
    }

    return ' placeholder="' . $esc(__('Configured; leave blank to keep')) . '"';
};

$textField = static function (string $field, string $label, array $options = []) use ($value, $esc, $secretPlaceholder): string {
    $isSecret = $options['secret'] ?? false;
    $type = $isSecret ? 'password' : ($options['type'] ?? 'text');
    $helpId = $field . '-help';
    $help = isset($options['help'])
        ? '<div id="' . $esc($helpId) . '" class="form-hint mt-2">' . $esc($options['help']) . '</div>'
        : '';
    $isConfiguredSecret = $isSecret && $value($field) === '********';
    $isRequired = !empty($options['required']) && !$isConfiguredSecret;
    $required = $isRequired ? ' required' : '';
    $requiredMarker = $isRequired ? '<span class="text-danger ms-1" aria-hidden="true">*</span>' : '';
    $autocomplete = $isSecret ? 'new-password' : 'off';
    $placeholder = $isSecret ? $secretPlaceholder($field) : '';
    $currentValue = $value($field);
    if ($isSecret && $currentValue === '********') {
        $currentValue = '';
    }
    $describedBy = isset($options['help']) ? ' aria-describedby="' . $esc($helpId) . '"' : '';
    $columnClass = !empty($options['full']) ? 'col-12' : 'col-12 col-md-6';

    return '<div class="' . $columnClass . '">'
        . '<label class="form-label fw-semibold" for="' . $esc($field) . '">' . $esc($label) . $requiredMarker . '</label>'
        . '<input class="form-control" type="' . $esc($type) . '" id="' . $esc($field) . '" name="' . $esc($field) . '" value="' . $currentValue . '" autocomplete="' . $esc($autocomplete) . '"' . $describedBy . $placeholder . $required . '>'
        . $help
        . '</div>';
};

$checkbox = static function (string $field, string $label, string $description = '') use ($values, $esc): string {
    $checked = !empty($values[$field]) ? ' checked' : '';
    $help = $description !== '' ? '<div class="form-hint mt-1 ms-4">' . $esc($description) . '</div>' : '';

    return '<div class="col-12 col-md-6">'
        . '<div class="border rounded-2 p-3 h-100">'
        . '<div class="form-check">'
        . '<input class="form-check-input" type="checkbox" id="' . $esc($field) . '" name="' . $esc($field) . '" value="1"' . $checked . '>'
        . '<label class="form-check-label fw-semibold" for="' . $esc($field) . '">' . $esc($label) . '</label>'
        . '</div>'
        . $help
        . '</div></div>'
        . '</div>';
};

$section = static function (string $title, string $description = '', string $icon = 'ti ti-settings') use ($esc): string {
    return '<div class="card mb-4">'
        . '<div class="card-header py-3">'
        . '<div class="d-flex align-items-start gap-3">'
        . '<span class="avatar avatar-sm bg-primary-lt text-primary flex-shrink-0"><i class="' . $esc($icon) . '" aria-hidden="true"></i></span>'
        . '<div><h2 class="h3 mb-1">' . $esc($title) . '</h2>'
        . ($description !== '' ? '<div class="text-muted">' . $esc($description) . '</div>' : '')
        . '</div></div>'
        . '</div>'
        . '<div class="card-body"><div class="row g-3">';
};

Html::header(
    __('GLPI Microsoft Teams Integration'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins',
    'glpimsteams'
);

echo '<div class="container-fluid px-0 px-md-2">';
echo '<div class="row justify-content-center">';
echo '<div class="col-12 col-xxl-11">';
echo '<form method="post" action="' . $esc($_SERVER['PHP_SELF']) . '" class="needs-validation">';
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo '<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">';
echo '<div class="d-flex align-items-start gap-3">';
echo '<span class="avatar avatar-lg bg-primary-lt text-primary"><i class="ti ti-brand-microsoft" aria-hidden="true"></i></span>';
echo '<div><h1 class="h2 mb-1">' . $esc(__('Microsoft Teams Integration')) . '</h1>';
echo '<p class="text-muted mb-2">' . $esc(__('Connect GLPI notifications and user actions to Microsoft Teams.')) . '</p>';
echo '<div class="small text-muted"><i class="ti ti-shield-check me-1" aria-hidden="true"></i>' . $esc(__('Managed from the GLPI plugin configuration area.')) . '</div></div>';
echo '</div>';
echo '<span class="badge ' . ($configured ? 'bg-success-lt text-success' : 'bg-secondary-lt text-secondary') . ' px-3 py-2">'
    . '<i class="ti ' . ($configured ? 'ti-circle-check' : 'ti-alert-circle') . ' me-1" aria-hidden="true"></i>'
    . $esc($configured ? __('Configured') : __('Not configured')) . '</span>';
echo '</div>';

echo '<div class="alert alert-info d-flex align-items-start gap-2 mb-4" role="status">';
echo '<i class="ti ti-info-circle fs-2" aria-hidden="true"></i><div>';
echo $esc(__('This page uses the GLPI configuration permission. The user profile must have Configuration > Update to save changes.'));
echo '</div></div>';

echo '<div class="row g-4 align-items-start">';
echo '<div class="col-12 col-xl-8">';

echo $section(__('Microsoft Entra ID and bot'), __('Credentials used to authenticate the integration with Microsoft Teams.'), 'ti ti-shield-lock');
echo $textField('tenant_id', __('Tenant ID'), ['required' => true, 'help' => __('The Microsoft Entra directory identifier for your organization.')]);
echo $textField('client_id', __('Client ID'));
echo $textField('client_secret', __('Client Secret'), ['secret' => true, 'help' => __('Leave blank to keep the stored secret.')]);
echo $textField('bot_app_id', __('Bot/Application ID'), ['required' => true]);
echo $textField('bot_app_secret', __('Bot/Application Secret'), ['secret' => true, 'required' => true, 'help' => __('Leave blank to keep the stored secret.')]);
echo $textField('teams_app_id', __('Teams App ID'));
echo '</div></div>';

echo $section(__('Message routing'), __('Default destination used when no specific GLPI route is configured.'), 'ti ti-route');
echo $textField('default_team_id', __('Default Team ID'));
echo $textField('default_channel_id', __('Default Channel ID'));
echo $textField('default_conversation_id', __('Default Conversation ID'));
echo $textField('default_service_url', __('Default Bot Service URL'), ['type' => 'url', 'full' => true, 'help' => __('Use the service URL provided by Bot Framework for your Teams channel.')]);
echo $textField('webhook_url', __('Webhook URL'), ['type' => 'url', 'full' => true]);
echo $textField('base_url', __('GLPI Base URL'), ['type' => 'url', 'full' => true, 'help' => __('The public HTTPS address used by OAuth callbacks and Teams links.')]);
echo '</div></div>';

echo $section(__('GLPI OAuth'), __('Settings used when a Teams user links their GLPI identity.'), 'ti ti-key');
echo $textField('glpi_redirect_uri', __('GLPI OAuth Redirect URI'), ['type' => 'url', 'full' => true, 'help' => __('Register this exact HTTPS URL in the GLPI OAuth client.')]);
echo $textField('glpi_api_client_id', __('GLPI OAuth Client ID'));
echo $textField('glpi_api_client_secret', __('GLPI OAuth Client Secret'), ['secret' => true, 'help' => __('Leave blank to keep the stored secret.')]);
echo $textField('glpi_oauth_scope', __('GLPI OAuth Scope'));
echo '</div></div>';

echo $section(__('Notifications'), __('Choose which GLPI events should be sent to Microsoft Teams.'), 'ti ti-bell');
echo $checkbox('enabled', __('Integration enabled'), __('Enable provider integration.'));
echo $checkbox('notify_new_ticket', __('New ticket'));
echo $checkbox('notify_ticket_update', __('Ticket update'));
echo $checkbox('notify_followup', __('New follow-up'));
echo $checkbox('notify_status', __('Status change'));
echo $checkbox('notify_priority', __('Priority change'));
echo $checkbox('notify_assignment', __('Assignment change'));
echo $checkbox('notify_solution', __('Solution'));
echo $checkbox('notify_closure', __('Closure'));
echo '</div></div>';

echo '</div>';

echo '<div class="col-12 col-xl-4">';
echo '<div class="card mb-4">';
echo '<div class="card-header py-3"><div class="d-flex align-items-center gap-2">'
    . '<span class="avatar avatar-sm bg-azure-lt text-azure"><i class="ti ti-activity" aria-hidden="true"></i></span>'
    . '<h2 class="h3 mb-0">' . $esc(__('Integration status')) . '</h2></div></div>';
echo '<div class="card-body">';
echo '<div class="d-flex align-items-center gap-2 mb-3">'
    . '<span class="status-dot ' . ($configured ? 'bg-success' : 'bg-secondary') . '"></span>'
    . '<span class="fw-semibold">' . $esc($configured ? __('Ready for connection tests') : __('Configuration incomplete')) . '</span>'
    . '</div>';
echo '<div class="row g-2">';
foreach ([
    __('Pending') => $snapshot['outbox']['pending'],
    __('Processing') => $snapshot['outbox']['processing'],
    __('Sent') => $snapshot['outbox']['sent'],
    __('Failed') => $snapshot['outbox']['failed'],
] as $label => $count) {
    echo '<div class="col-6"><div class="bg-body-secondary rounded-2 p-3 h-100">'
        . '<div class="text-muted small">' . $esc($label) . '</div>'
        . '<div class="fs-2 fw-bold lh-1 mt-1">' . $esc($count) . '</div>'
        . '</div></div>';
}
echo '</div>';
echo '<div class="border-top mt-3 pt-3 small">'
    . '<div class="text-muted mb-1">' . $esc(__('Latest activity')) . '</div>'
    . '<div class="fw-semibold">' . $esc($snapshot['last_log_level']) . '</div>'
    . '<div class="text-muted">' . $esc($snapshot['last_log_message']) . '</div>'
    . '<div class="text-muted mt-1">' . $esc($snapshot['last_log_date']) . '</div>'
    . '</div>';
echo '</div></div>';

echo '<div class="card mb-4">';
echo '<div class="card-header py-3"><div class="d-flex align-items-center gap-2">'
    . '<span class="avatar avatar-sm bg-yellow-lt text-yellow"><i class="ti ti-list-check" aria-hidden="true"></i></span>'
    . '<h2 class="h3 mb-0">' . $esc(__('Before you save')) . '</h2></div></div>';
echo '<div class="card-body"><ul class="list-unstyled mb-0">';
foreach ([
    __('Verify the app and bot identifiers.'),
    __('Keep secrets blank when you are not rotating them.'),
    __('Use HTTPS for public URLs and OAuth callbacks.'),
] as $tip) {
    echo '<li class="d-flex align-items-start gap-2 mb-3">'
        . '<i class="ti ti-check text-success mt-1" aria-hidden="true"></i>'
        . '<span class="small">' . $esc($tip) . '</span></li>';
}
echo '</ul></div></div>';
echo '</div></div>';

echo '<div class="card mb-3"><div class="card-body">';
echo '<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">';
echo '<div class="d-flex flex-wrap gap-2">';
echo Html::submit(__('Save configuration'), ['name' => 'update', 'class' => 'btn btn-primary']);
echo Html::submit(__('Test connection'), ['name' => 'test_connection', 'class' => 'btn btn-outline-secondary']);
echo Html::submit(__('Send test message'), ['name' => 'test_message', 'class' => 'btn btn-outline-secondary']);
echo '</div>';
echo '<span class="small text-muted"><i class="ti ti-lock me-1" aria-hidden="true"></i>' . $esc(__('Secrets are kept when their fields are left blank.')) . '</span>';
echo '</div></div></div>';

Html::closeForm();
echo '</div></div></div>';

Html::footer();
