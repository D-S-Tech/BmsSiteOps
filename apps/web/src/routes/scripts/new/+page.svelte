<script lang="ts">
	import { goto } from '$app/navigation';
	import { ApiError } from '$lib/api';
	import { registry } from '$lib/registry';

	// Static list — same enum values as App\Enums\ScriptLanguage on the server.
	const languages: { value: string; label: string }[] = [
		{ value: 'python', label: 'Python' },
		{ value: 'javascript', label: 'JavaScript' },
		{ value: 'typescript', label: 'TypeScript' },
		{ value: 'shell', label: 'Shell (bash)' },
		{ value: 'esphome_yaml', label: 'ESPHome YAML' },
		{ value: 'nodered_flow', label: 'Node-RED flow' },
		{ value: 'bacnet_config', label: 'BACnet config' },
		{ value: 'niagara_program', label: 'Niagara program' },
		{ value: 'generic', label: 'Generic' }
	];

	let title = $state('');
	let prompt = $state('');
	let language = $state('python');
	let submitting = $state(false);
	let error = $state<string | null>(null);

	async function handleSubmit() {
		if (submitting) return;
		submitting = true;
		error = null;
		try {
			const result = await registry.createScript({ title, prompt, language });
			// Hop straight to the detail page — it polls until ready.
			await goto(`/scripts/${result.data.id}`);
		} catch (e) {
			error =
				e instanceof ApiError && e.status === 422
					? 'Please check the fields below — at least one looks invalid.'
					: e instanceof ApiError && e.status === 401
						? 'Sign in to request a script.'
						: e instanceof Error
							? e.message
							: 'Failed to submit the script request.';
			submitting = false;
		}
	}
</script>

<svelte:head><title>New script · BmsSiteOps</title></svelte:head>

<div class="mx-auto max-w-3xl space-y-6">
	<header>
		<a href="/scripts" class="text-sm" style="color: var(--color-text-muted);">← Scripts</a>
		<h1 class="mt-1 text-2xl font-semibold tracking-tight">New script request</h1>
		<p class="mt-1 text-sm" style="color: var(--color-text-secondary);">
			The worker picks the request up, generates the code via the AI model, and pushes the result
			back. You'll be redirected to the detail page which polls until it's ready.
		</p>
	</header>

	{#if error}
		<div
			class="rounded-lg border p-4 text-sm"
			style="border-color: var(--color-danger, #dc2626); color: var(--color-danger, #dc2626);"
		>
			{error}
		</div>
	{/if}

	<form
		class="space-y-4"
		onsubmit={(e) => {
			e.preventDefault();
			void handleSubmit();
		}}
	>
		<div>
			<label for="title" class="block text-sm font-medium">Title</label>
			<input
				id="title"
				type="text"
				bind:value={title}
				required
				maxlength="200"
				placeholder="List online TRMM agents"
				class="mt-1 block w-full rounded-md border px-3 py-2 text-sm"
				style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
			/>
			<p class="mt-1 text-xs" style="color: var(--color-text-muted);">
				A short label for your records.
			</p>
		</div>

		<div>
			<label for="language" class="block text-sm font-medium">Language / format</label>
			<select
				id="language"
				bind:value={language}
				class="mt-1 block w-full rounded-md border px-3 py-2 text-sm"
				style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
			>
				{#each languages as lang (lang.value)}
					<option value={lang.value}>{lang.label}</option>
				{/each}
			</select>
		</div>

		<div>
			<label for="prompt" class="block text-sm font-medium">Prompt</label>
			<textarea
				id="prompt"
				bind:value={prompt}
				required
				rows="8"
				maxlength="5000"
				placeholder="Write a Python snippet that uses the TRMM REST API to list all online agents and prints their hostnames."
				class="mt-1 block w-full rounded-md border px-3 py-2 font-mono text-sm"
				style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
			></textarea>
			<p class="mt-1 text-xs" style="color: var(--color-text-muted);">
				Describe what the script should do. The more specific, the better — name the libraries you
				want, target versions, expected inputs/outputs.
			</p>
		</div>

		<div class="flex items-center justify-end gap-2">
			<a
				href="/scripts"
				class="rounded-md border px-3 py-1.5 text-sm"
				style="border-color: var(--color-border-subtle); color: var(--color-text-secondary);"
			>
				Cancel
			</a>
			<button
				type="submit"
				disabled={submitting || !title || !prompt}
				class="rounded-md px-3 py-1.5 text-sm font-medium disabled:opacity-50"
				style="background: var(--color-bmce-red); color: white;"
			>
				{submitting ? 'Submitting…' : 'Submit request'}
			</button>
		</div>
	</form>
</div>
