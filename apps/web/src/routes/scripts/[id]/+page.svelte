<script lang="ts">
	import { onDestroy, onMount } from 'svelte';
	import { page } from '$app/stores';
	import { ApiError } from '$lib/api';
	import { registry } from '$lib/registry';
	import { formatTimestamp } from '$lib/format';
	import type { Script } from '$lib/types';

	let script = $state<Script | null>(null);
	let loading = $state(true);
	let error = $state<string | null>(null);
	let copied = $state(false);

	const scriptId = $derived(Number($page.params.id));

	// Polling interval handle so we can cancel on unmount or terminal status.
	let pollTimer: ReturnType<typeof setTimeout> | null = null;

	async function refresh(): Promise<void> {
		try {
			const result = await registry.getScript(scriptId);
			script = result.data;
		} catch (e) {
			error =
				e instanceof ApiError && e.status === 404
					? 'Script not found.'
					: e instanceof ApiError && e.status === 401
						? 'Sign in to view this script.'
						: e instanceof Error
							? e.message
							: 'Failed to load the script.';
		} finally {
			loading = false;
		}
	}

	function schedulePoll(): void {
		if (!script || !script.is_pending) {
			return;
		}
		pollTimer = setTimeout(async () => {
			await refresh();
			schedulePoll();
		}, 2000);
	}

	onMount(async () => {
		await refresh();
		schedulePoll();
	});

	onDestroy(() => {
		if (pollTimer !== null) clearTimeout(pollTimer);
	});

	async function copyToClipboard(): Promise<void> {
		if (!script?.content) return;
		try {
			await navigator.clipboard.writeText(script.content);
			copied = true;
			setTimeout(() => (copied = false), 1500);
		} catch {
			// Clipboard write can fail in some browsers — silently ignore.
		}
	}

	function statusColor(status: Script['status']): string {
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

<svelte:head><title>{script?.title ?? 'Script'} · BmsSiteOps</title></svelte:head>

<div class="space-y-6">
	{#if loading}
		<p style="color: var(--color-text-muted);">Loading script…</p>
	{:else if error || !script}
		<div
			class="rounded-lg border p-4 text-sm"
			style="border-color: var(--color-border-subtle); color: var(--color-text-secondary);"
		>
			{error ?? 'Script not found.'}
		</div>
	{:else}
		<header>
			<a href="/scripts" class="text-sm" style="color: var(--color-text-muted);">← Scripts</a>
			<div class="mt-1 flex items-baseline justify-between gap-4">
				<h1 class="text-2xl font-semibold tracking-tight">{script.title}</h1>
				<span style="color: {statusColor(script.status)}; font-weight: 500;">
					{script.status_label}
				</span>
			</div>
			<p class="mt-1 text-sm" style="color: var(--color-text-secondary);">
				{script.language_label}
				{#if script.requested_at}
					· requested {formatTimestamp(script.requested_at)}
				{/if}
				{#if script.model}
					· {script.model}
				{/if}
			</p>
		</header>

		<!-- Prompt -->
		<section
			class="rounded-lg border p-4"
			style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
		>
			<h2 class="mb-2 text-sm font-medium">Prompt</h2>
			<p class="text-sm whitespace-pre-line" style="color: var(--color-text-secondary);">
				{script.prompt}
			</p>
		</section>

		<!-- Pending state: spinner + helpful copy -->
		{#if script.is_pending}
			<section
				class="rounded-lg border p-4"
				style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
			>
				<div class="flex items-center gap-3">
					<span
						class="inline-block h-3 w-3 animate-pulse rounded-full"
						style="background: var(--color-info, #2563eb);"
					></span>
					<p class="text-sm" style="color: var(--color-text-secondary);">
						{script.status === 'requested'
							? 'Waiting for the worker to pick this up…'
							: 'Generating…'}
					</p>
				</div>
				<p class="mt-2 text-xs" style="color: var(--color-text-muted);">
					This page refreshes itself every few seconds. Feel free to navigate away — your script
					will keep generating in the background.
				</p>
			</section>
		{/if}

		<!-- Failed state -->
		{#if script.status === 'failed'}
			<section
				class="rounded-lg border p-4"
				style="background: var(--color-surface-1); border-color: var(--color-danger, #dc2626);"
			>
				<h2 class="mb-2 text-sm font-medium" style="color: var(--color-danger, #dc2626);">
					Generation failed
				</h2>
				<p
					class="font-mono text-sm whitespace-pre-line"
					style="color: var(--color-text-secondary);"
				>
					{script.error}
				</p>
			</section>
		{/if}

		<!-- Result -->
		{#if script.status === 'ready' && script.content}
			<section
				class="rounded-lg border"
				style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
			>
				<div
					class="flex items-center justify-between border-b px-4 py-2"
					style="border-color: var(--color-border-subtle);"
				>
					<h2 class="text-sm font-medium">Generated code</h2>
					<button
						type="button"
						onclick={copyToClipboard}
						class="rounded px-2 py-1 text-xs"
						style="background: var(--color-surface-2); color: var(--color-text-secondary);"
					>
						{copied ? 'Copied' : 'Copy'}
					</button>
				</div>
				<pre
					class="overflow-x-auto p-4 text-sm leading-relaxed"
					style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace;"><code
						>{script.content}</code
					></pre>
			</section>
		{/if}
	{/if}
</div>
