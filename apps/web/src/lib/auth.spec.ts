/**
 * Unit tests for $lib/auth — pure token / user storage helpers.
 *
 * These tests don't touch the network or the Svelte runtime. They mock
 * localStorage and verify the in-memory + persistence behavior.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';

// Mock $app/environment to behave as a browser for these tests.
vi.mock('$app/environment', () => ({ browser: true }));

// Provide a fresh in-memory localStorage between tests.
const storage = new Map<string, string>();
vi.stubGlobal('window', {
	localStorage: {
		getItem: (k: string) => storage.get(k) ?? null,
		setItem: (k: string, v: string) => storage.set(k, v),
		removeItem: (k: string) => storage.delete(k)
	}
});

import { getToken, getUser, isAuthenticated, logout, setToken, setUser } from './auth';

beforeEach(() => {
	storage.clear();
	// Clear the in-memory cache between tests by setting to null.
	setToken(null);
	setUser(null);
});

afterEach(() => {
	storage.clear();
});

describe('token storage', () => {
	test('returns null when no token set', () => {
		expect(getToken()).toBeNull();
	});

	test('persists token to localStorage', () => {
		setToken('abc.def.ghi');
		expect(getToken()).toBe('abc.def.ghi');
		expect(storage.get('bmssiteops.token')).toBe('abc.def.ghi');
	});

	test('clearing token removes from localStorage', () => {
		setToken('abc.def.ghi');
		setToken(null);
		expect(getToken()).toBeNull();
		expect(storage.has('bmssiteops.token')).toBe(false);
	});
});

describe('user storage', () => {
	test('returns null when no user set', () => {
		expect(getUser()).toBeNull();
	});

	test('persists user as JSON', () => {
		setUser({
			id: 1,
			name: 'Test',
			email: 'test@example.com',
			current_tenant_id: 1,
			is_super_admin: false,
			email_verified_at: null,
			created_at: '2026-01-01T00:00:00Z',
			updated_at: '2026-01-01T00:00:00Z'
		});

		const stored = getUser();
		expect(stored?.email).toBe('test@example.com');
		expect(stored?.current_tenant_id).toBe(1);
	});
});

describe('isAuthenticated', () => {
	test('false when no token', () => {
		expect(isAuthenticated()).toBe(false);
	});

	test('true when token set', () => {
		setToken('whatever');
		expect(isAuthenticated()).toBe(true);
	});
});

describe('logout', () => {
	test('clears both token and user', () => {
		setToken('abc');
		setUser({
			id: 1,
			name: 'X',
			email: 'x@y.com',
			current_tenant_id: null,
			is_super_admin: false,
			email_verified_at: null,
			created_at: '',
			updated_at: ''
		});
		logout();
		expect(getToken()).toBeNull();
		expect(getUser()).toBeNull();
	});
});
