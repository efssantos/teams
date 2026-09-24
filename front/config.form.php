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
    $help = isset($options['help']) ? '<div class="form-text">' . $esc($options['help']) . '</div>' : '';
    $isConfiguredSecret = $isSecret && $value($field) === '********';
    $required = !empty($options['required']) && !$isConfiguredSecret ? ' required' : '';
    $autocomplete = $isSecret ? 'new-password' : 'off';
    $placeholder = $isSecret ? $secretPlaceholder($field) : '';
    $currentValue = $value($field);
    if ($isSecret && $currentValue === '********') {
        $currentValue = '';
    }

    return '<div class="col-12 col-md-6 mb-3">'
        . '<label class="form-label" for="' . $esc($field) . '">' . $esc($label) . '</label>'
        . '<input class="form-control" type="' . $esc($type) . '" id="' . $esc($field) . '" name="' . $esc($field) . '" value="' . $currentValue . '" autocomplete="' . $esc($autocomplete) . '"' . $placeholder . $required . '>'
        . $help
        . '</div>';
};

$checkbox = static function (string $field, string $label, string $description = '') use ($values, $esc): string {
    $checked = !empty($values[$field]) ? ' checked' : '';
    $help = $description !== '' ? '<div class="form-text">' . $esc($description) . '</div>' : '';

    return '<div class="col-12 col-md-6 mb-3">'
        . '<div class="form-check">'
        . '<input class="form-check-input" type="checkbox" id="' . $esc($field) . '" name="' . $esc($field) . '" value="1"' . $checked . '>'
        . '<label class="form-check-label" for="' . $esc($field) . '">' . $esc($label) . '</label>'
        . '</div>'
        . $help
        . '</div>';
};

$section = static function (string $title, string $description = '') use ($esc): string {
    return '<div class="card mb-3">'
        . '<div class="card-header">'
        . '<h2 class="h4 mb-0">' . $esc($title) . '</h2>'
        . ($description !== '' ? '<div class="text-muted mt-1">' . $esc($description) . '</div>' : '')
        . '</div>'
        . '<div class="card-body"><div class="row">';
};

Html::header(
    __('GLPI Microsoft Teams Integration'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins',
    'glpimsteams'
);

echo '<div class="container-fluid">';
echo '<div class="row justify-content-center">';
echo '<div class="col-12 col-xxl-10">';
echo '<form method="post" action="' . $esc($_SERVER['PHP_SELF']) . '" class="needs-validation">';
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">';
echo '<div><h1 class="h2 mb-1">' . $esc(__('Microsoft Teams Integration')) . '</h1>';
echo '<p class="text-muted mb-0">' . $esc(__('Configure authentication, message routing and notification preferences.')) . '</p></div>';
echo '<span class="badge ' . ($configured ? 'bg-success' : 'bg-secondary') . '">' . $esc($configured ? __('Configured') : __('Not configured')) . '</span>';
echo '</div>';

echo '<div class="alert alert-info" role="status">';
echo '<i class="ti ti-info-circle me-1" aria-hidden="true"></i>';
echo $esc(__('This page uses the GLPI configuration permission. The user profile must have Configuration > Update to save changes.'));
echo '</div>';

echo $section(__('Microsoft Entra ID and bot'), __('Credentials used to authenticate the integration with Microsoft Teams.'));
echo $textField('tenant_id', __('Tenant ID'), ['required' => true]);
echo $textField('client_id', __('Client ID'));
echo $textField('client_secret', __('Client Secret'), ['secret' => true]);
echo $textField('bot_app_id', __('Bot/Application ID'), ['required' => true]);
echo $textField('bot_app_secret', __('Bot/Application Secret'), ['secret' => true, 'required' => true]);
echo $textField('teams_app_id', __('Teams App ID'));
echo '</div></div>';

echo $section(__('Message routing'), __('Default destination used when no specific GLPI route is configured.'));
echo $textField('default_team_id', __('Default Team ID'));
echo $textField('default_channel_id', __('Default Channel ID'));
echo $textField('default_conversation_id', __('Default Conversation ID'));
echo $textField('default_service_url', __('Default Bot Service URL'), ['type' => 'url']);
echo $textField('webhook_url', __('Webhook URL'), ['type' => 'url']);
echo $textField('base_url', __('GLPI Base URL'), ['type' => 'url']);
echo '</div></div>';

echo $section(__('GLPI OAuth'), __('Settings used when a Teams user links their GLPI identity.'));
echo $textField('glpi_redirect_uri', __('GLPI OAuth Redirect URI'), ['type' => 'url']);
echo $textField('glpi_api_client_id', __('GLPI OAuth Client ID'));
echo $textField('glpi_api_client_secret', __('GLPI OAuth Client Secret'), ['secret' => true]);
echo $textField('glpi_oauth_scope', __('GLPI OAuth Scope'));
echo '</div></div>';

echo $section(__('Notifications'), __('Choose which GLPI events should be sent to Microsoft Teams.'));
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

echo '<div class="card mb-3">';
echo '<div class="card-header"><h2 class="h4 mb-0">' . $esc(__('Integration status')) . '</h2></div>';
echo '<div class="card-body"><div class="row g-3">';
foreach ([
    __('Pending') => $snapshot['outbox']['pending'],
    __('Processing') => $snapshot['outbox']['processing'],
    __('Sent') => $snapshot['outbox']['sent'],
    __('Failed') => $snapshot['outbox']['failed'],
] as $label => $count) {
    echo '<div class="col-6 col-lg-3"><div class="border rounded p-3 h-100">'
        . '<div class="text-muted small">' . $esc($label) . '</div>'
        . '<div class="fs-2 fw-bold">' . $esc($count) . '</div>'
        . '</div></div>';
}
echo '</div>';
echo '<div class="mt-3 small text-muted">'
    . $esc(sprintf(__('Last log: %s — %s (%s)'), $snapshot['last_log_level'], $snapshot['last_log_message'], $snapshot['last_log_date']))
    . '</div>';
echo '</div></div>';

echo '<div class="card mb-3"><div class="card-body">';
echo '<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">';
echo '<div class="d-flex flex-wrap gap-2">';
echo Html::submit(__('Save configuration'), ['name' => 'update', 'class' => 'btn btn-primary']);
echo Html::submit(__('Test connection'), ['name' => 'test_connection', 'class' => 'btn btn-outline-secondary']);
echo Html::submit(__('Send test message'), ['name' => 'test_message', 'class' => 'btn btn-outline-secondary']);
echo '</div>';
echo '<span class="small text-muted">' . $esc(__('Secrets are kept when their fields are left blank.')) . '</span>';
echo '</div></div></div>';

Html::closeForm();
echo '</div></div></div>';

Html::footer();
