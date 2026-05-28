/**
 * Typed wrappers around the /api/v1 registry endpoints.
 *
 * Thin convenience layer over $lib/api so pages don't repeat path strings and
 * generics. Filtering options map 1:1 to the controller query parameters.
 */

import { api } from '$lib/api';
import type { Device, Event, Paginated, Site, SiteSummary, SiteTimeline, Source } from '$lib/types';

function query(params: Record<string, string | number | boolean | undefined>): string {
	const usp = new URLSearchParams();
	for (const [key, value] of Object.entries(params)) {
		if (value !== undefined && value !== '') {
			usp.set(key, String(value));
		}
	}
	const s = usp.toString();
	return s ? `?${s}` : '';
}

export const registry = {
	listSites: (opts: { per_page?: number } = {}) => api.get<Paginated<Site>>(`/sites${query(opts)}`),

	getSite: (id: number) => api.get<{ data: Site }>(`/sites/${id}`),

	getSiteSummary: (id: number) => api.get<SiteSummary>(`/sites/${id}/summary`),

	getSiteTimeline: (id: number, hours = 24) =>
		api.get<SiteTimeline>(`/sites/${id}/timeline?hours=${hours}`),

	listSources: (
		opts: { site_id?: number; kind?: string; is_active?: boolean; per_page?: number } = {}
	) => api.get<Paginated<Source>>(`/sources${query(opts)}`),

	getSource: (id: number) => api.get<{ data: Source }>(`/sources/${id}`),

	listDevices: (
		opts: {
			site_id?: number;
			source_id?: number;
			status?: string;
			search?: string;
			per_page?: number;
		} = {}
	) => api.get<Paginated<Device>>(`/devices${query(opts)}`),

	getDevice: (id: number) => api.get<{ data: Device }>(`/devices/${id}`),

	listEvents: (
		opts: {
			site_id?: number;
			device_id?: number;
			source_id?: number;
			severity?: string;
			metric?: string;
			since?: string;
			until?: string;
			per_page?: number;
		} = {}
	) => api.get<Paginated<Event>>(`/events${query(opts)}`)
};
