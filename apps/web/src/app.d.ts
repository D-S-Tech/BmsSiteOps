// See https://svelte.dev/docs/kit/types#app.d.ts

import type { Tenant, User } from '$lib/types';

declare global {
	namespace App {
		interface Error {
			message: string;
			code?: string;
		}

		interface Locals {
			user: User | null;
			tenant: Tenant | null;
		}

		interface PageData {
			user: User | null;
			tenant: Tenant | null;
		}

		// interface PageState {}
		// interface Platform {}
	}
}

export {};
