/**
 * Unit tests for the pure presentation helpers in $lib/format.
 */

import { describe, expect, test } from 'vitest';
import {
	deviceStatusColor,
	deviceStatusLabel,
	formatTimestamp,
	severityColor,
	severityWeight,
	sourceStatusColor,
	timelineMax
} from './format';
import type { TimelineBucket } from './types';

describe('deviceStatusColor', () => {
	test('online is ok-colored', () => {
		expect(deviceStatusColor('online')).toBe('var(--color-status-ok)');
	});
	test('offline is error-colored', () => {
		expect(deviceStatusColor('offline')).toBe('var(--color-status-err)');
	});
	test('unknown is muted', () => {
		expect(deviceStatusColor('unknown')).toBe('var(--color-text-muted)');
	});
});

describe('deviceStatusLabel', () => {
	test.each([
		['online', 'Online'],
		['offline', 'Offline'],
		['unknown', 'Unknown']
	] as const)('%s -> %s', (status, label) => {
		expect(deviceStatusLabel(status)).toBe(label);
	});
});

describe('sourceStatusColor', () => {
	test('ok / error / never', () => {
		expect(sourceStatusColor('ok')).toBe('var(--color-status-ok)');
		expect(sourceStatusColor('error')).toBe('var(--color-status-err)');
		expect(sourceStatusColor('never')).toBe('var(--color-text-muted)');
	});
});

describe('severityColor', () => {
	test('critical / warning / info / null', () => {
		expect(severityColor('critical')).toBe('var(--color-status-err)');
		expect(severityColor('warning')).toBe('var(--color-status-warn)');
		expect(severityColor('info')).toBe('var(--color-status-info)');
		expect(severityColor(null)).toBe('var(--color-text-muted)');
	});
});

describe('severityWeight', () => {
	test('orders critical > warning > info > null', () => {
		expect(severityWeight('critical')).toBeGreaterThan(severityWeight('warning'));
		expect(severityWeight('warning')).toBeGreaterThan(severityWeight('info'));
		expect(severityWeight('info')).toBeGreaterThan(severityWeight(null));
	});
});

describe('formatTimestamp', () => {
	test('null -> em dash', () => {
		expect(formatTimestamp(null)).toBe('—');
	});
	test('invalid -> em dash', () => {
		expect(formatTimestamp('not-a-date')).toBe('—');
	});
	test('valid ISO -> compact UTC', () => {
		expect(formatTimestamp('2026-05-27T12:34:56Z')).toBe('2026-05-27 12:34 UTC');
	});
});

describe('timelineMax', () => {
	const bucket = (total: number): TimelineBucket => ({
		t: '2026-05-28T00:00:00Z',
		critical: 0,
		warning: 0,
		info: 0,
		none: 0,
		total
	});

	test('returns the largest total', () => {
		expect(timelineMax([bucket(3), bucket(7), bucket(1)])).toBe(7);
	});

	test('never returns less than 1 (avoids divide-by-zero)', () => {
		expect(timelineMax([])).toBe(1);
		expect(timelineMax([bucket(0), bucket(0)])).toBe(1);
	});
});
