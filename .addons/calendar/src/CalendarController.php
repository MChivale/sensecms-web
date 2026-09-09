<?php

declare(strict_types=1);

namespace SenseCMS\Calendar;

use App\Core\ExtensionContext;
use RuntimeException;
use Throwable;

final class CalendarController
{
    private CalendarRepository $calendar;
    private CalendarIntegrationManager $integrations;
    private CalendarI18n $i18n;

    public function __construct(private readonly ExtensionContext $context, private readonly string $root)
    {
        $this->i18n = new CalendarI18n($root, (string) ($_GET['lang'] ?? ''), (string) ($context->config['default_locale'] ?? 'en'));
        $this->calendar = new CalendarRepository($context->db, $context->auth->id() ?? 0, $context->access->allows('system.owner'), [$this->i18n, 't']);
        $this->integrations = new CalendarIntegrationManager($context->db, $context->root, (string) ($context->config['secrets_key'] ?? ''));
    }

    public function page(): never
    {
        $this->context->access->assert('calendar.view');
        $from = (new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone('UTC')))->modify('-14 days')->format('Y-m-d H:i:s');
        $to = (new \DateTimeImmutable('first day of next month 00:00:00', new \DateTimeZone('UTC')))->modify('+21 days')->format('Y-m-d H:i:s');
        $scope = $this->context->access->facilityIds();
        $boot = [
            'events' => $this->calendar->events($from, $to, $scope),
            'options' => $this->options($scope),
            'notifications' => $this->calendar->notifications($this->context->auth->id() ?? 0),
            'permissions' => [
                'manage' => $this->context->access->allows('calendar.manage'),
                'override' => $this->context->access->allows('calendar.override-conflicts'),
                'resources' => $this->context->access->allows('calendar.resources.manage'),
                'settings' => $this->context->access->allows('calendar.settings.manage'),
            ],
            'csrf' => $this->context->auth->csrf(),
            'range' => ['from' => $from, 'to' => $to],
            'integrations' => $this->context->access->allows('calendar.settings.manage') ? $this->integrations->catalog(true) : [],
            'locale' => $this->i18n->locale,
            'locales' => CalendarI18n::LOCALES,
            'copy' => $this->i18n->all(),
            'deliveries' => $this->context->access->allows('calendar.settings.manage') ? $this->calendar->deliveryStatus() : [],
        ];
        $this->context->dashboard->extensionPage($this->i18n->t('hero.title'), $this->root . '/views/calendar.php', [
            'calendarBoot' => $boot,
            'calendarCopy' => $this->i18n->all(),
            'calendarLocale' => $this->i18n->locale,
            'extensionActive' => '/calendar',
            'extensionStyles' => ['/extension-assets/addon/calendar/calendar.css?v=1.1.0'],
            'extensionScripts' => ['/extension-assets/addon/calendar/calendar.js?v=1.1.0'],
        ]);
    }

    public function events(): never
    {
        $this->context->access->assert('calendar.view');
        try {
            $events = $this->calendar->events((string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''), $this->context->access->facilityIds(), $_GET);
            $this->json(true, $this->i18n->t('messages.events_loaded'), ['events' => $events]);
        } catch (Throwable $error) { $this->failure($error); }
    }

    public function event(int $id): never
    {
        $this->context->access->assert('calendar.view');
        $event = $this->calendar->event($id, $this->context->access->facilityIds());
        if (!$event) $this->json(false, $this->i18n->t('errors.The calendar event was not found.'), null, 404);
        $this->json(true, $this->i18n->t('messages.event_loaded'), ['event' => $event]);
    }

    public function save(): never
    {
        $this->context->access->assert('calendar.manage');
        $payload = $this->payload();
        $this->csrf($payload);
        try {
            $available = $this->options($this->context->access->facilityIds())['channels'];
            foreach ((array) ($payload['channels'] ?? []) as $channel) if (!in_array($channel, $available, true)) throw new RuntimeException('A selected notification channel is not configured.');
            $saved = $this->calendar->save($payload, $this->context->auth->id() ?? 0, $this->context->access->facilityIds(), $this->context->access->allows('calendar.override-conflicts'));
            $message = !empty($saved['series_updated']) ? $this->i18n->t('messages.series_updated', ['count'=>$saved['occurrences']]) : ($saved['occurrences'] > 1 ? $this->i18n->t('messages.recurring_created', ['count'=>$saved['occurrences']]) : $this->i18n->t('messages.event_saved'));
            $this->json(true, $message, $saved);
        } catch (CalendarConflictException $error) {
            $this->json(false, $error->getMessage(), ['conflicts' => $error->conflicts], 409);
        } catch (Throwable $error) { $this->failure($error); }
    }

    public function conflicts(): never
    {
        $this->context->access->assert('calendar.view');
        $payload = $this->payload();
        $this->csrf($payload);
        try { $this->json(true, $this->i18n->t('messages.conflicts_checked'), ['conflicts' => $this->calendar->conflictsForInput($payload, $this->context->access->facilityIds())]); }
        catch (Throwable $error) { $this->failure($error); }
    }

    public function cancel(int $id): never
    {
        $this->context->access->assert('calendar.manage');
        $payload = $this->payload(); $this->csrf($payload);
        try { $count=$this->calendar->cancel($id, $this->context->auth->id() ?? 0, $this->context->access->facilityIds(), (string)($payload['cancel_scope']??'single')); $this->json(true, $count>1?$this->i18n->t('messages.events_cancelled',['count'=>$count]):$this->i18n->t('messages.event_cancelled'), ['cancelled'=>$count]); }
        catch (Throwable $error) { $this->failure($error); }
    }

    public function resource(): never
    {
        $this->context->access->assert('calendar.resources.manage');
        $payload = $this->payload(); $this->csrf($payload);
        try { $this->json(true, $this->i18n->t('messages.resource_created'), ['resource' => $this->calendar->saveResource($payload, $this->context->auth->id() ?? 0, $this->context->access->facilityIds())]); }
        catch (Throwable $error) { $this->failure($error); }
    }

    public function saveCategory(): never
    {
        $this->context->access->assert('calendar.settings.manage');
        $payload = $this->payload(); $this->csrf($payload);
        try {
            $category = $this->calendar->saveCategory($payload, $this->context->auth->id() ?? 0);
            $this->json(true, $this->i18n->t('categories.saved'), ['category'=>$category,'categories'=>$this->calendar->categories()]);
        } catch (Throwable $error) { $this->failure($error); }
    }

    public function notifications(): never
    {
        $this->context->access->assert('calendar.view');
        $this->json(true, $this->i18n->t('messages.notifications_loaded'), ['notifications' => $this->calendar->notifications($this->context->auth->id() ?? 0)]);
    }

    public function readNotifications(): never
    {
        $this->context->access->assert('calendar.view');
        $payload = $this->payload(); $this->csrf($payload);
        $count = $this->calendar->readNotifications($this->context->auth->id() ?? 0, (array) ($payload['ids'] ?? []));
        $this->json(true, $this->i18n->t('messages.notifications_read', ['count'=>$count]), ['updated' => $count]);
    }

    public function integrations(): never
    {
        $this->context->access->assert('calendar.settings.manage');
        $this->json(true, $this->i18n->t('messages.integrations_loaded'), ['integrations' => $this->integrations->catalog(true)]);
    }

    public function saveIntegration(string $slug): never
    {
        $this->context->access->assert('calendar.settings.manage');
        $payload = $this->payload(); $this->csrf($payload);
        try { $this->json(true, $this->i18n->t('messages.integration_saved'), ['integration' => $this->integrations->save($slug, $payload)]); }
        catch (Throwable $error) { $this->failure($error); }
    }

    public function deliveries(): never
    {
        $this->context->access->assert('calendar.settings.manage');
        $this->json(true, $this->i18n->t('messages.delivery_loaded'), ['deliveries'=>$this->calendar->deliveryStatus()]);
    }

    public function retrySafeDeliveries(): never
    {
        $this->context->access->assert('calendar.settings.manage');
        $payload=$this->payload();$this->csrf($payload);
        $this->json(true, $this->i18n->t('messages.delivery_retried'), $this->calendar->retrySafeDeliveries($this->context->auth->id()??0));
    }

    private function options(?array $scope): array
    {
        $options = $this->calendar->options($scope); $channels = ['internal'];
        $options['timezones'] = \DateTimeZone::listIdentifiers();
        if (filter_var($this->context->config['mail']['from_address'] ?? '', FILTER_VALIDATE_EMAIL)) $channels[] = 'email';
        try { if ((new \App\Core\WebPushSubscriptions($this->context->db,$this->context->config,$this->context->root))->available()) $channels[]='browser'; } catch (\Throwable) {}
        $notifications=new \App\Core\NotificationChannels($this->context->db,$this->context->root,(string)$this->context->config['secrets_key']);
        foreach ($notifications->catalog() as $integration) {
            if (!$integration['enabled'] || !$integration['verified_at']) continue;
            $channel = ['telegram-notifications'=>'telegram','whatsapp-notifications'=>'whatsapp'][$integration['slug']] ?? null;
            if ($channel) $channels[] = $channel;
        }
        $options['channels'] = $channels;
        return $options;
    }

    private function payload(): array
    {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        return is_array($payload) ? $payload : $_POST;
    }

    private function csrf(array $payload): void
    {
        if (!is_string($payload['csrf'] ?? null) || !$this->context->auth->verifyCsrf($payload['csrf'])) $this->json(false, 'Your session token is invalid. Refresh and try again.', null, 419);
    }

    private function failure(Throwable $error): never
    {
        if ($error instanceof \PDOException || !$error instanceof RuntimeException) {
            error_log('Calendar operation failed: ' . get_class($error));
            $this->json(false, 'Calendar could not complete the operation. Please try again.', null, 500);
        }
        $status = in_array($error->getCode(), [403, 404, 409, 422], true) ? $error->getCode() : 422;
        $this->json(false, $this->i18n->message($error->getMessage()), null, $status);
    }

    private function json(bool $ok, string $message, mixed $data = null, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}
