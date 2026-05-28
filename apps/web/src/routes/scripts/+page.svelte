<script lang="ts">
	import { onMount } from 'svelte';
	import { ApiError } from '$lib/api';
	import { registry } from '$lib/registry';
	import { formatTimestamp } from '$lib/format';
	import type { Script } from '$lib/types';

	let scripts = $state<Script[]>([]);
	let loading = $state(true);
	let error = $state<string | null>(null);

	onMount(async () => {
		try {
			const result = await registry.listScripts({ per_page: 50 });
			scripts = result.data;
		} catch (e) {
			error =
				e instanceof ApiError && e.status === 401
					? 'Sign in to view scripts.'
					: e instanceof Error
						? e.message
						: 'Failed to load scripts.';
		} finally {
			loading = false;
		}
	});

	function statusBadgeColor(status: Script['status']): string {
		switch (status) {
			case 'ready':
				return 'var(--color-success, #16a34a)';
			case 'failed':
				return 'var(--color-danger, #dc2626)';
			case 'generating':
				return 'var(--color-info, #2563eb)';
			default:
				return 'var(--color-text-muted)';
		}
	}
</script>

<svelte:head><title>Scripts · BmsSiteOps</title></svelte:head>

<div class="space-y-6">
	<header class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-semibold tracking-tight">AI Scripts</h1>
			<p class="text-sm" style="color: var(--color-text-secondary);">
				Operator-requested code generation — Python, ESPHome, BACnet, and more.
			</p>
		</div>
		<a
			href="/scripts/new"
			class="rounded-md border px-3 py-1.5 text-sm font-medium"
			style="background: var(--color-bmce-red); color: white; border-color: var(--color-bmce-red);"
		>
			New script
		</a>
	</header>

	{#if loading}
		<p style="color: var(--color-text-muted);">Loading scripts…</p>
	{:else if error}
		<div
			class="rounded-lg border p-4 text-sm"
			style="border-color: var(--color-border-subtle); color: var(--color-text-secondary);"
		>
			{error}
		</div>
	{:else if scripts.length === 0}
		<div
			class="rounded-lg border p-6 text-center text-sm"
			style="border-color: var(--color-border-subtle); color: var(--color-text-secondary);"
		>
			<p>No scripts yet.</p>
			<p class="mt-1" style="color: var(--color-text-muted);">
				Click <em>New script</em> to request your first one.
			</p>
		</div>
	{:else}
		<div
			class="overflow-hidden rounded-lg border"
			style="border-color: var(--color-border-subtle); background: var(--color-surface-1);"
		>
			<table class="w-full text-sm">
				<thead
					class="border-b text-left text-xs tracking-wide uppercase"
					style="background: var(--color-surface-2); border-color: var(--color-border-subtle); color: var(--color-text-muted);"
				>
					<tr>
						<th class="px-4 py-2 font-medium">Requested</th>
						<th class="px-4 py-2 font-medium">Title</th>
						<th class="px-4 py-2 font-medium">Language</th>
						<th class="px-4 py-2 font-medium">Status</th>
					</tr>
				</thead>
				<tbody>
					{#each scripts as script (script.id)}
						<tr class="border-t" style="border-color: var(--color-border-subtle);">
							<td
								class="px-4 py-2 align-top whitespace-nowrap"
								style="color: var(--color-text-muted);"
							>
								{formatTimestamp(script.requested_at)}
							</td>
							<td class="px-4 py-2">
								<a href="/scripts/{script.id}" class="font-medium hover:underline">
									{script.title}
								</a>
							</td>
							<td class="px-4 py-2 align-top">
								<span
									class="rounded px-1.5 py-0.5 text-xs"
									style="background: var(--color-surface-2); color: var(--color-text-secondary);"
								>
									{script.language_label}
								</span>
							</td>
							<td class="px-4 py-2 align-top">
								<span style="color: {statusBadgeColor(script.status)}; font-weight: 500;">
									{script.status_label}
								</span>
							</td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>
	{/if}
</div>
