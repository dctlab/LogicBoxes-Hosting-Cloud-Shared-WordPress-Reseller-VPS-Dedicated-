<?php

declare(strict_types=1);

namespace LbHosting\Service;

use Illuminate\Database\Capsule\Manager as Capsule;
use LbHosting\Catalog\ProductFamily;
use LbHosting\Support\Logger;
use LbHosting\Support\Settings;

/**
 * Items the LogicBoxes API cannot provision are sold as normal configurable
 * options but fulfilled by a person:
 *   - Cloud Hosting dedicated IP (KB 2652: "IP addresses cannot be provisioned
 *     using the API calls")
 *   - Dedicated Server bandwidth (no bandwidth add-on in the API)
 *
 * Each requested change becomes a mod_lbh_manual_tasks row, a WHMCS To-Do item
 * and (optionally) a support ticket for the client, so nobody pretends it was
 * automated. The admin closes the task on the addon's "Manual Tasks" page.
 *
 * WHMCS contracts used:
 *  - tbltodolist (date, title, description, admin, status, duedate) - core
 *    table behind Utilities > To-Do List (PATTERN: no localAPI exists for it).
 *  - localAPI('OpenTicket', deptid, clientid, subject, message, priority,
 *    serviceid) (VERIFIED API command; serviceid links the ticket to the service).
 */
final class ManualFulfilment
{
    public const ITEMS = [
        'dedicated_ip' => 'Dedicated IP address',
        'bandwidth' => 'Bandwidth',
    ];

    /**
     * @return string 'created' | 'exists' | 'unchanged'
     */
    public function request(int $serviceId, string $family, string $item, string $target, ?string $previous, array $context): string
    {
        $target = mb_substr(trim($target), 0, 64);
        $previous = $previous === null ? null : mb_substr(trim($previous), 0, 64);
        if ($previous !== null && strcasecmp($target, $previous) === 0) {
            return 'unchanged';
        }
        // A fresh order asking for "none" needs nothing.
        if ($previous === null && in_array(strtolower($target), ['', '0', 'none'], true)) {
            return 'unchanged';
        }

        $now = date('Y-m-d H:i:s');
        $open = Capsule::table('mod_lbh_manual_tasks')->where('service_id', $serviceId)->where('item', $item)->where('status', 'open')->first();
        if ($open) {
            if (strcasecmp((string) $open->target, $target) === 0) {
                return 'exists';
            }
            Capsule::table('mod_lbh_manual_tasks')->where('id', $open->id)->update([
                'status' => 'cancelled', 'note' => 'Superseded by a newer request (' . $target . ')', 'updated_at' => $now,
            ]);
            if ($open->todo_id) {
                $this->closeTodo((int) $open->todo_id);
            }
        }

        $label = self::ITEMS[$item] ?? $item;
        $product = ProductFamily::label($family);
        $domain = (string) ($context['domain'] ?? '');
        $orderId = $context['order_id'] ?? null;
        $title = sprintf('%s: %s -> %s for %s', $product, $label, $target === '' ? 'none' : $target, $domain !== '' ? $domain : 'service #' . $serviceId);
        $desc = implode("\n", [
            'The LogicBoxes API cannot provision this change - please do it by hand.',
            'Service: #' . $serviceId . ' (' . $domain . ')',
            'LogicBoxes order: ' . ($orderId ? '#' . $orderId : 'not created yet'),
            'Item: ' . $label,
            'Was: ' . ($previous === null || $previous === '' ? 'none' : $previous),
            'Requested: ' . ($target === '' ? 'none' : $target),
            'When done: Addons > LogicBoxes Hosting > Manual Tasks > Mark done.',
        ]);

        $existing = Capsule::table('mod_lbh_manual_tasks')->where('service_id', $serviceId)->where('item', $item)->where('target', $target)->first();
        $row = [
            'previous' => $previous,
            'status' => 'open',
            'note' => null,
            'updated_at' => $now,
        ];
        if ($existing) {
            Capsule::table('mod_lbh_manual_tasks')->where('id', $existing->id)->update($row);
            $taskId = (int) $existing->id;
        } else {
            $taskId = (int) Capsule::table('mod_lbh_manual_tasks')->insertGetId($row + [
                'service_id' => $serviceId, 'item' => $item, 'target' => $target, 'created_at' => $now,
            ]);
        }

        $todoId = null;
        $ticketId = null;
        if (Settings::bool('manual_todo')) {
            try {
                $todoId = (int) Capsule::table('tbltodolist')->insertGetId([
                    'date' => date('Y-m-d'),
                    'title' => mb_substr($title, 0, 250),
                    'description' => $desc,
                    'admin' => 0,
                    'status' => 'Pending',
                    'duedate' => date('Y-m-d', time() + 86400),
                ]);
            } catch (\Throwable $e) {
                Logger::log('manual', 'Could not create To-Do item: ' . $e->getMessage(), 'warning', $serviceId);
            }
        }
        $clientId = (int) ($context['client_id'] ?? 0);
        if (Settings::bool('manual_ticket') && $clientId > 0 && function_exists('localAPI')) {
            try {
                $res = localAPI('OpenTicket', [
                    'deptid' => $this->departmentId(),
                    'clientid' => $clientId,
                    'serviceid' => $serviceId,
                    'subject' => mb_substr($product . ' - ' . $label . ' request (' . ($domain !== '' ? $domain : '#' . $serviceId) . ')', 0, 200),
                    'message' => sprintf(
                        "Thank you - we have received your request to change the %s of your %s (%s) to: %s.\n\nThis change is completed by our team, and we will update this ticket as soon as it is done.",
                        strtolower($label),
                        $product,
                        $domain !== '' ? $domain : 'service #' . $serviceId,
                        $target === '' ? 'none' : $target
                    ),
                    'priority' => 'Medium',
                ]);
                if (is_array($res) && ($res['result'] ?? '') === 'success') {
                    $ticketId = (int) ($res['id'] ?? $res['ticketid'] ?? 0) ?: null;
                } else {
                    Logger::log('manual', 'OpenTicket failed: ' . (is_array($res) ? ($res['message'] ?? 'unknown error') : 'no response'), 'warning', $serviceId);
                }
            } catch (\Throwable $e) {
                Logger::log('manual', 'OpenTicket failed: ' . $e->getMessage(), 'warning', $serviceId);
            }
        }
        Capsule::table('mod_lbh_manual_tasks')->where('id', $taskId)->update(['todo_id' => $todoId, 'ticket_id' => $ticketId]);
        Logger::log('manual', 'Manual task #' . $taskId . ': ' . $title, 'warning', $serviceId);

        return 'created';
    }

    public function complete(int $taskId, string $status = 'done', string $note = ''): void
    {
        $task = Capsule::table('mod_lbh_manual_tasks')->where('id', $taskId)->first();
        if (!$task) {
            throw new \RuntimeException('Manual task not found.');
        }
        Capsule::table('mod_lbh_manual_tasks')->where('id', $taskId)->update([
            'status' => $status === 'cancelled' ? 'cancelled' : 'done',
            'note' => $note !== '' ? mb_substr($note, 0, 1000) : $task->note,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($task->todo_id) {
            $this->closeTodo((int) $task->todo_id);
        }
        Logger::log('manual', 'Manual task #' . $taskId . ' marked ' . $status, 'info', (int) $task->service_id);
    }

    /** @return object[] */
    public static function openFor(int $serviceId): array
    {
        return Capsule::table('mod_lbh_manual_tasks')->where('service_id', $serviceId)->where('status', 'open')->orderBy('id')->get()->all();
    }

    private function closeTodo(int $todoId): void
    {
        try {
            Capsule::table('tbltodolist')->where('id', $todoId)->update(['status' => 'Completed']);
        } catch (\Throwable $e) {
        }
    }

    private function departmentId(): int
    {
        $id = (int) Settings::get('manual_ticket_dept');
        if ($id > 0) {
            return $id;
        }
        try {
            return (int) (Capsule::table('tblticketdepartments')->orderBy('order')->value('id') ?? 1);
        } catch (\Throwable $e) {
            return 1;
        }
    }
}
