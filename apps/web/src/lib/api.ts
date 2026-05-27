/**
 * Typed API client for the BmsSiteOps Laravel backend.
 *
 * Usage:
 *
 *   import { api, ApiError } from '$lib/api';
 *
 *   const sites = await api.get<Paginated<Site>>('/sites');
 *
 *   try {
 *     await api.post('/sites', { slug, name, address });
 *   } catch (e) {
 *     if (e instanceof ApiError) console.error(e.status, e.body.message);
 *     throw e;
 *   }
 *
 * Authentication: the client picks up a bearer token from $lib/auth (set on
 * login). The token is sent as `Authorization: Bearer <token>` on every
 * request. Requests carrying a valid token automatically scope to the user's
 * current tenant — that resolution happens server-side via TenantScope.
 */

import { browser } from '$app/environment';
import { PUBLIC_API_BASE_URL } from '$env/static/public';
import { getToken } from '$lib/auth';
import type { ApiErrorBody } from '$lib/types';

const BASE_URL = (PUBLIC_API_BASE_URL ?? '').replace(/\/$/, '');

if (!BASE_URL && browser) {
	console.warn('PUBLIC_API_BASE_URL is not configured; API requests will fail.');
}

export class ApiError extends Error {
	constructor(
		readonly status: number,
		readonly body: ApiErrorBody,
		readonly url: string
	) {
		super(`API ${status} ${url}: ${body.message}`);
		this.name = 'ApiError';
	}
}

type Json = Record<string, unknown> | unknown[] | string | number | boolean | null;

interface RequestOptions {
	signal?: AbortSignal;
	headers?: Record<string, string>;
}

async function request<T>(
	method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
	path: string,
	body?: Json,
	options: RequestOptions = {}
): Promise<T> {
	const url = `${BASE_URL}/api/v1${path.startsWith('/') ? path : `/${path}`}`;
	const token = getToken();

	const headers: Record<string, string> = {
		Accept: 'application/json',
		...options.headers
	};

	if (body !== undefined) {
		headers['Content-Type'] = 'application/json';
	}

	if (token) {
		headers.Authorization = `Bearer ${token}`;
	}

	const response = await fetch(url, {
		method,
		headers,
		body: body !== undefined ? JSON.stringify(body) : undefined,
		signal: options.signal,
		credentials: 'omit'
	});

	if (response.status === 204) {
		return undefined as T;
	}

	const text = await response.text();
	const parsed = text.length > 0 ? (JSON.parse(text) as unknown) : null;

	if (!response.ok) {
		throw new ApiError(
			response.status,
			(parsed as ApiErrorBody) ?? { message: response.statusText },
			url
		);
	}

	return parsed as T;
}

export const api = {
	get: <T>(path: string, options?: RequestOptions) => request<T>('GET', path, undefined, options),
	post: <T>(path: string, body?: Json, options?: RequestOptions) =>
		request<T>('POST', path, body, options),
	put: <T>(path: string, body?: Json, options?: RequestOptions) =>
		request<T>('PUT', path, body, options),
	patch: <T>(path: string, body?: Json, options?: RequestOptions) =>
		request<T>('PATCH', path, body, options),
	delete: <T>(path: string, options?: RequestOptions) =>
		request<T>('DELETE', path, undefined, options)
};
