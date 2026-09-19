<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MessageSessionController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\SmsNumberController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\ScheduledMessageController;
use App\Http\Controllers\OptOutController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\EmailSmsSenderController;
use App\Http\Controllers\OptEventController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\AutoReplyController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\ConversationMetaController;
use App\Http\Controllers\AgentSelfController;
use App\Http\Controllers\AgentPasswordResetController;
use App\Http\Controllers\TenantPasswordResetController;
use App\Http\Controllers\SuperAdminAuthController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\TenantWebhookController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\LockoutController;

/*
 | API routes — session auth via `web` middleware group
 | ( sanctum SPA / laravel session cookie ). See bootstrap/app.php:
 |   Route::middleware('web')->group(base_path('routes/api.php'));
 */

// Public
Route::post('/login', [AuthController::class, 'login']);
Route::get('/auth/login-options', [AuthController::class, 'loginOptions']);
Route::post('/tenant/login', [AuthController::class, 'tenantLogin']);
Route::post('/webhooks/dynalink', [WebhookController::class, 'dynalink']); // server-to-server

// Authenticated (dynalink session must exist)
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/me', [AuthController::class, 'me']);
Route::post('/refresh', [AuthController::class, 'refresh']);
Route::post('/auth/verify-password', [AuthController::class, 'verifyPassword'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':10,1');

// Agent local auth (Phase 1 roles)
Route::post('/agent/login', [AuthController::class, 'agentLogin']);
Route::post('/agent/forgot/start', [AgentPasswordResetController::class, 'start'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':10,1');
Route::post('/agent/forgot/answer', [AgentPasswordResetController::class, 'answer'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':10,1');
Route::post('/agent/forgot/complete', [AgentPasswordResetController::class, 'complete'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':10,1');
Route::post('/tenant/forgot/start', [TenantPasswordResetController::class, 'start'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':10,1');
Route::post('/tenant/forgot/answer', [TenantPasswordResetController::class, 'answer'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':10,1');
Route::post('/tenant/forgot/complete', [TenantPasswordResetController::class, 'complete'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':10,1');

// Superadmin portal — IP-restricted, separate session (class middleware, no aliases needed).
Route::post('/superadmin/login', [SuperAdminAuthController::class, 'login'])
    ->middleware([\App\Http\Middleware\EnsureSuperAdminIp::class]);
Route::prefix('superadmin')->middleware([\App\Http\Middleware\EnsureSuperAdminIp::class, \App\Http\Middleware\EnsureSuperAdminAuth::class])->group(function () {
    Route::get('/me', [SuperAdminAuthController::class, 'me']);
    Route::post('/logout', [SuperAdminAuthController::class, 'logout']);
    Route::post('/password', [SuperAdminAuthController::class, 'password']);
    Route::get('/tenants', [SuperAdminController::class, 'tenantsIndex']);
    Route::post('/tenants', [SuperAdminController::class, 'tenantsStore']);
    Route::post('/tenants/verify-numbers', [SuperAdminController::class, 'tenantsVerifyNumbers']);
    Route::get('/tenants/{tenant}', [SuperAdminController::class, 'tenantsShow']);
    Route::post('/tenants/{tenant}/numbers', [SuperAdminController::class, 'tenantsNumbers']);
    Route::put('/tenants/{tenant}', [SuperAdminController::class, 'tenantsUpdate']);
    Route::post('/tenants/{tenant}/deactivate', [SuperAdminController::class, 'tenantsDeactivate']);
    Route::post('/tenants/{tenant}/reactivate', [SuperAdminController::class, 'tenantsReactivate']);
    Route::get('/tenants/{tenant}/admins', [SuperAdminController::class, 'adminsIndex']);
    Route::post('/tenants/{tenant}/admins', [SuperAdminController::class, 'adminsStore']);
    Route::put('/tenants/{tenant}/admins/{admin}', [SuperAdminController::class, 'adminsUpdate']);
    Route::post('/tenants/{tenant}/admins/{admin}/password', [SuperAdminController::class, 'adminsPassword']);
    Route::get('/settings', [SuperAdminController::class, 'settingsShow']);
    Route::put('/settings', [SuperAdminController::class, 'settingsUpdate']);
    Route::post('/settings/mail-test', [SuperAdminController::class, 'mailTest'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':10,1');
    Route::get('/audit-logs', [SuperAdminController::class, 'auditIndex']);
    Route::get('/audit-logs/actions', [SuperAdminController::class, 'auditActions']);
    Route::get('/allowed-ips', [SuperAdminController::class, 'ipsIndex']);
    Route::post('/allowed-ips', [SuperAdminController::class, 'ipsStore']);
    Route::delete('/allowed-ips/{ip}', [SuperAdminController::class, 'ipsDestroy']);
    Route::get('/webhook-ips', [SuperAdminController::class, 'webhookIpsIndex']);
    Route::post('/webhook-ips', [SuperAdminController::class, 'webhookIpsStore']);
    Route::delete('/webhook-ips/{ip}', [SuperAdminController::class, 'webhookIpsDestroy']);
    Route::get('/tenants/{tenant}/delete-preview', [SuperAdminController::class, 'tenantsDeletePreview']);
    Route::delete('/tenants/{tenant}', [SuperAdminController::class, 'tenantsDestroy']);
    Route::get('/tenants/{tenant}/admins/{admin}/delete-preview', [SuperAdminController::class, 'adminsDeletePreview']);
    Route::delete('/tenants/{tenant}/admins/{admin}', [SuperAdminController::class, 'adminsDestroy']);
    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::get('/reports/trend', [ReportController::class, 'trend']);
    Route::get('/reports/by-agent', [ReportController::class, 'byAgent']);
    Route::get('/reports/by-number', [ReportController::class, 'byNumber']);
    Route::get('/reports/detail', [ReportController::class, 'detail']);
    Route::get('/reports/tenants', [ReportController::class, 'tenants']);
});
Route::get('/agent/profile', [AgentSelfController::class, 'show']);
Route::patch('/agent/profile', [AgentSelfController::class, 'update']);
Route::post('/agent/password', [AgentSelfController::class, 'password']);
Route::post('/agent/ping', [AgentSelfController::class, 'ping']);
Route::post('/agents/{agent}/password', [AgentController::class, 'setPassword']);

Route::get('/sms-numbers', [SmsNumberController::class, 'index']);
Route::get('/subscriptions', [SubscriptionController::class, 'index']);
Route::post('/subscriptions/ensure', [SubscriptionController::class, 'ensure'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':20,1');
Route::delete('/subscriptions/{model}', [SubscriptionController::class, 'destroy'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':20,1');

Route::get('/reports/summary', [ReportController::class, 'summary']);
Route::get('/reports/trend', [ReportController::class, 'trend']);
Route::get('/reports/by-agent', [ReportController::class, 'byAgent']);
Route::get('/reports/by-number', [ReportController::class, 'byNumber']);
Route::get('/reports/detail', [ReportController::class, 'detail']);

Route::get('/integrations', [IntegrationController::class, 'index']);
Route::put('/integrations/revio', [IntegrationController::class, 'saveRevio'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':10,1');
Route::post('/integrations/revio/test', [IntegrationController::class, 'testRevio'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':10,1');
Route::delete('/integrations/revio', [IntegrationController::class, 'destroyRevio']);
Route::put('/integrations/revio/numbers', [IntegrationController::class, 'numbersRevio']);
Route::put('/integrations/revio/spiels', [IntegrationController::class, 'spielsRevio']);
Route::put('/integrations/revio/settings', [IntegrationController::class, 'settingsRevio']);

Route::get('/sessions', [MessageSessionController::class, 'index']);
Route::get('/sessions/{id}/messages', [MessageSessionController::class, 'messages']);
Route::post('/sessions/{id}/messages', [MessageSessionController::class, 'send'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':30,1');
Route::post('/sessions/{id}/read', [MessageSessionController::class, 'read']);
Route::post('/sessions/{id}/unread', [MessageSessionController::class, 'unread']);

Route::post('/messages', [MessageController::class, 'store'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':30,1');
Route::post('/messages/bulk', [MessageController::class, 'bulk'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':5,1'); // single call, array destination

Route::get('/contacts/template', [ContactController::class, 'template']);
Route::post('/contacts/import', [ContactController::class, 'import'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':3,1');
Route::apiResource('/contacts', ContactController::class)->except(['create', 'edit']);

Route::apiResource('/groups', GroupController::class);
Route::apiResource('/companies', CompanyController::class);
Route::get('/agents/directory', [AgentController::class, 'directory']);
Route::get('/agents/{agent}/delete-preview', [AgentController::class, 'deletePreview']);
Route::apiResource('/agents', AgentController::class)->except(['create', 'edit']);
Route::get('/conversation-meta', [ConversationMetaController::class, 'index']);
Route::put('/conversation-meta/{sessionId}', [ConversationMetaController::class, 'upsert']);
Route::get('/audit-logs/actions', [AuditLogController::class, 'actions']);
Route::get('/audit-logs', [AuditLogController::class, 'index']);
Route::get('/lockouts', [LockoutController::class, 'index']);
Route::post('/lockouts/users/unblock', [LockoutController::class, 'unblockUser']);
Route::post('/lockouts/ips/unblock', [LockoutController::class, 'unblockIp']);

Route::post('/templates/{template}/resolve', [TemplateController::class, 'resolve']);
Route::apiResource('/templates', TemplateController::class)->except(['create', 'edit']);
Route::get('/opt-outs', [OptOutController::class, 'index']);
Route::post('/opt-outs', [OptOutController::class, 'store']);
Route::delete('/opt-outs/{phone}', [OptOutController::class, 'destroy']);
Route::get('/company-settings', [CompanySettingsController::class, 'show']);
Route::put('/company-settings', [CompanySettingsController::class, 'update']);
Route::get('/email-sms-senders', [EmailSmsSenderController::class, 'index']);
Route::post('/email-sms-senders', [EmailSmsSenderController::class, 'store']);
Route::put('/email-sms-senders/{id}', [EmailSmsSenderController::class, 'update']);
Route::delete('/email-sms-senders/{id}', [EmailSmsSenderController::class, 'destroy']);
Route::get('/opt-events', [OptEventController::class, 'index']);
Route::post('/auto-replies/{autoReply}/unlock', [AutoReplyController::class, 'unlock']);
Route::post('/auto-replies/{autoReply}/reset', [AutoReplyController::class, 'reset']);

Route::post('/scheduled/{scheduled}/cancel', [ScheduledMessageController::class, 'cancel']);
Route::post('/scheduled/{scheduled}/retry', [ScheduledMessageController::class, 'retry'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':10,1');
Route::post('/scheduled/{scheduled}/send-now', [ScheduledMessageController::class, 'sendNow'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':10,1');
Route::get('/ops/health', [ScheduledMessageController::class, 'opsHealth']);
Route::apiResource('/scheduled', ScheduledMessageController::class)->except(['create', 'edit'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':60,1');

Route::get('/auto-reply-logs', [AutoReplyController::class, 'logs']);
Route::get('/webhook-events', [AutoReplyController::class, 'events']);
Route::post('/auto-replies/test', [AutoReplyController::class, 'test']);
Route::post('/auto-replies/{autoReply}/fire', [AutoReplyController::class, 'fire']);
// NOTE: explicit parameter name — Laravel would singularize to {auto_reply},
// which would NOT bind to the $autoReply arguments (update/delete/show/fire).
Route::apiResource('/auto-replies', AutoReplyController::class, ['parameters' => ['auto-replies' => 'autoReply']])->except(['create', 'edit']);

// ---- Tenant API tokens + outbound webhooks + push (portal, session auth) ----
Route::get('/api-tokens', [ApiTokenController::class, 'index']);
Route::post('/api-tokens', [ApiTokenController::class, 'store']);
Route::delete('/api-tokens/{id}', [ApiTokenController::class, 'destroy']);
Route::get('/tenant-webhooks', [TenantWebhookController::class, 'index']);
Route::post('/tenant-webhooks', [TenantWebhookController::class, 'store']);
Route::put('/tenant-webhooks/{webhook}', [TenantWebhookController::class, 'update']);
Route::delete('/tenant-webhooks/{webhook}', [TenantWebhookController::class, 'destroy']);
Route::post('/tenant-webhooks/{webhook}/test', [TenantWebhookController::class, 'test']);
Route::get('/tenant-webhooks/{webhook}/deliveries', [TenantWebhookController::class, 'deliveries']);
Route::get('/push/vapid-key', [PushSubscriptionController::class, 'vapidKey']);
Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store']);
Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy']);
Route::get('/scheduled/{scheduled}/report', [ScheduledMessageController::class, 'report']);

// ---- v1 tenant API (Sanctum bearer tokens; CSRF-exempt — see docs/TENANT_API.md) ----
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::post('/messages', [\App\Http\Controllers\Api\V1\MessageController::class, 'send'])->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':60,1');
    Route::get('/scheduled', [\App\Http\Controllers\Api\V1\ScheduledController::class, 'index']);
    Route::get('/scheduled/{scheduled}', [\App\Http\Controllers\Api\V1\ScheduledController::class, 'show']);
});
