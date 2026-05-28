/**
 * Pure presentation helpers for registry entities.
 *
 * Map enum values to display labels and design-token-based colors. Kept pure
 * (no DOM, no stores) so they are trivially unit-testable and reusable across
 * components.
 */

import type { DeviceStatus, EventSeverity, SourceStatus, TimelineBucket } from '$lib/types';

/** CSS custom-property name carrying the semantic color for a UI state. */
export type StatusColorVar =
	| 'var(--color-status-ok)'
	| 'var(--color-status-warn)'
	| 'var(--color-status-err)'
	| 'var(--color-status-info)'
	| 'var(--color-text-muted)';

export function deviceStatusColor(status: DeviceStatus): StatusColorVar {
	switch (status) {
		case 'online':
			return 'var(--color-status-ok)';
		case 'offline':
			return 'var(--color-status-err)';
		default:
			return 'var(--color-text-muted)';
	}
}

export function deviceStatusLabel(status: DeviceStatus): string {
	switch (status) {
		case 'online':
			return 'Online';
		case 'offline':
			return 'Offline';
		default:
			return 'Unknown';
	}
}

export function sourceStatusColor(status: SourceStatus): StatusColorVar {
	switch (status) {
		case 'ok':
			return 'var(--color-status-ok)';
		case 'error':
			return 'var(--color-status-err)';
		default:
			return 'var(--color-text-muted)';
	}
}

export function severityColor(severity: EventSeverity | null): StatusColorVar {
	switch (severity) {
		case 'critical':
			return 'var(--color-status-err)';
		case 'warning':
			return 'var(--color-status-warn)';
		case 'info':
			return 'var(--color-status-info)';
		default:
			return 'var(--color-text-muted)';
	}
}

/** Sortable weight for a severity — higher is more severe. */
export function severityWeight(severity: EventSeverity | null): number {
	switch (severity) {
		case 'critical':
			return 3;
		case 'warning':
			return 2;
		case 'info':
			return 1;
		default:
			return 0;
	}
}

/** Format an ISO timestamp as a compact, locale-stable relative-ish label. */
export function formatTimestamp(iso: string | null): string {
	if (!iso) return '—';
	const d = new Date(iso);
	if (Number.isNaN(d.getTime())) return '—';
	return d.toISOString().slice(0, 16).replace('T', ' ') + ' UTC';
}

/** Largest bucket total in a timeline, for scaling bar heights (min 1). */
export function timelineMax(buckets: TimelineBucket[]): number {
	return Math.max(1, ...buckets.map((b) => b.total));
}
