<?php

declare(strict_types=1);

namespace App\Services\Triage;

use App\Enums\TriageAction;
use App\Enums\TriageStatus;
use App\Models\Device;
use App\Models\Event;
use App\Models\TriageRule;
use Throwable;

/**
 * Executes the action prescribed by a matched triage rule.
 *
 * All Laravel-side actions are implemented here. The worker-executed
 * remediations (e.g. TRMM agent restart) live in the worker repo and are
 * dispatched separately — they are not part of this executor's responsibility.
 *
 * Every method returns [TriageStatus, ?array] so callers can persist the
 * outcome (status + structured result) into the decision row.
 */
class TriageActionExecutor
{
    /**
     * @return array{0: TriageStatus, 1: array<string, mixed>|null}
     */
    public function execute(TriageRule $rule, Event $event): array
    {
        try {
            return match ($rule->action) {
                TriageAction::MuteDevice => $this->muteDevice($event, $rule->action_params ?? []),
                TriageAction::MarkForReview => [TriageStatus::Executed, null],
                TriageAction::Ignore => [TriageStatus::Skipped, null],
            };
        } catch (Throwable $e) {
            return [TriageStatus::Failed, ['error' => $e->getMessage()]];
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{0: TriageStatus, 1: array<string, mixed>}
     */
    private function muteDevice(Event $event, array $params): array
    {
        $device = Device::find($event->device_id);

        if ($device === null) {
            return [TriageStatus::Failed, ['error' => 'device not found', 'device_id' => $event->device_id]];
        }

        // params.duration_minutes (int) -> timed mute; absent -> indefinite.
        $minutes = isset($params['duration_minutes']) && is_numeric($params['duration_minutes'])
            ? max(1, (int) $params['duration_minutes'])
            : null;

        $until = $minutes !== null ? now()->addMinutes($minutes) : null;

        $device->update([
            'is_muted' => true,
            'muted_until' => $until,
        ]);

        return [TriageStatus::Executed, [
            'muted_device_id' => $device->id,
            'muted_until' => $until?->toIso8601String(),
        ]];
    }
}
