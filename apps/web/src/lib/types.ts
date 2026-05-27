/**
 * Domain types mirroring the Laravel API's serialized models.
 *
 * Keep these in lockstep with apps/api/app/Models/* and the JSON resources.
 * When adding a field to a Laravel model, add it here too — there is no
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
	tenant_id: number;
	slug: string;
	name: string;
	address: string | null;
	timezone: string | null;
	metadata: Record<string, unknown>;
	created_at: string;
	updated_at: string;
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
