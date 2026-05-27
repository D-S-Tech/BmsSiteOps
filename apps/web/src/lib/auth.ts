/**
 * Bearer-token authentication state.
 *
 * The Laravel API issues a Sanctum personal-access token on successful login.
 * We hold the token in memory + localStorage on the client; on the server side
 * (SSR), session state is reconstructed from the cookie set by Laravel during
 * login.
 *
 * This module deliberately avoids global stores or runes — it's a small set
 * of pure functions consumed by $lib/api and a small Svelte rune in the
 * layout. Layered up that way, the auth state is testable without mounting
 * a component.
 */

import { browser } from '$app/environment';
import type { User } from '$lib/types';

const TOKEN_KEY = 'bmssiteops.token';
const USER_KEY = 'bmssiteops.user';

let memoryToken: string | null = null;
let memoryUser: User | null = null;

export function getToken(): string | null {
	if (memoryToken !== null) return memoryToken;
	if (browser) {
		const stored = window.localStorage.getItem(TOKEN_KEY);
		memoryToken = stored;
		return stored;
	}
	return null;
}

export function setToken(token: string | null): void {
	memoryToken = token;
	if (browser) {
		if (token === null) {
			window.localStorage.removeItem(TOKEN_KEY);
		} else {
			window.localStorage.setItem(TOKEN_KEY, token);
		}
	}
}

export function getUser(): User | null {
	if (memoryUser !== null) return memoryUser;
	if (browser) {
		const raw = window.localStorage.getItem(USER_KEY);
		if (raw === null) return null;
		try {
			memoryUser = JSON.parse(raw) as User;
			return memoryUser;
		} catch {
			return null;
		}
	}
	return null;
}

export function setUser(user: User | null): void {
	memoryUser = user;
	if (browser) {
		if (user === null) {
			window.localStorage.removeItem(USER_KEY);
		} else {
			window.localStorage.setItem(USER_KEY, JSON.stringify(user));
		}
	}
}

export function isAuthenticated(): boolean {
	return getToken() !== null;
}

export function logout(): void {
	setToken(null);
	setUser(null);
}
