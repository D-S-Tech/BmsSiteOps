<script lang="ts">
	import { onMount } from 'svelte';
	import { page } from '$app/stores';
	import { ApiError } from '$lib/api';
	import { registry } from '$lib/registry';
	import { formatTimestamp, severityColor, timelineMax } from '$lib/format';
	import type { SiteBrief, SiteSummary, SiteTimeline } from '$lib/types';

	let summary = $state<SiteSummary | null>(null);
	let timeline = $state<SiteTimeline | null>(null);
	let brief = $state<SiteBrief | null>(null);
	let loading = $state(true);
	let error = $state<string | null>(null);

	const siteId = $derived(Number($page.params.id));

	onMount(async () => {
		try {
			[summary, timeline] = await Promise.all([
				registry.getSiteSummary(siteId),
				registry.getSiteTimeline(siteId, 24)
			]);

			// The latest brief is optional — a site may not have one generated yet.
			try {
				brief = (await registry.getLatestBrief(siteId)).data;
			} catch (e) {
				if (!(e instanceof ApiError && e.status === 404)) throw e;
			}
		} catch (e) {
			error =
				e instanceof ApiError && e.status === 401
					? 'Sign in to view this site.'
					: e instanceof ApiError && e.status === 404
						? 'Site not found.'
						: e instanceof Error
							? e.message
							: 'Failed to load site dashboard.';
		} finally {
			loading = false;
		}
	});

	const maxTotal = $derived(timeline ? timelineMax(timeline.buckets) : 1);
</script>

<svelte:head><title>{summary?.site.name ?? 'Site'} · BmsSiteOps</title></svelte:head>

<div class="space-y-6">
	{#if loading}
		<p style="color: var(--color-text-muted);">Loading site dashboard…</p>
	{:else if error}
		<div
			class="rounded-lg border p-4 text-sm"
			style="border-color: var(--color-border-subtle); color: var(--color-text-secondary);"
		>
			{error}
		</div>
	{:else if summary}
		<header class="flex items-center justify-between">
			<div>
				<a href="/sites" class="text-sm" style="color: var(--color-text-muted);">← Sites</a>
				<h1 class="text-2xl font-semibold tracking-tight">{summary.site.name}</h1>
				{#if summary.site.address}
					<p class="text-sm" style="color: var(--color-text-secondary);">{summary.site.address}</p>
				{/if}
			</div>
		</header>

		<!-- AI Site Brief -->
		{#if brief}
			<section
				class="rounded-lg border p-4"
				style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
			>
				<div class="mb-2 flex items-center justify-between">
					<h2 class="text-sm font-medium">AI Site Brief</h2>
					<span class="text-xs" style="color: var(--color-text-muted);">
						{brief.model} · {formatTimestamp(brief.generated_at)}
					</span>
				</div>
				<p
					class="text-sm leading-relaxed whitespace-pre-line"
					style="color: var(--color-text-secondary);"
				>
					{brief.summary}
				</p>
			</section>
		{/if}

		<!-- Stat cards -->
		<div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
			<div
				class="rounded-lg border p-4"
				style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
			>
				<div class="text-xs tracking-wide uppercase" style="color: var(--color-text-muted);">
					Devices
				</div>
				<div class="mt-1 text-2xl font-semibold">{summary.devices.total}</div>
				<div class="mt-2 flex gap-3 text-xs" style="color: var(--color-text-secondary);">
					<span style="color: var(--color-status-ok);">{summary.devices.online} online</span>
					<span style="color: var(--color-status-err);">{summary.devices.offline} offline</span>
					<span>{summary.devices.unknown} unknown</span>
				</div>
			</div>

			<div
				class="rounded-lg border p-4"
				style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
			>
				<div class="text-xs tracking-wide uppercase" style="color: var(--color-text-muted);">
					Sources
				</div>
				<div class="mt-1 text-2xl font-semibold">{summary.sources.total}</div>
				<div class="mt-2 flex gap-3 text-xs" style="color: var(--color-text-secondary);">
					<span style="color: var(--color-status-ok);">{summary.sources.ok} ok</span>
					<span style="color: var(--color-status-err);">{summary.sources.error} error</span>
					<span>{summary.sources.never} never</span>
				</div>
			</div>

			<div
				class="rounded-lg border p-4"
				style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
			>
				<div class="text-xs tracking-wide uppercase" style="color: var(--color-text-muted);">
					Events (24h)
				</div>
				<div class="mt-1 text-2xl font-semibold">{summary.events_24h.total}</div>
				<div class="mt-2 flex gap-3 text-xs" style="color: var(--color-text-secondary);">
					<span style="color: var(--color-status-err);">{summary.events_24h.critical} crit</span>
					<span style="color: var(--color-status-warn);">{summary.events_24h.warning} warn</span>
					<span style="color: var(--color-status-info);">{summary.events_24h.info} info</span>
				</div>
			</div>
		</div>

		<!-- Timeline (severity-stacked hourly bars) -->
		{#if timeline}
			<section
				class="rounded-lg border p-4"
				style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
			>
				<h2 class="mb-3 text-sm font-medium">Event timeline (last 24h)</h2>
				<div class="flex h-32 items-end gap-0.5">
					{#each timeline.buckets as b (b.t)}
						<div
							class="flex flex-1 flex-col-reverse"
							title={`${b.t}: ${b.total} events`}
							style={`height: ${(b.total / maxTotal) * 100}%; min-height: 1px;`}
						>
							<div style={`flex: ${b.info + b.none}; background: var(--color-status-info);`}></div>
							<div style={`flex: ${b.warning}; background: var(--color-status-warn);`}></div>
							<div style={`flex: ${b.critical}; background: var(--color-status-err);`}></div>
						</div>
					{/each}
				</div>
			</section>
		{/if}

		<!-- Recent actionable events -->
		<section>
			<h2 class="mb-3 text-sm font-medium">Recent critical &amp; warning events</h2>
			{#if summary.recent_events.length === 0}
				<p style="color: var(--color-text-muted);">No recent critical or warning events.</p>
			{:else}
				<div
					class="overflow-hidden rounded-lg border"
					style="border-color: var(--color-border-subtle);"
				>
					<table class="w-full text-sm">
						<tbody>
							{#each summary.recent_events as event (event.id)}
								<tr style="border-top: 1px solid var(--color-border-subtle);">
									<td class="px-4 py-2">
										<span
											class="inline-block h-2 w-2 rounded-full"
											style={`background: ${severityColor(event.severity)};`}
										></span>
									</td>
									<td class="px-4 py-2 font-medium">{event.metric}</td>
									<td class="px-4 py-2" style="color: var(--color-text-secondary);">
										{event.value ?? '—'}
									</td>
									<td class="px-4 py-2 text-right" style="color: var(--color-text-muted);">
										{formatTimestamp(event.occurred_at)}
									</td>
								</tr>
							{/each}
						</tbody>
					</table>
				</div>
			{/if}
		</section>
	{/if}
</div>
