<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthSettings;
use App\JsonRequestBody;
use App\JsonResponse;
use App\Mail\MailSettings;
use App\Services\AuthorizationService;
use App\Services\InstanceSettingsService;
use App\Services\MailService;
use App\Services\ProjectScopeResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminSettingsController
{
    use AuthorizesApiAccess;

    public function __construct(
        private readonly PDO $pdo,
        AuthorizationService $authorization,
        ProjectScopeResolver $projectScope,
        private readonly InstanceSettingsService $instanceSettings,
        private readonly AuthSettings $authSettings,
        private readonly array $env,
        private readonly string $dbDriver,
    ) {
        $this->initAuthorization($authorization, $projectScope);
    }

    /** GET /api/admin/settings */
    public function get(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($denied = $this->authorizeManageUsers()) {
            return $denied;
        }

        return JsonResponse::encode($response, [
            'data' => $this->instanceSettings->buildAdminSnapshot($this->env, $this->authSettings, $this->dbDriver),
        ]);
    }

    /** PATCH /api/admin/settings */
    public function patch(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($denied = $this->authorizeManageUsers()) {
            return $denied;
        }

        $body = JsonRequestBody::decode($request);
        if (!is_array($body)) {
            return JsonResponse::error('Invalid JSON body', 400);
        }

        $err = $this->instanceSettings->validatePatch($body);
        if ($err !== null) {
            return JsonResponse::error($err, 400);
        }

        $this->instanceSettings->applyPatch($body);
        $effectiveEnv = $this->instanceSettings->mergeEnv($this->env);

        return JsonResponse::encode($response, [
            'data' => $this->instanceSettings->buildAdminSnapshot($effectiveEnv, $this->authSettings, $this->dbDriver),
        ]);
    }

    /** POST /api/admin/settings/test-mail — body: { "to": "email@example.com" } */
    public function testMail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($denied = $this->authorizeManageUsers()) {
            return $denied;
        }

        $body = JsonRequestBody::decode($request);
        if (!is_array($body)) {
            return JsonResponse::error('Invalid JSON body', 400);
        }
        $to = strtolower(trim((string) ($body['to'] ?? '')));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return JsonResponse::error('A valid recipient email is required.', 400);
        }

        $effectiveEnv = $this->instanceSettings->mergeEnv($this->env);
        $mailSettings = MailSettings::fromEnv($effectiveEnv);
        $mail = new MailService($mailSettings);

        if (!$mailSettings->isEnabled()) {
            return JsonResponse::error(
                'Mail is not enabled. Configure mail in System settings or server environment.',
                400,
            );
        }

        $subject = 'TestTrove mail test';
        $plain = "This is a test message from TestTrove.\n\nIf you received this, outbound mail is working.";
        $ok = $mail->send($to, $subject, $plain);
        if (!$ok) {
            $detail = $mail->getLastError();

            return JsonResponse::error($detail !== '' ? $detail : 'Could not send test email.', 502);
        }

        return JsonResponse::encode($response, [
            'data' => ['sent' => true, 'to' => $to],
        ]);
    }
}
