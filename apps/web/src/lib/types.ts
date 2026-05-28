/**
 * Domain types mirroring the Laravel API's serialized models.
 *
 * Keep these in lockstep with apps/api/app/Http/Resources/* and the Eloquent
 * models. When adding a field to a resource, add it here too — there is no
 * code generation step (yet).
 */

export interface Tenant {
	id: number;
	slug: string;
	name: string;
	is_active: boolean;
	created_at: string;
	updated_at: string;
}

export interface User {
	id: number;
	name: string;
	email: string;
	current_tenant_id: number | null;
	is_super_admin: boolean;
	email_verified_at: string | null;
	created_at: string;
	updated_at: string;
}

export interface Site {
	id: number;
	slug: string;
	name: string;
	address: string | null;
	timezone: string | null;
	metadata: Record<string, unknown>;
	sources_count?: number;
	devices_count?: number;
	created_at: string;
	updated_at: string;
}

export type SourceKind = 'trmm' | 'niagara' | 'bacnet';
export type SourceStatus = 'never' | 'ok' | 'error';
export type DeviceStatus = 'online' | 'offline' | 'unknown';
export type EventSeverity = 'info' | 'warning' | 'critical';

export interface Source {
	id: number;
	site_id: number;
	kind: SourceKind;
	kind_label: string;
	name: string;
	base_url: string | null;
	/** The public API never returns the secret itself — only whether one is set. */
	has_credentials: boolean;
	poll_interval_seconds: number;
	is_active: boolean;
	last_status: SourceStatus;
	last_polled_at: string | null;
	last_error: string | null;
	metadata: Record<string, unknown>;
	site?: Site;
	devices_count?: number;
	created_at: string;
	updated_at: string;
}

export interface Device {
	id: number;
	source_id: number;
	site_id: number;
	external_id: string;
	name: string;
	type: string | null;
	status: DeviceStatus;
	status_label: string;
	last_seen_at: string | null;
	metadata: Record<string, unknown>;
	source?: Source;
	site?: Site;
	events_count?: number;
	created_at: string;
	updated_at: string;
}

export interface Event {
	id: number;
	device_id: number;
	source_id: number;
	site_id: number;
	kind: SourceKind;
	metric: string;
	value: string | null;
	severity: EventSeverity | null;
	occurred_at: string;
	metadata: Record<string, unknown>;
	device?: Device;
	created_at: string;
}

/**
 * Envelope returned by /api/v1/auth/login on success.
 */
export interface AuthLoginResponse {
	token: string;
	user: User;
	tenants: Tenant[];
}

/**
 * Standard error body for /api/v1/* responses.
 *
 * The shape matches Laravel's default validation/exception responses.
 * The thrown error class itself is `ApiError` in $lib/api — this interface
 * is the body shape it carries.
 */
export interface ApiErrorBody {
	message: string;
	errors?: Record<string, string[]>;
}

export interface Paginated<T> {
	data: T[];
	meta: {
		current_page: number;
		from: number | null;
		last_page: number;
		per_page: number;
		to: number | null;
		total: number;
	};
	links: {
		first: string | null;
		last: string | null;
		prev: string | null;
		next: string | null;
	};
}
