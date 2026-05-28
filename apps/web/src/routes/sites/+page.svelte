<script lang="ts">
	import { onMount } from 'svelte';
	import { ApiError } from '$lib/api';
	import { registry } from '$lib/registry';
	import type { Site } from '$lib/types';

	let sites = $state<Site[]>([]);
	let loading = $state(true);
	let error = $state<string | null>(null);

	onMount(async () => {
		try {
			const result = await registry.listSites({ per_page: 100 });
			sites = result.data;
		} catch (e) {
			error =
				e instanceof ApiError && e.status === 401
					? 'Sign in to view sites.'
					: e instanceof Error
						? e.message
						: 'Failed to load sites.';
		} finally {
			loading = false;
		}
	});
</script>

<svelte:head><title>Sites · BmsSiteOps</title></svelte:head>

<div class="space-y-6">
	<header class="flex items-center justify-between">
		<h1 class="text-2xl font-semibold tracking-tight">Sites</h1>
	</header>

	{#if loading}
		<p style="color: var(--color-text-muted);">Loading sites…</p>
	{:else if error}
		<div
			class="rounded-lg border p-4 text-sm"
			style="border-color: var(--color-border-subtle); color: var(--color-text-secondary);"
		>
			{error}
		</div>
	{:else if sites.length === 0}
		<p style="color: var(--color-text-muted);">No sites yet.</p>
	{:else}
		<div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
			{#each sites as site (site.id)}
				<a
					href={`/sites/${site.id}`}
					class="rounded-lg border p-4 transition-colors"
					style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
				>
					<div class="font-medium">{site.name}</div>
					{#if site.address}
						<div class="mt-1 text-sm" style="color: var(--color-text-secondary);">
							{site.address}
						</div>
					{/if}
					<div class="mt-3 flex gap-4 text-xs" style="color: var(--color-text-muted);">
						<span>{site.sources_count ?? 0} sources</span>
						<span>{site.devices_count ?? 0} devices</span>
					</div>
				</a>
			{/each}
		</div>
	{/if}
</div>
