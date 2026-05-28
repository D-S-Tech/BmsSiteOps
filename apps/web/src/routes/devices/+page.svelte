<script lang="ts">
	import { onMount } from 'svelte';
	import { ApiError } from '$lib/api';
	import { registry } from '$lib/registry';
	import { deviceStatusColor, deviceStatusLabel, formatTimestamp } from '$lib/format';
	import type { Device, DeviceStatus } from '$lib/types';

	let devices = $state<Device[]>([]);
	let loading = $state(true);
	let error = $state<string | null>(null);
	let statusFilter = $state<'' | DeviceStatus>('');

	async function load() {
		loading = true;
		error = null;
		try {
			const result = await registry.listDevices({
				status: statusFilter || undefined,
				per_page: 100
			});
			devices = result.data;
		} catch (e) {
			error =
				e instanceof ApiError && e.status === 401
					? 'Sign in to view devices.'
					: e instanceof Error
						? e.message
						: 'Failed to load devices.';
		} finally {
			loading = false;
		}
	}

	onMount(load);

	function setFilter(value: '' | DeviceStatus) {
		statusFilter = value;
		load();
	}
</script>

<svelte:head><title>Devices · BmsSiteOps</title></svelte:head>

<div class="space-y-6">
	<header class="flex items-center justify-between">
		<h1 class="text-2xl font-semibold tracking-tight">Devices</h1>
		<div class="flex gap-1 text-sm">
			{#each [['', 'All'], ['online', 'Online'], ['offline', 'Offline'], ['unknown', 'Unknown']] as [value, label] (value)}
				<button
					onclick={() => setFilter(value as '' | DeviceStatus)}
					class="rounded-md px-3 py-1"
					style={statusFilter === value
						? 'background: var(--color-surface-3); color: var(--color-text-primary);'
						: 'color: var(--color-text-secondary);'}
				>
					{label}
				</button>
			{/each}
		</div>
	</header>

	{#if loading}
		<p style="color: var(--color-text-muted);">Loading devices…</p>
	{:else if error}
		<div
			class="rounded-lg border p-4 text-sm"
			style="border-color: var(--color-border-subtle); color: var(--color-text-secondary);"
		>
			{error}
		</div>
	{:else if devices.length === 0}
		<p style="color: var(--color-text-muted);">No devices match this filter.</p>
	{:else}
		<div
			class="overflow-hidden rounded-lg border"
			style="border-color: var(--color-border-subtle);"
		>
			<table class="w-full text-sm">
				<thead>
					<tr style="background: var(--color-surface-2);">
						<th class="px-4 py-2 text-left font-medium">Name</th>
						<th class="px-4 py-2 text-left font-medium">Type</th>
						<th class="px-4 py-2 text-left font-medium">Status</th>
						<th class="px-4 py-2 text-left font-medium">Last seen</th>
					</tr>
				</thead>
				<tbody>
					{#each devices as device (device.id)}
						<tr style="border-top: 1px solid var(--color-border-subtle);">
							<td class="px-4 py-2 font-medium">{device.name}</td>
							<td class="px-4 py-2" style="color: var(--color-text-secondary);">
								{device.type ?? '—'}
							</td>
							<td class="px-4 py-2">
								<span class="inline-flex items-center gap-1.5">
									<span
										class="inline-block h-2 w-2 rounded-full"
										style={`background: ${deviceStatusColor(device.status)};`}
									></span>
									{deviceStatusLabel(device.status)}
								</span>
							</td>
							<td class="px-4 py-2" style="color: var(--color-text-muted);">
								{formatTimestamp(device.last_seen_at)}
							</td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>
	{/if}
</div>
