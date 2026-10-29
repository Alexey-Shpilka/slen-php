<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Webhooks\Enums;

/** Every `type` a webhook endpoint can subscribe to. */
enum WebhookEventType: string
{
    case ReservationCreated = 'reservation.created';
    case ReservationCaptured = 'reservation.captured';
    case ReservationReleased = 'reservation.released';
    case ReservationExpired = 'reservation.expired';
    case ReservationOverrun = 'reservation.overrun';
    case BudgetThresholdReached = 'budget.threshold_reached';
    case BudgetExceeded = 'budget.exceeded';
    case OperationAuthorized = 'operation.authorized';
    case OperationDenied = 'operation.denied';
    case OperationStarted = 'operation.started';
    case OperationCompleted = 'operation.completed';
    case OperationFailed = 'operation.failed';
    case OperationUncertain = 'operation.uncertain';
    case FlowPublished = 'flow.published';
    case FlowRolledBack = 'flow.rolled_back';
    case ConnectionCooldownStarted = 'connection.cooldown_started';
    case ConnectionHealthChanged = 'connection.health_changed';
    case ApprovalRequested = 'approval.requested';
    case ApprovalDecided = 'approval.decided';
    case ExecutionFinished = 'execution.finished';
    case AlertOpened = 'alert.opened';
    case AlertResolved = 'alert.resolved';
    case OverrunDecided = 'overrun.decided';
    case CustomerBalanceLow = 'customer.balance_low';
    case LedgerDegraded = 'ledger.degraded';
    case LedgerRestored = 'ledger.restored';
}
