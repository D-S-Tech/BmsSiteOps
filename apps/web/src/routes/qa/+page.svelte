<script lang="ts">
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { ApiError } from '$lib/api';
	import { registry } from '$lib/registry';
	import { formatTimestamp } from '$lib/format';
	import type { Question } from '$lib/types';

	let questions = $state<Question[]>([]);
	let loading = $state(true);
	let listError = $state<string | null>(null);

	// Inline ask form
	let questionText = $state('');
	let asking = $state(false);
	let askError = $state<string | null>(null);

	onMount(async () => {
		try {
			const result = await registry.listQuestions({ per_page: 50 });
			questions = result.data;
		} catch (e) {
			listError =
				e instanceof ApiError && e.status === 401
					? 'Sign in to view Q&A.'
					: e instanceof Error
						? e.message
						: 'Failed to load Q&A history.';
		} finally {
			loading = false;
		}
	});

	async function handleAsk() {
		if (asking) return;
		const text = questionText.trim();
		if (text.length < 3) {
			askError = 'Question must be at least 3 characters.';
			return;
		}
		asking = true;
		askError = null;
		try {
			const result = await registry.askQuestion({ question: text });
			// Navigate to the detail page — the answer is already there because
			// the platform processes Q&A synchronously.
			await goto(`/qa/${result.data.id}`);
		} catch (e) {
			askError =
				e instanceof ApiError && e.status === 401
					? 'Sign in to ask a question.'
					: e instanceof ApiError && e.status === 422
						? 'Please check the question — it failed validation.'
						: e instanceof Error
							? e.message
							: 'Failed to submit the question.';
			asking = false;
		}
	}

	function statusColor(status: Question['status']): string {
		switch (status) {
			case 'ready':
				return 'var(--color-success, #16a34a)';
			case 'failed':
				return 'var(--color-danger, #dc2626)';
			default:
				return 'var(--color-text-muted)';
		}
	}
</script>

<svelte:head><title>Site Q&amp;A · BmsSiteOps</title></svelte:head>

<div class="space-y-8">
	<header>
		<h1 class="text-2xl font-semibold tracking-tight">Site Q&amp;A</h1>
		<p class="mt-1 text-sm" style="color: var(--color-text-secondary);">
			Ask the platform anything about your sites. Answers are grounded in your uploaded documents
			and the AI Site Briefs — every claim is cited.
		</p>
	</header>

	<!-- Ask form -->
	<section
		class="rounded-lg border p-4"
		style="background: var(--color-surface-1); border-color: var(--color-border-subtle);"
	>
		{#if askError}
			<p
				class="mb-3 rounded border p-2 text-sm"
				style="border-color: var(--color-danger, #dc2626); color: var(--color-danger, #dc2626);"
			>
				{askError}
			</p>
		{/if}

		<form
			onsubmit={(e) => {
				e.preventDefault();
				void handleAsk();
			}}
			class="space-y-3"
		>
			<label for="qa-question" class="block text-sm font-medium">Your question</label>
			<textarea
				id="qa-question"
				bind:value={questionText}
				rows="3"
				maxlength="5000"
				placeholder="When does AHU-1 at 80 Pine St start in heating mode?"
				class="block w-full rounded-md border px-3 py-2 text-sm"
				style="background: var(--color-surface-2); border-color: var(--color-border-subtle);"
			></textarea>
			<div class="flex justify-end">
				<button
					type="submit"
					disabled={asking || questionText.trim().length < 3}
					class="rounded-md px-4 py-1.5 text-sm font-medium disabled:opacity-50"
					style="background: var(--color-bmce-red); color: white;"
				>
					{asking ? 'Thinking… (up to ~15s)' : 'Ask'}
				</button>
			</div>
		</form>
	</section>

	<!-- History -->
	<section class="space-y-3">
		<h2 class="text-lg font-medium">Recent questions</h2>

		{#if loading}
			<p style="color: var(--color-text-muted);">Loading…</p>
		{:else if listError}
			<div
				class="rounded-lg border p-4 text-sm"
				style="border-color: var(--color-border-subtle); color: var(--color-text-secondary);"
			>
				{listError}
			</div>
		{:else if questions.length === 0}
			<div
				class="rounded-lg border p-6 text-center text-sm"
				style="border-color: var(--color-border-subtle); color: var(--color-text-secondary);"
			>
				<p>No questions yet. Ask something above to get started.</p>
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
							<th class="px-4 py-2 font-medium">Asked</th>
							<th class="px-4 py-2 font-medium">Question</th>
							<th class="px-4 py-2 font-medium">Status</th>
						</tr>
					</thead>
					<tbody>
						{#each questions as q (q.id)}
							<tr class="border-t" style="border-color: var(--color-border-subtle);">
								<td
									class="px-4 py-2 align-top whitespace-nowrap"
									style="color: var(--color-text-muted);"
								>
									{formatTimestamp(q.asked_at)}
								</td>
								<td class="px-4 py-2">
									<a href="/qa/{q.id}" class="font-medium hover:underline">
										{q.question}
									</a>
								</td>
								<td class="px-4 py-2 align-top">
									<span style="color: {statusColor(q.status)}; font-weight: 500;">
										{q.status_label}
									</span>
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
		{/if}
	</section>
</div>
